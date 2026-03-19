<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\LeagueService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class LeagueController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private LeagueService $service,
        private ?string $userId
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function create(int $courseId, int $poolId, int $clPoolId, ?string $name = null): DataResponse {
        try {
            return new DataResponse(
                $this->service->createSeason($courseId, (string)$this->userId, $poolId, $clPoolId, $name),
                Http::STATUS_CREATED
            );
        } catch (\Throwable $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function active(int $courseId): DataResponse {
        try {
            return new DataResponse($this->service->getOverview($courseId, (string)$this->userId));
        } catch (\Throwable $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function start(int $courseId, int $id): DataResponse {
        try {
            return new DataResponse($this->service->startSeason($courseId, $id, (string)$this->userId));
        } catch (\Throwable $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function finish(int $courseId, int $id): DataResponse {
        try {
            return new DataResponse($this->service->finishSeason($courseId, $id, (string)$this->userId));
        } catch (\Throwable $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function challenge(int $courseId, int $id, string $opponentUid): DataResponse {
        try {
            return new DataResponse($this->service->challengeOpponent($courseId, $id, $opponentUid, (string)$this->userId));
        } catch (\Throwable $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function accept(int $courseId, int $id, int $challengeId): DataResponse {
        try {
            return new DataResponse($this->service->acceptChallenge($courseId, $id, $challengeId, (string)$this->userId));
        } catch (\Throwable $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function table(int $courseId, int $id): DataResponse {
        try {
            return new DataResponse($this->service->getTable($courseId, $id, (string)$this->userId));
        } catch (\Throwable $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function cl(int $courseId, int $id): DataResponse {
        try {
            return new DataResponse($this->service->getChampionsLeague($courseId, $id, (string)$this->userId));
        } catch (\Throwable $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }
}
