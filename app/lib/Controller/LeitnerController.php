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
            return new DataResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function due(int $poolId, int $limit = 10): DataResponse {
        try {
            return new DataResponse($this->service->getDueQuestions($poolId, $this->userId, $limit));
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function answer(int $itemId, bool $correct): DataResponse {
        try {
            return new DataResponse($this->service->answerQuestion($itemId, $correct, $this->userId));
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function stats(int $poolId): DataResponse {
        try {
            return new DataResponse($this->service->getStats($poolId, $this->userId));
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], 400);
        }
    }
}
