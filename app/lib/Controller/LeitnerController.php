<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\LeitnerService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
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
            return new DataResponse(['error' => 'Failed to initialize pool'], 400);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function due(int $poolId, int $limit = 10): DataResponse {
        try {
            $limit = max(1, min($limit, 100));
            return new DataResponse($this->service->getDueQuestions($poolId, $this->userId, $limit));
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to load due questions'], 400);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 120, period: 60)]
    public function answer(int $itemId, ?int $answerId = null, ?array $answerIds = null): DataResponse {
        try {
            return new DataResponse($this->service->answerQuestion($itemId, $answerId, $this->userId, $answerIds));
        } catch (\Exception $e) {
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
            return new DataResponse(['error' => 'Failed to load stats'], 400);
        }
    }
}
