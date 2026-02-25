<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\XpService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IRequest;

class UserStateController extends Controller {
    private BadgeService $badgeService;
    private StreakService $streakService;
    private XpService $xpService;
    private IDBConnection $db;
    private ICacheFactory $cacheFactory;
    private ?string $userId;

    public function __construct(
        $appName,
        IRequest $request,
        BadgeService $badgeService,
        StreakService $streakService,
        XpService $xpService,
        IDBConnection $db,
        ICacheFactory $cacheFactory,
        ?string $userId
    ) {
        parent::__construct($appName, $request);
        $this->badgeService = $badgeService;
        $this->streakService = $streakService;
        $this->xpService = $xpService;
        $this->db = $db;
        $this->cacheFactory = $cacheFactory;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function state(): DataResponse {
        $cache = $this->cacheFactory->createDistributed('learning');
        $cacheKey = 'user_state_' . $this->userId;

        $cached = $cache->get($cacheKey);
        if ($cached !== null) {
            return new DataResponse($cached);
        }

        $xp = $this->xpService->calculateXp($this->userId);
        $streak = $this->streakService->getStreak($this->userId);
        $badges = $this->badgeService->getUserBadges($this->userId);
        $progress = $this->badgeService->getBadgeProgress($this->userId);

        // Stats from denormalized table
        $qb = $this->db->getQueryBuilder();
        $qb->select('total_sessions', 'total_mastered')
           ->from('learning_user_stats')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)));
        $result = $qb->execute();
        $statsRow = $result->fetch();
        $result->closeCursor();

        $data = [
            'xp' => $xp,
            'streak' => $streak,
            'badges' => $badges,
            'progress' => $progress,
            'stats' => [
                'total_sessions' => (int)($statsRow['total_sessions'] ?? 0),
                'total_mastered' => (int)($statsRow['total_mastered'] ?? 0),
            ],
        ];

        $cache->set($cacheKey, $data, 30);

        return new DataResponse($data);
    }
}
