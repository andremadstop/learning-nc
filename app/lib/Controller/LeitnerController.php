<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\LeitnerService;
use OCA\Learning\Service\ReadinessService;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\XpService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\IRequest;

class LeitnerController extends Controller {
    private LeitnerService $service;
    private StreakService $streakService;
    private BadgeService $badgeService;
    private XpService $xpService;
    private ReadinessService $readinessService;
    private ?string $userId;

    public function __construct(string $appName, IRequest $request, LeitnerService $service, StreakService $streakService, BadgeService $badgeService, XpService $xpService, ReadinessService $readinessService, ?string $userId) {
        parent::__construct($appName, $request);
        $this->service = $service;
        $this->streakService = $streakService;
        $this->badgeService = $badgeService;
        $this->xpService = $xpService;
        $this->readinessService = $readinessService;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     */
    public function queue(int $limit = 30, ?string $lang = null): DataResponse {
        try {
            $limit = max(1, min($limit, 100));
            return new DataResponse($this->service->getSmartQueue($this->userId, $limit, $lang));
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to load smart queue'], 400);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function queueCount(): DataResponse {
        try {
            return new DataResponse(['count' => $this->service->getQueueCount($this->userId)]);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to load queue count'], 400);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function remediation(int $limit = 20, ?string $lang = null): DataResponse {
        try {
            $limit = max(1, min($limit, 100));
            return new DataResponse($this->service->getRemediationQueue($this->userId, $limit, $lang));
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to load remediation queue'], 400);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function remediationCount(): DataResponse {
        try {
            return new DataResponse(['count' => $this->service->getRemediationCount($this->userId)]);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to load remediation count'], 400);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function initialize(int $poolId, ?int $courseId = null): DataResponse {
        try {
            $count = $this->service->initializePool($poolId, $this->userId, $courseId);
            return new DataResponse(['initialized' => $count]);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to initialize pool'], 400);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function due(?int $poolId = null, int $limit = 10, ?string $lang = null, ?int $courseId = null): DataResponse {
        if ($poolId === null) {
            return new DataResponse(['error' => 'poolId is required'], 400);
        }
        try {
            $limit = max(1, min($limit, 100));
            return new DataResponse($this->service->getDueQuestions($poolId, $this->userId, $limit, $lang, $courseId));
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to load due questions'], 400);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 120, period: 60)]
    public function answer(int $itemId, ?int $answerId = null, ?array $answerIds = null, ?string $answerText = null, ?array $pbqAnswers = null, ?int $rating = null, bool $preview = false, ?string $lang = null): DataResponse {
        if ($answerText !== null && mb_strlen($answerText) > 2000) {
            return new DataResponse(['error' => 'Answer text too long (max 2000 chars)'], 400);
        }
        try {
            return new DataResponse($this->service->answerQuestion($itemId, $answerId, $this->userId, $answerIds, $answerText, $pbqAnswers, $rating, $preview, $lang));
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to submit answer'], 400);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function stats(?int $poolId = null, ?int $courseId = null): DataResponse {
        if ($poolId === null) {
            return new DataResponse(['error' => 'poolId is required'], 400);
        }
        try {
            return new DataResponse($this->service->getStats($poolId, $this->userId, $courseId));
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

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function readiness(int $courseId): DataResponse {
        try {
            return new DataResponse($this->readinessService->getCourseReadiness($courseId, (string)$this->userId));
        } catch (\RuntimeException $e) {
            return new DataResponse(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to load readiness'], 400);
        }
    }
}
