<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\LeitnerService;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\XpService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\IRequest;

class LeitnerController extends Controller {
    private $service;
    private $streakService;
    private $badgeService;
    private $xpService;
    private $userId;

    public function __construct($appName, IRequest $request, LeitnerService $service, StreakService $streakService, BadgeService $badgeService, XpService $xpService, $userId) {
        parent::__construct($appName, $request);
        $this->service = $service;
        $this->streakService = $streakService;
        $this->badgeService = $badgeService;
        $this->xpService = $xpService;
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

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function streak(): DataResponse {
        try {
            $streakData = $this->streakService->getStreak($this->userId);
            return new DataResponse($streakData);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to load streak'], 400);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function badges(): DataResponse {
        try {
            $badges = $this->badgeService->getUserBadges($this->userId);
            $xp = $this->xpService->calculateXp($this->userId);
            return new DataResponse([
                'badges' => $badges,
                'xp' => $xp,
            ]);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to load badges'], 400);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function badgeProgress(): DataResponse {
        try {
            return new DataResponse(['progress' => $this->badgeService->getBadgeProgress($this->userId)]);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to load badge progress'], 400);
        }
    }
}
