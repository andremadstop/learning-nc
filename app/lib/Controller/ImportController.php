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
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;

class ImportController extends Controller {
    private QuestionMapper $questionMapper;
    private AnswerMapper $answerMapper;
    private PoolMapper $poolMapper;
    private PoolShareMapper $shareMapper;
    private IConfig $config;
    private IDBConnection $db;
    private ?string $userId;

    public function __construct(
        string $appName,
        IRequest $request,
        QuestionMapper $questionMapper,
        AnswerMapper $answerMapper,
        PoolMapper $poolMapper,
        PoolShareMapper $shareMapper,
        IConfig $config,
        IDBConnection $db,
        ?string $userId
    ) {
        parent::__construct($appName, $request);
        $this->questionMapper = $questionMapper;
        $this->answerMapper = $answerMapper;
        $this->poolMapper = $poolMapper;
        $this->shareMapper = $shareMapper;
        $this->config = $config;
        $this->db = $db;
        $this->userId = $userId;
    }

    private function getMaxImportBytes(): int {
        $maxMb = (int)$this->config->getAppValue('learning', 'max_import_size_mb', '2');
        $maxMb = max(1, min(10, $maxMb));
        return $maxMb * 1024 * 1024;
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
     * Strip UTF-8 BOM and normalize encoding.
     */
    private function normalizeText(string $text): string {
        // Strip UTF-8 BOM
        if (substr($text, 0, 3) === "\xEF\xBB\xBF") {
            $text = substr($text, 3);
        }
        // Try to fix Latin1/Windows-1252 → UTF-8
        if (!mb_check_encoding($text, 'UTF-8')) {
            $converted = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
            if ($converted !== false) {
                $text = $converted;
            }
        }
        return $text;
    }

    /**
     * Detect CSV delimiter (comma or semicolon).
     */
    private function detectDelimiter(string $firstLine): string {
        $semicolons = substr_count($firstLine, ';');
        $commas = substr_count($firstLine, ',');
        return $semicolons > $commas ? ';' : ',';
    }

    /**
     * Normalize a JSON question item from various formats to our canonical format.
     * Accepts: frage/question/text, antworten/answers, korrekt/correct/richtig/is_correct, erklaerung/explanation
     */
    private function normalizeJsonItem(array $item): ?array {
        // Question text: text > frage > question
        $text = trim($item['text'] ?? $item['frage'] ?? $item['question'] ?? '');
        if ($text === '') {
            return null;
        }

        // Explanation: explanation > erklaerung
        $explanation = $item['explanation'] ?? $item['erklaerung'] ?? null;
        if ($explanation !== null) {
            $explanation = trim($explanation);
            if ($explanation === '') {
                $explanation = null;
            }
        }

        // Difficulty
        $difficulty = $item['difficulty'] ?? $item['schwierigkeit'] ?? null;

        // Type: type > typ
        $type = $item['type'] ?? $item['typ'] ?? null;

        // Answers: answers > antworten
        $rawAnswers = $item['answers'] ?? $item['antworten'] ?? [];
        if (!is_array($rawAnswers)) {
            return null;
        }

        $answers = [];
        foreach ($rawAnswers as $a) {
            if (!is_array($a)) continue;
            $aText = trim($a['text'] ?? '');
            if ($aText === '') continue;

            // is_correct > korrekt > correct > richtig
            $isCorrect = $a['is_correct'] ?? $a['korrekt'] ?? $a['correct'] ?? $a['richtig'] ?? false;
            $isCorrect = filter_var($isCorrect, FILTER_VALIDATE_BOOLEAN);

            $answers[] = [
                'text' => $aText,
                'is_correct' => $isCorrect,
            ];
        }

        return [
            'text' => $text,
            'answers' => $answers,
            'explanation' => $explanation,
            'difficulty' => $difficulty,
            'type' => $type,
        ];
    }

    /**
     * Extract optional import metadata from JSON wrapper.
     */
    private function extractImportMeta(array $data): array {
        if (!isset($data['_meta']) || !is_array($data['_meta'])) {
            return [];
        }
        $meta = [];
        if (isset($data['_meta']['source'])) {
            $source = trim((string)$data['_meta']['source']);
            if ($source !== '') {
                $meta['source'] = mb_substr($source, 0, 255);
            }
        }
        if (isset($data['_meta']['license'])) {
            $license = trim((string)$data['_meta']['license']);
            if ($license !== '') {
                $meta['license'] = mb_substr($license, 0, 120);
            }
        }
        return $meta;
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 5, period: 60)]
    public function importCsv(int $poolId, string $csvData): DataResponse {
        if (!$this->canEditPool($poolId)) {
            return new DataResponse(['error' => 'No edit access to this pool'], Http::STATUS_FORBIDDEN);
        }

        $csvData = $this->normalizeText($csvData);

        $maxBytes = $this->getMaxImportBytes();
        if (strlen($csvData) > $maxBytes) {
            return new DataResponse(['error' => 'CSV data too large (max ' . (int)($maxBytes / 1024 / 1024) . ' MB)'], Http::STATUS_BAD_REQUEST);
        }

        $lines = array_filter(array_map('trim', explode("\n", $csvData)), function($l) { return $l !== ''; });
        $lines = array_values($lines);
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
        } elseif (strpos($firstLine, 'frage') !== false && strpos($firstLine, 'antwort') !== false) {
            array_shift($lines);
        }

