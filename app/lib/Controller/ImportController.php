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
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;

class ImportController extends Controller {
    private QuestionMapper $questionMapper;
    private AnswerMapper $answerMapper;
    private PoolMapper $poolMapper;
    private PoolShareMapper $shareMapper;
    private ?string $userId;

    public function __construct(
        string $appName,
        IRequest $request,
        QuestionMapper $questionMapper,
        AnswerMapper $answerMapper,
        PoolMapper $poolMapper,
        PoolShareMapper $shareMapper,
        ?string $userId
    ) {
        parent::__construct($appName, $request);
        $this->questionMapper = $questionMapper;
        $this->answerMapper = $answerMapper;
        $this->poolMapper = $poolMapper;
        $this->shareMapper = $shareMapper;
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
    public function importCsv(int $poolId, string $csvData): DataResponse {
        if (!$this->canEditPool($poolId)) {
            return new DataResponse(['error' => 'No edit access to this pool'], Http::STATUS_FORBIDDEN);
        }

        $lines = array_filter(array_map('trim', explode("\n", $csvData)));
        if (empty($lines)) {
            return new DataResponse(['error' => 'No data to import'], Http::STATUS_BAD_REQUEST);
        }

        // Check if first line is a header
        $firstLine = strtolower($lines[0]);
        if (strpos($firstLine, 'question') !== false && strpos($firstLine, 'answer') !== false) {
            array_shift($lines);
        }

        $imported = 0;
        $errors = [];

        foreach ($lines as $lineNum => $line) {
            $fields = str_getcsv($line);
            
            // Minimum: question + 2 answers + correct index
            if (count($fields) < 4) {
                $errors[] = 'Line ' . ($lineNum + 1) . ': Not enough fields (need at least question, answer1, answer2, correct)';
                continue;
            }

            $questionText = trim($fields[0]);
            if (empty($questionText)) {
                $errors[] = 'Line ' . ($lineNum + 1) . ': Empty question text';
                continue;
            }

            // Detect format: last field might be explanation, second-to-last is correct index
            // Format A: question,a1,a2,a3,a4,correct_index,explanation
            // Format B: question,a1,a2,a3,a4,correct_index
            // Format C: question,a1,a2,correct_index
            $answerTexts = [];
            $correctIndex = null;
            $explanation = null;

            // Find the correct answer indicator (number 1-4 or answer text)
            $lastIdx = count($fields) - 1;
            $secondLastIdx = $lastIdx - 1;

            // Try to detect correct answer column
            // Check if second-to-last or last field is a small integer (1-based correct index)
            if ($lastIdx >= 2) {
                $possibleCorrect = trim($fields[$lastIdx]);
                $possibleExplanation = null;

                // If last field is a number 1-6, it's the correct index
                if (is_numeric($possibleCorrect) && (int)$possibleCorrect >= 1 && (int)$possibleCorrect <= 6) {
                    $correctIndex = (int)$possibleCorrect - 1; // Convert to 0-based
                    $answerTexts = array_slice($fields, 1, $lastIdx - 1);
                } else if ($secondLastIdx >= 2) {
                    // Last might be explanation, second-to-last is correct index
                    $possibleCorrect2 = trim($fields[$secondLastIdx]);
                    if (is_numeric($possibleCorrect2) && (int)$possibleCorrect2 >= 1 && (int)$possibleCorrect2 <= 6) {
                        $correctIndex = (int)$possibleCorrect2 - 1;
                        $answerTexts = array_slice($fields, 1, $secondLastIdx - 1);
                        $explanation = trim($fields[$lastIdx]);
                        if (empty($explanation)) $explanation = null;
                    } else {
                        // Assume last field is correct answer TEXT
                        $answerTexts = array_slice($fields, 1, $lastIdx);
                        $correctText = strtolower(trim($possibleCorrect));
                        foreach ($answerTexts as $i => $at) {
                            if (strtolower(trim($at)) === $correctText) {
                                $correctIndex = $i;
                                break;
                            }
                        }
                        // Remove the last "answer" which is the correct indicator
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

            if ($correctIndex === null || $correctIndex >= count($answerTexts)) {
                $errors[] = 'Line ' . ($lineNum + 1) . ': Could not determine correct answer';
                continue;
            }

            // Create question
            $question = new Question();
            $question->setPoolId($poolId);
            $question->setUserId($this->userId);
            $question->setText($questionText);
            $question->setExplanation($explanation);
            $question = $this->questionMapper->createOrUpdate($question);

            // Create answers
            foreach ($answerTexts as $i => $answerText) {
                $answer = new Answer();
                $answer->setQuestionId($question->getId());
                $answer->setText($answerText);
                $answer->setIsCorrect($i === $correctIndex);
                $answer->setPosition($i);
                $this->answerMapper->createOrUpdate($answer);
            }

            $imported++;
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
    public function importJson(int $poolId, string $jsonData): DataResponse {
        if (!$this->canEditPool($poolId)) {
            return new DataResponse(['error' => 'No edit access to this pool'], Http::STATUS_FORBIDDEN);
        }

        $data = json_decode($jsonData, true);
        if (!is_array($data)) {
            return new DataResponse(['error' => 'Invalid JSON data'], Http::STATUS_BAD_REQUEST);
        }

        // If wrapped in {"questions": [...]}
        if (isset($data['questions']) && is_array($data['questions'])) {
            $data = $data['questions'];
        }

        $imported = 0;
        $errors = [];

        foreach ($data as $idx => $item) {
            $num = $idx + 1;

            if (!isset($item['text']) || empty(trim($item['text']))) {
                $errors[] = "Item $num: Missing question text";
                continue;
            }

            if (!isset($item['answers']) || !is_array($item['answers']) || count($item['answers']) < 2) {
                $errors[] = "Item $num: Need at least 2 answers";
                continue;
            }

            $hasCorrect = false;
            foreach ($item['answers'] as $a) {
                if (!empty($a['is_correct'])) $hasCorrect = true;
            }

            if (!$hasCorrect) {
                $errors[] = "Item $num: No correct answer marked";
                continue;
            }

            $question = new Question();
            $question->setPoolId($poolId);
            $question->setUserId($this->userId);
            $question->setText(trim($item['text']));
            $question->setExplanation(isset($item['explanation']) ? trim($item['explanation']) : null);
            $question->setDifficulty(isset($item['difficulty']) ? trim($item['difficulty']) : null);
            $question = $this->questionMapper->createOrUpdate($question);

            foreach ($item['answers'] as $i => $answerData) {
                $answer = new Answer();
                $answer->setQuestionId($question->getId());
                $answer->setText(trim($answerData['text']));
                $answer->setIsCorrect(!empty($answerData['is_correct']));
                $answer->setPosition($i);
                $this->answerMapper->createOrUpdate($answer);
            }

            $imported++;
        }

        return new DataResponse([
            'imported' => $imported,
            'errors' => $errors,
            'total_items' => count($data)
        ], $imported > 0 ? Http::STATUS_CREATED : Http::STATUS_BAD_REQUEST);
    }
}
