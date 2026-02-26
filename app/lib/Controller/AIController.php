<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\AIService;
use OCA\Learning\Service\QuestionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\IDBConnection;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class AIController extends Controller {
    private AIService $aiService;
    private QuestionService $questionService;
    private IDBConnection $db;
    private LoggerInterface $logger;
    private ?string $userId;

    public function __construct(
        string $appName,
        IRequest $request,
        AIService $aiService,
        QuestionService $questionService,
        IDBConnection $db,
        LoggerInterface $logger,
        ?string $userId
    ) {
        parent::__construct($appName, $request);
        $this->aiService = $aiService;
        $this->questionService = $questionService;
        $this->db = $db;
        $this->logger = $logger;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     */
    public function available(): JSONResponse {
        return new JSONResponse(['available' => $this->aiService->isAvailable()]);
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 5, period: 60)]
    public function generate(int $poolId, string $text, int $count = 10, string $lang = 'de'): JSONResponse {
        try {
            $this->questionService->verifyEditAccess($poolId, $this->userId);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'No edit access to this pool'], Http::STATUS_FORBIDDEN);
        }

        try {
            if (mb_strlen(trim($text)) < 50) {
                return new JSONResponse(['error' => 'Text too short (minimum 50 characters)'], Http::STATUS_BAD_REQUEST);
            }
            if (mb_strlen($text) > 50000) {
                return new JSONResponse(['error' => 'Text too long (maximum 50000 characters)'], Http::STATUS_BAD_REQUEST);
            }

            $taskId = $this->aiService->generateQuestions($text, $count, $lang, $this->userId);
            return new JSONResponse(['taskId' => $taskId]);
        } catch (\Exception $e) {
            $this->logger->error('AI generate error: ' . $e->getMessage(), ['app' => 'learning']);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function status(int $taskId): JSONResponse {
        try {
            $result = $this->aiService->getTaskStatus($taskId, $this->userId);
            return new JSONResponse($result);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function import(int $taskId, int $poolId, array $questions): JSONResponse {
        // Verify edit access (403 on failure)
        try {
            $this->questionService->verifyEditAccess($poolId, $this->userId);
        } catch (\Exception $e) {
            return new JSONResponse(['error' => 'No edit access to this pool'], Http::STATUS_FORBIDDEN);
        }

        try {
            // Verify task ownership and completion status
            $taskStatus = $this->aiService->getTaskStatus($taskId, $this->userId);
            if (($taskStatus['status'] ?? '') !== 'completed') {
                return new JSONResponse(['error' => 'Task not ready or not owned by user'], Http::STATUS_BAD_REQUEST);
            }

            // Batch size limit
            if (count($questions) > 100) {
                return new JSONResponse(['error' => 'Too many questions (max 100)'], Http::STATUS_BAD_REQUEST);
            }

            // Import in a transaction to prevent partial imports
            $this->db->beginTransaction();
            try {
                $imported = 0;
                foreach ($questions as $q) {
                    if (empty($q['text']) || !isset($q['answers']) || !is_array($q['answers'])) {
                        continue;
                    }

                    // Build answers array
                    $answers = [];
                    foreach ($q['answers'] as $a) {
                        if (empty($a['text'])) continue;
                        $answers[] = [
                            'text' => (string)$a['text'],
                            'is_correct' => !empty($a['is_correct']),
                        ];
                    }

                    if (count($answers) < 2) continue;

                    $this->questionService->create(
                        $poolId,
                        $this->userId,
                        (string)$q['text'],
                        $q['explanation'] ?? null,
                        $q['difficulty'] ?? 'medium',
                        $answers,
                        'single'
                    );
                    $imported++;
                }
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }

            return new JSONResponse(['imported' => $imported]);
        } catch (\Exception $e) {
            $this->logger->error('AI import error: ' . $e->getMessage(), ['app' => 'learning']);
            return new JSONResponse(['error' => 'Failed to import questions'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