        // Auto-detect delimiter from first data line
        $delimiter = $this->detectDelimiter($lines[0] ?? '');

        $imported = 0;
        $errors = [];
        $warnings = [];

        $this->db->beginTransaction();
        try {
            foreach ($lines as $lineNum => $line) {
                $fields = str_getcsv($line, $delimiter);

                $questionText = trim($fields[0] ?? '');
                if (empty($questionText)) {
                    $errors[] = 'Line ' . ($lineNum + 1) . ': Empty question text';
                    continue;
                }

                if (mb_strlen($questionText) > 5000) {
                    $errors[] = 'Line ' . ($lineNum + 1) . ': Question text too long (max 5000)';
                    continue;
                }

                // Open-question format: question,model_answer,open (3 fields)
                if (count($fields) === 3 && mb_strtolower(trim($fields[2])) === 'open') {
                    $modelAnswer = trim($fields[1]);
                    if ($modelAnswer === '') {
                        $errors[] = 'Line ' . ($lineNum + 1) . ': Empty model answer for open question';
                        continue;
                    }
                    if (mb_strlen($modelAnswer) > 2000) {
                        $modelAnswer = mb_substr($modelAnswer, 0, 2000);
                    }
                    $question = new Question();
                    $question->setPoolId($poolId);
                    $question->setUserId($this->userId);
                    $question->setText($questionText);
                    $question->setQuestionType('open');
                    $question = $this->questionMapper->createOrUpdate($question);

                    $answer = new Answer();
                    $answer->setQuestionId($question->getId());
                    $answer->setText($modelAnswer);
                    $answer->setIsCorrect(true);
                    $answer->setPosition(0);
                    $this->answerMapper->createOrUpdate($answer);

                    $imported++;
                    continue;
                }

                // MC format: minimum question + 2 answers + correct index
                if (count($fields) < 4) {
                    $errors[] = 'Line ' . ($lineNum + 1) . ': Not enough fields (need: question, answers, correct number)';
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

        $response = [
            'imported' => $imported,
            'errors' => $errors,
            'total_lines' => count($lines)
        ];
        if (!empty($warnings)) {
            $response['warnings'] = $warnings;
        }
        return new DataResponse($response, $imported > 0 ? Http::STATUS_CREATED : Http::STATUS_BAD_REQUEST);
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 5, period: 60)]
    public function importJson(int $poolId, string $jsonData): DataResponse {
        if (!$this->canEditPool($poolId)) {
            return new DataResponse(['error' => 'No edit access to this pool'], Http::STATUS_FORBIDDEN);
        }

        $jsonData = $this->normalizeText($jsonData);

        $maxBytes = $this->getMaxImportBytes();
        if (strlen($jsonData) > $maxBytes) {
            return new DataResponse(['error' => 'JSON data too large (max ' . (int)($maxBytes / 1024 / 1024) . ' MB)'], Http::STATUS_BAD_REQUEST);
        }

        $data = json_decode($jsonData, true);
        if (!is_array($data)) {
            return new DataResponse(['error' => 'Invalid JSON — check for missing brackets, commas, or quotes'], Http::STATUS_BAD_REQUEST);
        }

        $importMeta = $this->extractImportMeta($data);

        // Accept various wrapper formats: {questions:[...]}, {fragen:[...]}, or bare array
        if (isset($data['questions']) && is_array($data['questions'])) {
            $data = $data['questions'];
        } elseif (isset($data['fragen']) && is_array($data['fragen'])) {
            $data = $data['fragen'];
        } elseif (isset($data['items']) && is_array($data['items'])) {
            $data = $data['items'];
        }

        if (count($data) > 500) {
            return new DataResponse(['error' => 'Maximum 500 items per import'], Http::STATUS_BAD_REQUEST);
        }

        $imported = 0;
        $errors = [];
        $warnings = [];

        $this->db->beginTransaction();
        try {
            foreach ($data as $idx => $rawItem) {
                $num = $idx + 1;

                if (!is_array($rawItem)) {
                    $errors[] = "Item $num: Not a valid object";
                    continue;
                }

                // PBQ items have their own import path
                if (isset($rawItem['type']) && $rawItem['type'] === 'pbq') {
                    $imported += $this->importPbqItem($poolId, $rawItem, $num, $errors, $warnings);
                    continue;
                }

                // Normalize field names from any supported format
                $item = $this->normalizeJsonItem($rawItem);
                if ($item === null) {
                    $errors[] = "Item $num: Missing question text";
                    continue;
                }

                if (mb_strlen($item['text']) > 5000) {
                    $item['text'] = mb_substr($item['text'], 0, 5000);
                    $warnings[] = "Item $num: Question text truncated to 5000 chars";
                }

                // Open-question type
                if ($item['type'] === 'open') {
                    if (empty($item['answers'])) {
                        $errors[] = "Item $num: Open question needs at least 1 answer";
                        continue;
                    }
                    $modelText = $item['answers'][0]['text'];
                    if (mb_strlen($modelText) > 2000) {
                        $modelText = mb_substr($modelText, 0, 2000);
                    }
                    $question = new Question();
                    $question->setPoolId($poolId);
                    $question->setUserId($this->userId);
                    $question->setText($item['text']);
                    $question->setExplanation($item['explanation']);
                    $question->setQuestionType('open');
                    $question = $this->questionMapper->createOrUpdate($question);

                    $answer = new Answer();
                    $answer->setQuestionId($question->getId());
                    $answer->setText($modelText);
                    $answer->setIsCorrect(true);
                    $answer->setPosition(0);
                    $this->answerMapper->createOrUpdate($answer);

                    $imported++;
                    continue;
                }

                if (count($item['answers']) < 2) {
                    $errors[] = "Item $num: Need at least 2 answers";
                    continue;
                }

                if (count($item['answers']) > 8) {
                    $item['answers'] = array_slice($item['answers'], 0, 8);
                    $warnings[] = "Item $num: Truncated to 8 answers";
                }

                $correctCount = 0;
                foreach ($item['answers'] as $a) {
                    if ($a['is_correct']) $correctCount++;
                }

                if ($correctCount === 0) {
                    // Best-effort: mark first answer as correct + warn
                    $item['answers'][0]['is_correct'] = true;
                    $correctCount = 1;
                    $warnings[] = "Item $num: No correct answer marked — defaulted to first answer";
                }

                $questionType = $correctCount > 1 ? 'multi' : 'single';

                // Validate ALL answer texts BEFORE creating the question to prevent orphans
                $validatedAnswers = [];
                $hasInvalidAnswer = false;
                foreach ($item['answers'] as $i => $answerData) {
                    $answerText = $answerData['text'];
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
                        'is_correct' => $answerData['is_correct'],
                        'position' => $i,
                    ];
                }
                if ($hasInvalidAnswer) {
                    continue;
                }

                $question = new Question();
                $question->setPoolId($poolId);
                $question->setUserId($this->userId);
                $question->setText($item['text']);
                $question->setExplanation($item['explanation']);
                $question->setDifficulty($item['difficulty'] !== null ? trim($item['difficulty']) : null);
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

        $response = [
            'imported' => $imported,
            'errors' => $errors,
            'total_items' => count($data)
        ];
        if (!empty($importMeta)) {
            $response['meta'] = $importMeta;
        }
        if (!empty($warnings)) {
            $response['warnings'] = $warnings;
        }
        return new DataResponse($response, $imported > 0 ? Http::STATUS_CREATED : Http::STATUS_BAD_REQUEST);
    }

    private function importPbqItem(int $poolId, array $item, int $num, array &$errors, array &$warnings): int {
        $text = trim($item['question_text'] ?? $item['text'] ?? '');
        if ($text === '') {
            $errors[] = "PBQ #$num: missing question_text";
            return 0;
        }

        $subtype = $item['subtype'] ?? null;
        if (!in_array($subtype, ['dropdown', 'placement', 'cli', 'cable'], true)) {
            $errors[] = "PBQ #$num: invalid subtype '$subtype'";
            return 0;
        }

        $pbqConfig = $item['pbq_config'] ?? null;
        if (!is_array($pbqConfig)) {
            $errors[] = "PBQ #$num: pbq_config must be an object";
            return 0;
        }

        $question = new \OCA\Learning\Db\Question();
        $question->setPoolId($poolId);
        $question->setUserId($this->userId);
        $question->setText($text);
        $question->setExplanation($item['explanation'] ?? null);
        $question->setQuestionType('pbq');
        $question->setPbqSubtype($subtype);
        $question->setPbqConfig(json_encode($pbqConfig));
        $question->setDifficulty($item['difficulty'] ?? 'medium');
        $question->setReviewStatus('published');

        $this->questionMapper->createOrUpdate($question);
        return 1;
    }

    /**
     * @NoAdminRequired
     *
     * File upload endpoint — accepts multipart/form-data with a CSV or JSON file.
     * Auto-detects format from file extension and content.
     */
    #[UserRateLimit(limit: 5, period: 60)]
    public function importFile(int $poolId): DataResponse {
        if (!$this->canEditPool($poolId)) {
            return new DataResponse(['error' => 'No edit access to this pool'], Http::STATUS_FORBIDDEN);
        }

        $file = $this->request->getUploadedFile('file');
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            return new DataResponse(['error' => 'No file uploaded or upload error'], Http::STATUS_BAD_REQUEST);
        }

        $maxBytes = $this->getMaxImportBytes();
        if ($file['size'] > $maxBytes) {
            return new DataResponse(['error' => 'File too large (max ' . (int)($maxBytes / 1024 / 1024) . ' MB)'], Http::STATUS_BAD_REQUEST);
        }

        $content = file_get_contents($file['tmp_name']);
        if ($content === false || $content === '') {
            return new DataResponse(['error' => 'Could not read uploaded file'], Http::STATUS_BAD_REQUEST);
        }

        $content = $this->normalizeText($content);

        // Auto-detect format: try JSON first, fall back to CSV
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $isJson = $ext === 'json';

        if (!$isJson) {
            // Try to detect JSON by content
            $trimmed = ltrim($content);
            if ($trimmed !== '' && ($trimmed[0] === '[' || $trimmed[0] === '{')) {
                $isJson = true;
            }
        }

        if ($isJson) {
            return $this->importJson($poolId, $content);
        } else {
            return $this->importCsv($poolId, $content);
        }
    }
}
