<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\TrainingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\IRequest;

class TrainingController extends Controller {
    private $service;
    private $userId;

    public function __construct($appName, IRequest $request, TrainingService $service, $userId) {
        parent::__construct($appName, $request);
        $this->service = $service;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function start(int $poolId, ?int $limit = null, string $mode = 'training'): DataResponse {
        try {
            return new DataResponse($this->service->startSession($poolId, $this->userId, $limit, $mode), 201);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to start training session'], 400);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 120, period: 60)]
    public function answer(int $sessionId, int $questionId, ?int $answerId = null, ?array $answerIds = null): DataResponse {
        try {
            return new DataResponse($this->service->submitAnswer($sessionId, $questionId, $answerId, $this->userId, $answerIds));
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to submit answer'], 400);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function submitBatch(int $sessionId, array $answers): DataResponse {
        // S3: Hard cap on batch size
        if (count($answers) > 200) {
            return new DataResponse(['error' => 'Batch too large (max 200)'], Http::STATUS_BAD_REQUEST);
        }
        try {
            return new DataResponse($this->service->submitBatch($sessionId, $answers, $this->userId));
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to submit answers'], 400);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function complete(int $sessionId): DataResponse {
        try {
            return new DataResponse($this->service->completeSession($sessionId, $this->userId));
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to complete session'], 400);
        }
    }
}
