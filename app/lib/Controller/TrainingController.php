<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\TrainingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
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
    public function start(int $poolId): DataResponse {
        try {
            return new DataResponse($this->service->startSession($poolId, $this->userId), 201);
        } catch (\Exception $e) {
            // FIX #8 MEDIUM: Generic error message
            return new DataResponse(['error' => 'Failed to start training session'], 400);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function answer(int $sessionId, int $questionId, int $answerId): DataResponse {
        try {
            return new DataResponse($this->service->submitAnswer($sessionId, $questionId, $answerId, $this->userId));
        } catch (\Exception $e) {
            // FIX #8 MEDIUM: Generic error message
            return new DataResponse(['error' => 'Failed to submit answer'], 400);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function complete(int $sessionId): DataResponse {
        try {
            return new DataResponse($this->service->completeSession($sessionId, $this->userId));
        } catch (\Exception $e) {
            // FIX #8 MEDIUM: Generic error message
            return new DataResponse(['error' => 'Failed to complete session'], 400);
        }
    }
}
