<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\Answer;
use OCA\Learning\Db\AnswerMapper;
use OCA\Learning\Db\Pool;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\Question;
use OCA\Learning\Db\QuestionMapper;
use OCP\IDBConnection;

class StarterPoolService {
    private const STARTER_DIR = __DIR__ . '/../../data/starter-pools';

    private PoolMapper $poolMapper;
    private QuestionMapper $questionMapper;
    private AnswerMapper $answerMapper;
    private IDBConnection $db;

    public function __construct(
        PoolMapper $poolMapper,
        QuestionMapper $questionMapper,
        AnswerMapper $answerMapper,
        IDBConnection $db
    ) {
        $this->poolMapper = $poolMapper;
        $this->questionMapper = $questionMapper;
        $this->answerMapper = $answerMapper;
        $this->db = $db;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAvailablePools(): array {
        $dir = self::STARTER_DIR;
        if (!is_dir($dir)) {
            return [];
        }

        $items = [];
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $starterId = basename($file, '.json');
            $payload = $this->loadStarterPayload($starterId);
            $meta = $payload['_meta'] ?? [];
            $questions = $payload['questions'] ?? [];

            $items[] = [
                'id' => $starterId,
                'title' => (string)($meta['title'] ?? $starterId),
                'description' => (string)($meta['description'] ?? ''),
                'question_count' => is_array($questions) ? count($questions) : 0,
                'exam' => (string)($meta['exam'] ?? ''),
                'difficulty' => (string)($meta['difficulty'] ?? 'beginner'),
                'language' => (string)($meta['language'] ?? 'en'),
            ];
        }

        usort($items, static fn(array $left, array $right): int => strcasecmp((string)$left['title'], (string)$right['title']));
        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public function importStarterPool(string $starterId, string $userId): array {
        $payload = $this->loadStarterPayload($starterId);
        $meta = is_array($payload['_meta'] ?? null) ? $payload['_meta'] : [];
        $questions = is_array($payload['questions'] ?? null) ? $payload['questions'] : [];
        if ($questions === []) {
            throw new \RuntimeException('Starter pool has no questions');
        }

        $pool = new Pool();
        $pool->setName($this->truncate((string)($meta['title'] ?? $starterId), 200));
        $pool->setDescription($this->truncate((string)($meta['description'] ?? ''), 2000) ?: null);
        $pool->setUserId($userId);
        $pool->setHandbookKey($this->truncate((string)($meta['handbook_key'] ?? ''), 64) ?: null);
        $pool->setHandbookTitle($this->truncate((string)($meta['handbook_title'] ?? ''), 255) ?: null);
        $pool->setChapterKey($this->truncate((string)($meta['chapter_key'] ?? ''), 64) ?: null);
        $pool->setChapterTitle($this->truncate((string)($meta['chapter_title'] ?? ''), 255) ?: null);
        $pool->setChapterOrder(isset($meta['chapter_order']) && is_numeric($meta['chapter_order']) ? (int)$meta['chapter_order'] : null);
        $pool->setReviewStatus('published');

        $defaults = [
            'difficulty' => $meta['difficulty'] ?? 'easy',
            'exam_key' => $meta['exam_key'] ?? null,
            'handbook_key' => $meta['handbook_key'] ?? null,
            'handbook_title' => $meta['handbook_title'] ?? null,
            'chapter_key' => $meta['chapter_key'] ?? null,
            'chapter_title' => $meta['chapter_title'] ?? null,
            'chapter_order' => $meta['chapter_order'] ?? null,
        ];

        $this->db->beginTransaction();
        try {
            $pool = $this->poolMapper->createOrUpdate($pool);
            foreach ($questions as $questionData) {
                $this->insertQuestion($pool->getId(), $userId, $questionData, $defaults);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return [
            'status' => 'ok',
            'pool' => $pool->jsonSerialize(),
            'imported_questions' => count($questions),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadStarterPayload(string $starterId): array {
        if (!preg_match('/^[a-z0-9-]+$/', $starterId)) {
            throw new \RuntimeException('Invalid starter pool id');
        }

        $file = self::STARTER_DIR . '/' . $starterId . '.json';
        if (!is_file($file)) {
            throw new \RuntimeException('Starter pool not found');
        }

        $payload = json_decode((string)file_get_contents($file), true);
        if (!is_array($payload)) {
            throw new \RuntimeException('Starter pool JSON is invalid');
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $questionData
     * @param array<string, mixed> $defaults
     */
    private function insertQuestion(int $poolId, string $userId, array $questionData, array $defaults): void {
        $text = trim((string)($questionData['text'] ?? ''));
        $answers = is_array($questionData['answers'] ?? null) ? $questionData['answers'] : [];
        if ($text === '' || count($answers) < 2) {
            throw new \RuntimeException('Starter question is invalid');
        }

        $correctCount = 0;
        foreach ($answers as $answerData) {
            if (!empty($answerData['is_correct'])) {
                $correctCount++;
            }
        }
        if ($correctCount !== 1) {
            throw new \RuntimeException('Starter question must have exactly one correct answer');
        }

        $question = new Question();
        $question->setPoolId($poolId);
        $question->setUserId($userId);
        $question->setText($this->truncate($text, 2000));
        $question->setExplanation($this->truncate((string)($questionData['explanation'] ?? ''), 4000) ?: null);
        $question->setDifficulty($this->truncate((string)($questionData['difficulty'] ?? $defaults['difficulty'] ?? 'easy'), 20) ?: 'easy');
        $question->setQuestionType('single');
        $question->setExamKey($this->truncate((string)($questionData['exam_key'] ?? $defaults['exam_key'] ?? ''), 64) ?: null);
        $question->setHandbookKey($this->truncate((string)($questionData['handbook_key'] ?? $defaults['handbook_key'] ?? ''), 64) ?: null);
        $question->setHandbookTitle($this->truncate((string)($questionData['handbook_title'] ?? $defaults['handbook_title'] ?? ''), 255) ?: null);
        $question->setChapterKey($this->truncate((string)($questionData['chapter_key'] ?? $defaults['chapter_key'] ?? ''), 64) ?: null);
        $question->setChapterTitle($this->truncate((string)($questionData['chapter_title'] ?? $defaults['chapter_title'] ?? ''), 255) ?: null);
        $question->setChapterOrder(isset($questionData['chapter_order']) && is_numeric($questionData['chapter_order'])
            ? (int)$questionData['chapter_order']
            : (isset($defaults['chapter_order']) && is_numeric($defaults['chapter_order']) ? (int)$defaults['chapter_order'] : null));
        $question->setReviewStatus('published');

        $question = $this->questionMapper->createOrUpdate($question);

        foreach (array_values($answers) as $index => $answerData) {
            $answer = new Answer();
            $answer->setQuestionId($question->getId());
            $answer->setText($this->truncate(trim((string)($answerData['text'] ?? '')), 2000));
            $answer->setIsCorrect(filter_var($answerData['is_correct'] ?? false, FILTER_VALIDATE_BOOLEAN));
            $answer->setPosition($index);
            $this->answerMapper->createOrUpdate($answer);
        }
    }

    private function truncate(string $value, int $maxLength): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        return mb_substr($value, 0, $maxLength);
    }
}
