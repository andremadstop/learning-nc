<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\GameshowService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class GameshowController extends Controller {
    private GameshowService $service;
    private ?string $userId;

    public function __construct(string $appName, IRequest $request, GameshowService $service, ?string $userId, private LoggerInterface $logger) {
        parent::__construct($appName, $request);
        $this->service = $service;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function create(int $poolId, string $mode, int $maxPlayers = 5, ?int $courseId = null): DataResponse {
        try {
            $maxPlayers = max(2, min(5, $maxPlayers));
            $validModes = ['sprint', 'elimination', 'lernwuerfel', 'wissensturm'];
            if (!in_array($mode, $validModes, true)) {
                return new DataResponse(['error' => 'Invalid mode: must be sprint, elimination, lernwuerfel, or wissensturm'], Http::STATUS_BAD_REQUEST);
            }
            return new DataResponse(
                $this->service->createSession($poolId, $this->userId, $mode, $maxPlayers, $courseId),
                Http::STATUS_CREATED
            );
        } catch (\Throwable $e) {
            return GameErrorResponse::fromThrowable($e, $this->logger, 'Failed to create gameshow session', 'create');
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function join(string $code): DataResponse {
        try {
            return new DataResponse($this->service->joinSession($code, $this->userId));
        } catch (\Throwable $e) {
            return GameErrorResponse::fromThrowable($e, $this->logger, 'Failed to join gameshow session', 'join');
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function ready(string $code, ?string $lang = null): DataResponse {
        try {
            return new DataResponse($this->service->setReady($code, $this->userId, $lang));
        } catch (\Throwable $e) {
            return GameErrorResponse::fromThrowable($e, $this->logger, 'Failed to set ready', 'ready');
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 120, period: 60)]
    public function state(string $code, ?string $lang = null): DataResponse {
        try {
            return new DataResponse($this->service->getState($code, $this->userId, $lang));
        } catch (\Throwable $e) {
            return GameErrorResponse::fromThrowable($e, $this->logger, 'Failed to get gameshow state', 'state');
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function answer(string $code, int $answerId, int $answeredAt, ?string $lang = null): DataResponse {
        try {
            return new DataResponse($this->service->submitAnswer($code, $this->userId, $answerId, $answeredAt, $lang));
        } catch (\Throwable $e) {
            return GameErrorResponse::fromThrowable($e, $this->logger, 'Failed to submit answer', 'answer');
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function history(): DataResponse {
        try {
            return new DataResponse($this->service->getHistory($this->userId));
        } catch (\Throwable $e) {
            return GameErrorResponse::fromThrowable($e, $this->logger, 'Request failed', 'history');
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 60, period: 60)]
    public function courseLobby(int $courseId): DataResponse {
        try {
            return new DataResponse($this->service->getCourseLobby($courseId, $this->userId));
        } catch (\Throwable $e) {
            return GameErrorResponse::fromThrowable($e, $this->logger, 'Failed to load course lobby', 'courseLobby');
        }
    }
}
