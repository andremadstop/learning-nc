<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCP\IConfig;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class AIService {
    private const LOCAL_TASK_CACHE_NAMESPACE = 'learning_ai_tasks';
    private const LOCAL_TASK_TTL = 3600;

    private IDBConnection $db;
    private IConfig $config;
    private ICacheFactory $cacheFactory;
    private LoggerInterface $logger;
    private LlmService $llmService;

    /** @var \OCP\TaskProcessing\IManager|null */
    private $taskProcessingManager = null;
    /** @var \OCP\TextProcessing\IManager|null */
    private $textProcessingManager = null;

    public function __construct(
        IDBConnection $db,
        IConfig $config,
        ICacheFactory $cacheFactory,
        LoggerInterface $logger,
        LlmService $llmService
    ) {
        $this->db = $db;
        $this->config = $config;
        $this->cacheFactory = $cacheFactory;
        $this->logger = $logger;
        $this->llmService = $llmService;

        // Runtime detection: NC 30+ TaskProcessing vs NC 29 TextProcessing
        if (class_exists(\OCP\TaskProcessing\IManager::class)) {
            try {
                $this->taskProcessingManager = \OC::$server->get(\OCP\TaskProcessing\IManager::class);
            } catch (\Throwable $e) {
                // Not available
            }
        }
        if ($this->taskProcessingManager === null && class_exists(\OCP\TextProcessing\IManager::class)) {
            try {
                $this->textProcessingManager = \OC::$server->get(\OCP\TextProcessing\IManager::class);
            } catch (\Throwable $e) {
                // Not available
            }
        }
    }

    public function isAvailable(): bool {
        if (!$this->useLegacyTaskBackend()) {
            return $this->llmService->isAvailable();
        }

        if ($this->taskProcessingManager !== null) {
            try {
                $types = $this->taskProcessingManager->getAvailableTaskTypes();
                return isset($types['core:text2text']) || isset($types['core:text2text:chat']);
            } catch (\Throwable $e) {
                return false;
            }
        }
        if ($this->textProcessingManager !== null) {
            try {
                $types = $this->textProcessingManager->getAvailableTaskTypes();
                foreach ($types as $type) {
                    if ($type instanceof \OCP\TextProcessing\FreePromptTaskType
                        || (is_string($type) && str_contains($type, 'FreePrompt'))) {
                        return true;
                    }
                }
                return !empty($types);
            } catch (\Throwable $e) {
                return false;
            }
        }
        return false;
    }

    public function schedulePrompt(string $prompt, string $userId): int {
        if (!$this->useLegacyTaskBackend()) {
            return $this->scheduleLocalTask($prompt, $userId, [
                'system_prompt' => 'You are a concise learning assistant. Explain the question clearly and accurately.',
                'temperature' => 0.3,
                'max_output_tokens' => 800,
            ]);
        }

        if ($this->taskProcessingManager !== null) {
            return $this->scheduleTaskProcessing($prompt, $userId);
        }
        if ($this->textProcessingManager !== null) {
            return $this->scheduleTextProcessing($prompt, $userId);
        }
        throw new \Exception('No AI provider configured');
    }

    public function generateQuestions(string $text, int $count, string $lang, string $userId): int {
        $count = max(1, min(30, $count));

        // Limit input text to ~3000 words
        $words = preg_split('/\s+/', $text);
        if (count($words) > 3000) {
            $text = implode(' ', array_slice($words, 0, 3000));
        }

        $prompt = $this->buildPrompt($text, $count, $lang);

        if (!$this->useLegacyTaskBackend()) {
            return $this->scheduleLocalTask($prompt, $userId, [
                'system_prompt' => 'You generate valid JSON question sets for a learning app.',
                'temperature' => 0.4,
                'max_output_tokens' => 2400,
                'parse_questions' => true,
            ]);
        }

        if ($this->taskProcessingManager !== null) {
            return $this->scheduleTaskProcessing($prompt, $userId);
        }
        if ($this->textProcessingManager !== null) {
            return $this->scheduleTextProcessing($prompt, $userId);
        }
        throw new \Exception('No AI provider configured');
    }

    private function buildPrompt(string $text, int $count, string $lang): string {
        return "Generate exactly {$count} multiple choice questions from the following text.

Rules:
- Each question must have exactly 4 answer options
- Exactly 1 answer must be correct
- Include a brief explanation for the correct answer
- Difficulty should vary: 30% easy, 50% medium, 20% hard
- Questions should test understanding, not just memorization
- Language: {$lang}

Output ONLY valid JSON (no markdown, no backticks):
[
  {
    \"text\": \"Question text here?\",
    \"answers\": [
      {\"text\": \"Answer A\", \"is_correct\": false},
      {\"text\": \"Answer B\", \"is_correct\": true},
      {\"text\": \"Answer C\", \"is_correct\": false},
      {\"text\": \"Answer D\", \"is_correct\": false}
    ],
    \"explanation\": \"Brief explanation\",
    \"difficulty\": \"easy\"
  }
]

TEXT:
{$text}";
    }

    private function scheduleTaskProcessing(string $prompt, string $userId): int {
        $taskClass = \OCP\TaskProcessing\Task::class;
        $task = new $taskClass('core:text2text', ['input' => $prompt], 'learning', $userId);
        $this->taskProcessingManager->scheduleTask($task);
        $taskId = $task->getId();

        // Store mapping in our DB for retrieval
        $this->storeTaskMapping($taskId, 'taskprocessing', $userId);
        return $taskId;
    }

    private function scheduleTextProcessing(string $prompt, string $userId): int {
        $taskTypeClass = \OCP\TextProcessing\FreePromptTaskType::class;
        $taskClass = \OCP\TextProcessing\Task::class;
        $task = new $taskClass(new $taskTypeClass(), $prompt, 'learning', $userId);
        $this->textProcessingManager->scheduleTask($task);
        $taskId = $task->getId();

        $this->storeTaskMapping($taskId, 'textprocessing', $userId);
        return $taskId;
    }

    private function storeTaskMapping(int $taskId, string $backend, string $userId): void {
        // Use a lightweight approach: store in learning_ai_tasks
        // For simplicity, we'll store in-memory and use NC's task API for retrieval
        // The task ID itself is the reference — no extra table needed
    }

    public function getTaskStatus(int $taskId, string $userId): array {
        if (!$this->useLegacyTaskBackend()) {
            return $this->getLocalTaskStatus($taskId, $userId);
        }

        if ($this->taskProcessingManager !== null) {
            try {
                $task = $this->taskProcessingManager->getTask($taskId);
                if ($task->getUserId() !== $userId) {
                    throw new \Exception('Not your task');
                }
                $status = $task->getStatus();
                $statusMap = [
                    0 => 'pending',     // STATUS_UNKNOWN
                    1 => 'pending',     // STATUS_SCHEDULED
                    2 => 'running',     // STATUS_RUNNING
                    3 => 'completed',   // STATUS_SUCCESSFUL
                    4 => 'failed',      // STATUS_FAILED
                    5 => 'cancelled',   // STATUS_CANCELLED
                ];
                $statusStr = $statusMap[$status] ?? 'pending';

                $result = ['status' => $statusStr, 'questions' => []];
                if ($statusStr === 'completed') {
                    $output = $task->getOutput();
                    $text = $output['output'] ?? '';
                    $result['output'] = $text;
                    $result['questions'] = $this->parseGeneratedQuestions($text);
                }
                if ($statusStr === 'failed') {
                    $result['error'] = $task->getErrorMessage() ?? 'Task failed';
                }
                return $result;
            } catch (\OCP\TaskProcessing\Exception\NotFoundException $e) {
                // Fall through to TextProcessing
            }
        }

        if ($this->textProcessingManager !== null) {
            try {
                $task = $this->textProcessingManager->getTask($taskId);
                if ($task->getUserId() !== $userId) {
                    throw new \Exception('Not your task');
                }
                $status = $task->getStatus();
                $statusMap = [
                    0 => 'pending',
                    1 => 'running',
                    2 => 'completed',
                    3 => 'failed',
                    4 => 'cancelled',
                ];
                $statusStr = $statusMap[$status] ?? 'pending';

                $result = ['status' => $statusStr, 'questions' => []];
                if ($statusStr === 'completed') {
                    $text = $task->getOutput() ?? '';
                    $result['output'] = $text;
                    $result['questions'] = $this->parseGeneratedQuestions($text);
                }
                return $result;
            } catch (\Throwable $e) {
                throw new \Exception('Task not found');
            }
        }

        throw new \Exception('No AI provider available');
    }

    /**
     * @param array<string, mixed> $context
     */
    private function scheduleLocalTask(string $prompt, string $userId, array $context): int {
        if (!$this->llmService->isAvailable()) {
            throw new \Exception('No AI provider configured');
        }

        $taskId = random_int(10000000, 2147483647);
        $cache = $this->cacheFactory->createDistributed(self::LOCAL_TASK_CACHE_NAMESPACE);
        $cacheKey = $this->localTaskCacheKey($taskId);

        $cache->set($cacheKey, [
            'user_id' => $userId,
            'status' => 'running',
            'questions' => [],
        ], self::LOCAL_TASK_TTL);

        try {
            $output = $this->llmService->generateText($prompt, $context);
            $cache->set($cacheKey, [
                'user_id' => $userId,
                'status' => 'completed',
                'output' => $output,
                'questions' => !empty($context['parse_questions']) ? $this->parseGeneratedQuestions($output) : [],
            ], self::LOCAL_TASK_TTL);
        } catch (\Throwable $e) {
            $this->logger->warning('AIService local task failed: ' . $e->getMessage(), ['app' => 'learning']);
            $cache->set($cacheKey, [
                'user_id' => $userId,
                'status' => 'failed',
                'error' => $e->getMessage(),
                'questions' => [],
            ], self::LOCAL_TASK_TTL);
        }

        return $taskId;
    }

    /**
     * @return array{status: string, questions: array<int, array<string, mixed>>, output?: string, error?: string}
     */
    private function getLocalTaskStatus(int $taskId, string $userId): array {
        $cache = $this->cacheFactory->createDistributed(self::LOCAL_TASK_CACHE_NAMESPACE);
        $payload = $cache->get($this->localTaskCacheKey($taskId));

        if (!is_array($payload)) {
            throw new \Exception('Task not found');
        }

        if (($payload['user_id'] ?? null) !== $userId) {
            throw new \Exception('Not your task');
        }

        return [
            'status' => (string)($payload['status'] ?? 'pending'),
            'questions' => is_array($payload['questions'] ?? null) ? $payload['questions'] : [],
            'output' => isset($payload['output']) ? (string)$payload['output'] : null,
            'error' => isset($payload['error']) ? (string)$payload['error'] : null,
        ];
    }

    private function localTaskCacheKey(int $taskId): string {
        return 'task_' . $taskId;
    }

    private function useLegacyTaskBackend(): bool {
        return trim($this->config->getAppValue('learning', 'ai_provider', '')) === '';
    }

    public function parseGeneratedQuestions(string $output): array {
        // Strip markdown code fences if present
        $output = trim($output);
        $output = preg_replace('/^```(?:json)?\s*/i', '', $output);
        $output = preg_replace('/\s*```$/', '', $output);
        $output = trim($output);

        $data = json_decode($output, true);
        if (!is_array($data)) {
            $this->logger->warning('AI output is not valid JSON', ['app' => 'learning', 'output' => substr($output, 0, 500)]);
            return [];
        }

        $questions = [];
        foreach ($data as $item) {
            if (!is_array($item) || empty($item['text']) || !isset($item['answers']) || !is_array($item['answers'])) {
                continue;
            }

            // Validate: exactly 4 answers, exactly 1 correct
            if (count($item['answers']) !== 4) {
                continue;
            }
            $correctCount = 0;
            $validAnswers = [];
            foreach ($item['answers'] as $answer) {
                if (!is_array($answer) || empty($answer['text'])) {
                    continue 2; // skip this question
                }
                $isCorrect = !empty($answer['is_correct']);
                if ($isCorrect) $correctCount++;
                $validAnswers[] = [
                    'text' => (string)$answer['text'],
                    'is_correct' => $isCorrect,
                ];
            }
            if ($correctCount !== 1 || count($validAnswers) !== 4) {
                continue;
            }

            $difficulty = in_array($item['difficulty'] ?? '', ['easy', 'medium', 'hard']) ? $item['difficulty'] : 'medium';

            $questions[] = [
                'text' => (string)$item['text'],
                'answers' => $validAnswers,
                'explanation' => (string)($item['explanation'] ?? ''),
                'difficulty' => $difficulty,
            ];
        }

        return $questions;
    }
}
