<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCA\Learning\Service\Llm\LlmConsentException;
use OCA\Learning\Service\Llm\LlmGateway;
use OCA\Learning\Service\Llm\LlmProviderException;
use OCA\Learning\Service\Llm\LlmPurpose;
use OCA\Learning\Service\Llm\LlmRequest;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * KI explanation (purpose explain) and question generation (purpose question_generate).
 *
 * Task B4: both send through LlmGateway. The 5.5 Nextcloud TaskProcessing/TextProcessing path is
 * gone — the connection snapshot, and so the consent, never names it. A "task" is a cache entry
 * the frontend polls; the call itself runs synchronously in scheduleLocalTask().
 */
class AIService {
    private const LOCAL_TASK_CACHE_NAMESPACE = 'learning_ai_tasks';
    private const LOCAL_TASK_TTL = 3600;

    public function __construct(
        private ICacheFactory $cacheFactory,
        private LoggerInterface $logger,
        private LlmService $llmService,
        private LlmGateway $gateway,
    ) {
    }

    public function isAvailable(): bool {
        return $this->llmService->isAvailable();
    }

    /**
     * @throws LlmConsentException the user has no valid consent for the current recipient
     */
    public function schedulePrompt(string $prompt, string $userId): int {
        return $this->scheduleLocalTask($userId, LlmPurpose::Explain,
            new LlmRequest(
                'You are a concise learning assistant. Explain the question clearly and accurately.',
                $prompt,
                0.3,
                800,
            ),
            false,
        );
    }

    /**
     * @throws LlmConsentException the user has no valid consent for the current recipient
     */
    public function generateQuestions(string $text, int $count, string $lang, string $userId): int {
        $count = max(1, min(30, $count));

        // Limit input text to ~3000 words
        $words = preg_split('/\s+/', $text);
        if (count($words) > 3000) {
            $text = implode(' ', array_slice($words, 0, 3000));
        }

        $prompt = $this->buildPrompt($text, $count, $lang);

        return $this->scheduleLocalTask($userId, LlmPurpose::QuestionGenerate,
            new LlmRequest('You generate valid JSON question sets for a learning app.', $prompt, 0.4, 2400),
            true,
        );
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

    public function getTaskStatus(int $taskId, string $userId): array {
        return $this->getLocalTaskStatus($taskId, $userId);
    }

    /**
     * Begins the gateway session before a task exists (no consent: nothing is cached or sent),
     * then sends once. A consent lost between begin() and send() marks the task failed with
     * consent_required and reaches the caller; any other error only fails the task.
     *
     * @throws LlmConsentException
     * @throws \Exception 'No AI provider configured' when AI is off or has no usable backend
     */
    private function scheduleLocalTask(string $userId, LlmPurpose $purpose, LlmRequest $request, bool $parseQuestions): int {
        if (!$this->llmService->isAvailable()) {
            throw new \Exception('No AI provider configured');
        }
        $session = $this->gateway->begin($userId, $purpose);

        $taskId = random_int(10000000, 2147483647);
        $cache = $this->cacheFactory->createDistributed(self::LOCAL_TASK_CACHE_NAMESPACE);
        $cacheKey = $this->localTaskCacheKey($taskId);

        $cache->set($cacheKey, [
            'user_id' => $userId,
            'status' => 'running',
            'questions' => [],
        ], self::LOCAL_TASK_TTL);

        try {
            $output = $this->gateway->send($session, $request)->text;
            $cache->set($cacheKey, [
                'user_id' => $userId,
                'status' => 'completed',
                'output' => $output,
                'questions' => $parseQuestions ? $this->parseGeneratedQuestions($output) : [],
            ], self::LOCAL_TASK_TTL);
        } catch (LlmConsentException $e) {
            $this->failTask($cache, $cacheKey, $userId, 'consent_required');
            throw $e;
        } catch (\Throwable $e) {
            $category = $e instanceof LlmProviderException ? $e->category : 'internal';
            $this->logger->warning('AIService local task failed: ' . $category, ['app' => 'learning']);
            $this->failTask($cache, $cacheKey, $userId, $category);
        }

        return $taskId;
    }

    /** @param \OCP\ICache $cache */
    private function failTask($cache, string $cacheKey, string $userId, string $error): void {
        $cache->set($cacheKey, [
            'user_id' => $userId,
            'status' => 'failed',
            'error' => $error,
            'questions' => [],
        ], self::LOCAL_TASK_TTL);
    }

    /**
     * @return array{status: string, questions: array<int, array<string, mixed>>, output?: string, error?: string}
     */
    private function getLocalTaskStatus(int $taskId, string $userId): array {
        $cache = $this->cacheFactory->createDistributed(self::LOCAL_TASK_CACHE_NAMESPACE);
        $payload = $cache->get($this->localTaskCacheKey($taskId));

        if (!is_array($payload)) {
            // Expired from the cache, or the ID of a task the 5.5 path queued in Nextcloud's own
            // AI queue (spec 4.3): those are gone with the upgrade. Answered as a failed task, so
            // the frontend stops polling; nothing about the task is revealed.
            return ['status' => 'failed', 'questions' => [], 'error' => 'expired'];
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

    public function parseGeneratedQuestions(string $output): array {
        // Strip markdown code fences if present
        $output = trim($output);
        $output = preg_replace('/^```(?:json)?\s*/i', '', $output);
        $output = preg_replace('/\s*```$/', '', $output);
        $output = trim($output);

        $data = json_decode($output, true);
        if (!is_array($data)) {
            // Task B4: the model output is content and stays out of the log
            $this->logger->warning('AI output is not valid JSON', ['app' => 'learning', 'output_chars' => strlen($output)]);
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
