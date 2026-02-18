<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\LeitnerService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class LeitnerController extends Controller {
    private $service;
    private $userId;

    public function __construct($appName, IRequest $request, LeitnerService $service, $userId) {
        parent::__construct($appName, $request);
        $this->service = $service;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     */
    public function initialize(int $poolId): DataResponse {
        try {
            $count = $this->service->initializePool($poolId, $this->userId);
            return new DataResponse(['initialized' => $count]);
        } catch (\Exception $e) {
            // FIX #8 MEDIUM: Generic error message
            return new DataResponse(['error' => 'Failed to initialize pool'], 400);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function due(int $poolId, int $limit = 10): DataResponse {
        try {
            // FIX #9 MEDIUM: Cap limit to prevent unbounded responses
            $limit = max(1, min($limit, 100));
            return new DataResponse($this->service->getDueQuestions($poolId, $this->userId, $limit));
        } catch (\Exception $e) {
            // FIX #8 MEDIUM: Generic error message
            return new DataResponse(['error' => 'Failed to load due questions'], 400);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function answer(int $itemId, int $answerId): DataResponse {
        try {
            return new DataResponse($this->service->answerQuestion($itemId, $answerId, $this->userId));
        } catch (\Exception $e) {
            // FIX #8 MEDIUM: Generic error message
            return new DataResponse(['error' => 'Failed to submit answer'], 400);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function stats(int $poolId): DataResponse {
        try {
            return new DataResponse($this->service->getStats($poolId, $this->userId));
        } catch (\Exception $e) {
            // FIX #8 MEDIUM: Generic error message
            return new DataResponse(['error' => 'Failed to load stats'], 400);
        }
    }
}
