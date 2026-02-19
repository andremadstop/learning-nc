<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Db\Question;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Db\Answer;
use OCA\Learning\Db\AnswerMapper;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\PoolShareMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IRequest;

class ImportController extends Controller {
    private QuestionMapper $questionMapper;
    private AnswerMapper $answerMapper;
    private PoolMapper $poolMapper;
    private PoolShareMapper $shareMapper;
    private IDBConnection $db;
    private ?string $userId;

    public function __construct(
        string $appName,
        IRequest $request,
        QuestionMapper $questionMapper,
        AnswerMapper $answerMapper,
        PoolMapper $poolMapper,
        PoolShareMapper $shareMapper,
        IDBConnection $db,
        ?string $userId
    ) {
        parent::__construct($appName, $request);
        $this->questionMapper = $questionMapper;
        $this->answerMapper = $answerMapper;
        $this->poolMapper = $poolMapper;
        $this->shareMapper = $shareMapper;
        $this->db = $db;
        $this->userId = $userId;
    }

    private function canEditPool(int $poolId): bool {
        try {
            $this->poolMapper->find($poolId, $this->userId);
            return true;
        } catch (DoesNotExistException $e) {
            $share = $this->shareMapper->findByPoolAndUser($poolId, $this->userId);
            return $share !== null && $share->getPermission() === 'edit';
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 5, period: 60)]
    public function importCsv(int $poolId, string $csvData): DataResponse {
        if (!$this->canEditPool($poolId)) {
            return new DataResponse(['error' => 'No edit access to this pool'], Http::STATUS_FORBIDDEN);
        }

        // S5: Enforce max body size (2 MB) before processing
        if (strlen($csvData) > 2 * 1024 * 1024) {
            return new DataResponse(['error' => 'CSV data too large (max 2 MB)'], Http::STATUS_BAD_REQUEST);
        }

        $lines = array_filter(array_map('trim', explode("\n", $csvData)));
        if (empty($lines)) {
            return new DataResponse(['error' => 'No data to import'], Http::STATUS_BAD_REQUEST);
        }

        if (count($lines) > 500) {
            return new DataResponse(['error' => 'Maximum 500 items per import'], Http::STATUS_BAD_REQUEST);
        }

        // Check if first line is a header
        $firstLine = strtolower($lines[0]);
        if (strpos($firstLine, 'question') !== false && strpos($firstLine, 'answer') !== false) {
            array_shift($lines);
        }

        $imported = 0;
        $errors = [];

        $this->db->beginTransaction();
        try {
            foreach ($lines as $lineNum => $line) {
                $fields = str_getcsv($line);

                // Minimum: question + 2 answers + correct index
                if (count($fields) < 4) {
                    $errors[] = 'Line ' . ($lineNum + 1) . ': Not enough fields (need: question, answers, correct number)';
                    continue;
                }

                $questionText = trim($fields[0]);
                if (empty($questionText)) {
                    $errors[] = 'Line ' . ($lineNum + 1) . ': Empty question text';
                    continue;
                }

                if (mb_strlen($questionText) > 5000) {
                    $errors[] = 'Line ' . ($lineNum + 1) . ': Question text too long (max 5000)';
                    continue;
                }

                $answerTexts = [];
                $correctIndex = null;
                $explanation = null;

                $lastIdx = count($fields) - 1;
                $secondLastIdx = $lastIdx - 1;

                if ($lastIdx >= 2) {
                    $possibleCorrect = trim($fields[$lastIdx]);
                    $answerCount = $lastIdx - 1; // fields between question and last field

                    if (is_numeric($possibleCorrect) && (int)$possibleCorrect >= 1 && (int)$possibleCorrect <= 8 && (int)$possibleCorrect <= $answerCount) {
                        $correctIndex = (int)$possibleCorrect - 1;
                        $answerTexts = array_slice($fields, 1, $lastIdx - 1);
                    } else if ($secondLastIdx >= 2) {
                        $possibleCorrect2 = trim($fields[$secondLastIdx]);
                        $answerCount2 = $secondLastIdx - 1;
                        if (is_numeric($possibleCorrect2) && (int)$possibleCorrect2 >= 1 && (int)$possibleCorrect2 <= 8 && (int)$possibleCorrect2 <= $answerCount2) {
                            $correctIndex = (int)$possibleCorrect2 - 1;
                            $answerTexts = array_slice($fields, 1, $secondLastIdx - 1);
                            $explanation = trim($fields[$lastIdx]);
                            if (empty($explanation)) $explanation = null;
                        } else {
                            $answerTexts = array_slice($fields, 1, $lastIdx);
                            $correctText = strtolower(trim($possibleCorrect));
                            foreach ($answerTexts as $i => $at) {
                                if (strtolower(trim($at)) === $correctText) {
                                    $correctIndex = $i;
                                    break;
                                }
                            }
                            if ($correctIndex !== null) {
                                array_pop($answerTexts);
                            }
                        }
                    }
                }

                $answerTexts = array_map('trim', $answerTexts);
                $answerTexts = array_filter($answerTexts, function($t) { return $t !== ''; });
                $answerTexts = array_values($answerTexts);

                if (count($answerTexts) < 2) {
                    $errors[] = 'Line ' . ($lineNum + 1) . ': Need at least 2 answers';
                    continue;
                }

                if (count($answerTexts) > 8) {
                    $errors[] = 'Line ' . ($lineNum + 1) . ': Maximum 8 answers';
                    continue;
                }

                if ($correctIndex === null || $correctIndex >= count($answerTexts)) {
                    $errors[] = 'Line ' . ($lineNum + 1) . ': Could not determine correct answer — use a number (1-N) to indicate which answer is correct';
                    continue;
                }

                // Determine question type based on correct index
                $csvQuestionType = 'single';
                $correctIndices = [$correctIndex];

                $question = new Question();
                $question->setPoolId($poolId);
                $question->setUserId($this->userId);
                $question->setText($questionText);
                $question->setExplanation($explanation);
                $question->setQuestionType($csvQuestionType);
                $question = $this->questionMapper->createOrUpdate($question);

                foreach ($answerTexts as $i => $answerText) {
                    if (mb_strlen($answerText) > 2000) {
                        $answerText = mb_substr($answerText, 0, 2000);
                    }
                    $answer = new Answer();
                    $answer->setQuestionId($question->getId());
                    $answer->setText($answerText);
                    $answer->setIsCorrect(in_array($i, $correctIndices));
                    $answer->setPosition($i);
                    $this->answerMapper->createOrUpdate($answer);
                }

                $imported++;
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            \OCP\Server::get(\Psr\Log\LoggerInterface::class)->error('CSV import failed: ' . get_class($e) . ': ' . $e->getMessage());
            return new DataResponse(['error' => 'Import failed due to a server error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new DataResponse([
            'imported' => $imported,
            'errors' => $errors,
            'total_lines' => count($lines)
        ], $imported > 0 ? Http::STATUS_CREATED : Http::STATUS_BAD_REQUEST);
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 5, period: 60)]
    public function importJson(int $poolId, string $jsonData): DataResponse {
        if (!$this->canEditPool($poolId)) {
            return new DataResponse(['error' => 'No edit access to this pool'], Http::STATUS_FORBIDDEN);
        }

        // S5: Enforce max body size (2 MB) before processing
        if (strlen($jsonData) > 2 * 1024 * 1024) {
            return new DataResponse(['error' => 'JSON data too large (max 2 MB)'], Http::STATUS_BAD_REQUEST);
        }

        $data = json_decode($jsonData, true);
        if (!is_array($data)) {
            return new DataResponse(['error' => 'Invalid JSON — check for missing brackets, commas, or quotes'], Http::STATUS_BAD_REQUEST);
        }

        // If wrapped in {"questions": [...]}
        if (isset($data['questions']) && is_array($data['questions'])) {
            $data = $data['questions'];
        }

        if (count($data) > 500) {
            return new DataResponse(['error' => 'Maximum 500 items per import'], Http::STATUS_BAD_REQUEST);
        }

        $imported = 0;
        $errors = [];

        $this->db->beginTransaction();
        try {
            foreach ($data as $idx => $item) {
                $num = $idx + 1;

                if (!isset($item['text']) || empty(trim($item['text']))) {
                    $errors[] = "Item $num: Missing question text";
                    continue;
                }

                if (mb_strlen(trim($item['text'])) > 5000) {
                    $errors[] = "Item $num: Question text too long (max 5000)";
                    continue;
                }

                if (!isset($item['answers']) || !is_array($item['answers']) || count($item['answers']) < 2) {
                    $errors[] = "Item $num: Need at least 2 answers";
                    continue;
                }

                if (count($item['answers']) > 8) {
                    $errors[] = "Item $num: Maximum 8 answers";
                    continue;
                }

                $correctCount = 0;
                foreach ($item['answers'] as $a) {
                    if (filter_var($a['is_correct'] ?? false, FILTER_VALIDATE_BOOLEAN)) $correctCount++;
                }

                if ($correctCount === 0) {
                    $errors[] = "Item $num: No correct answer marked";
                    continue;
                }

                $questionType = $correctCount > 1 ? 'multi' : 'single';

                // FIX3-HI-1: Validate ALL answer texts BEFORE creating the question to prevent orphans
                $validatedAnswers = [];
                $hasInvalidAnswer = false;
                foreach ($item['answers'] as $i => $answerData) {
                    $answerText = trim($answerData['text'] ?? '');
                    if ($answerText === '') {
                        $errors[] = "Item $num: Empty answer text at position " . ($i + 1);
                        $hasInvalidAnswer = true;
                        break;
                    }
                    if (mb_strlen($answerText) > 2000) {
                        $answerText = mb_substr($answerText, 0, 2000);
                    }
                    $validatedAnswers[] = [
                        'text' => $answerText,
                        'is_correct' => filter_var($answerData['is_correct'] ?? false, FILTER_VALIDATE_BOOLEAN),
                        'position' => $i,
                    ];
                }
                if ($hasInvalidAnswer) {
                    continue;
                }

                $question = new Question();
                $question->setPoolId($poolId);
                $question->setUserId($this->userId);
                $question->setText(trim($item['text']));
                $question->setExplanation(isset($item['explanation']) ? trim($item['explanation']) : null);
                $question->setDifficulty(isset($item['difficulty']) ? trim($item['difficulty']) : null);
                $question->setQuestionType($questionType);
                $question = $this->questionMapper->createOrUpdate($question);

                foreach ($validatedAnswers as $va) {
                    $answer = new Answer();
                    $answer->setQuestionId($question->getId());
                    $answer->setText($va['text']);
                    $answer->setIsCorrect($va['is_correct']);
                    $answer->setPosition($va['position']);
                    $this->answerMapper->createOrUpdate($answer);
                }

                $imported++;
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            \OCP\Server::get(\Psr\Log\LoggerInterface::class)->error('JSON import failed: ' . get_class($e) . ': ' . $e->getMessage());
            return new DataResponse(['error' => 'Import failed due to a server error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new DataResponse([
            'imported' => $imported,
            'errors' => $errors,
            'total_items' => count($data)
        ], $imported > 0 ? Http::STATUS_CREATED : Http::STATUS_BAD_REQUEST);
    }
}
