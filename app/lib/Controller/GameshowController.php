<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\GameshowService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\IRequest;

class GameshowController extends Controller {
    private GameshowService $service;
    private ?string $userId;

    public function __construct(string $appName, IRequest $request, GameshowService $service, ?string $userId) {
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
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to create gameshow session'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function join(string $code): DataResponse {
        try {
            return new DataResponse($this->service->joinSession($code, $this->userId));
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to join gameshow session'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function ready(string $code, ?string $lang = null): DataResponse {
        try {
            return new DataResponse($this->service->setReady($code, $this->userId, $lang));
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to set ready'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 120, period: 60)]
    public function state(string $code, ?string $lang = null): DataResponse {
        try {
            return new DataResponse($this->service->getState($code, $this->userId, $lang));
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to get gameshow state'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function answer(string $code, int $answerId, int $answeredAt, ?string $lang = null): DataResponse {
        try {
            return new DataResponse($this->service->submitAnswer($code, $this->userId, $answerId, $answeredAt, $lang));
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to submit answer'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function history(): DataResponse {
        try {
            return new DataResponse($this->service->getHistory($this->userId));
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 60, period: 60)]
    public function courseLobby(int $courseId): DataResponse {
        try {
            return new DataResponse($this->service->getCourseLobby($courseId, $this->userId));
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to load course lobby'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * BACK-03: Roll the dice for the active player (board-game modes only).
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function roll(string $code, ?string $lang = null): DataResponse {
        try {
            return new DataResponse($this->service->rollDice($code, $this->userId, $lang));
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to roll dice'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * BACK-01: Select a category (Wissensturm mode only) before answering.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function category(string $code, int $categoryPoolId, ?string $lang = null): DataResponse {
        try {
            return new DataResponse($this->service->selectCategory($code, $this->userId, $categoryPoolId, $lang));
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to select category'], Http::STATUS_BAD_REQUEST);
        }
    }
}
