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
    #[UserRateLimit(limit: 10, period: 60)]
    public function updateSettings(int $daily_goal): DataResponse {
        $daily_goal = max(5, min(200, $daily_goal));

        $qb = $this->db->getQueryBuilder();
        $qb->update('learning_user_stats')
           ->set('daily_goal', $qb->createNamedParameter($daily_goal))
           ->set('updated_at', $qb->createNamedParameter(time()))
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)));
        $affected = $qb->execute();

        if ($affected === 0) {
            // No stats row yet — create one with defaults
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->insert('learning_user_stats')
                   ->values([
                       'user_id' => $qb->createNamedParameter($this->userId),
                       'total_xp' => $qb->createNamedParameter(0),
                       'current_level' => $qb->createNamedParameter(1),
                       'current_streak' => $qb->createNamedParameter(0),
                       'longest_streak' => $qb->createNamedParameter(0),
                       'total_sessions' => $qb->createNamedParameter(0),
                       'total_mastered' => $qb->createNamedParameter(0),
                       'daily_goal' => $qb->createNamedParameter($daily_goal),
                       'updated_at' => $qb->createNamedParameter(time()),
                   ]);
                $qb->execute();
            } catch (\OCP\DB\Exception $e) {
                if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                    $qb = $this->db->getQueryBuilder();
                    $qb->update('learning_user_stats')
                       ->set('daily_goal', $qb->createNamedParameter($daily_goal))
                       ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)));
                    $qb->execute();
                } else {
                    throw $e;
                }
            }
        }

        // Invalidate cache
        $this->cacheFactory->createDistributed('learning')->remove('user_state_' . $this->userId);

        return new DataResponse(['daily_goal' => $daily_goal]);
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
        $qb->select('total_sessions', 'total_mastered', 'daily_goal')
           ->from('learning_user_stats')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)));
        $result = $qb->execute();
        $statsRow = $result->fetch();
        $result->closeCursor();

        $dailyGoal = (int)($statsRow['daily_goal'] ?? 20);

        // Cards reviewed today (leitner items updated today)
        $todayStart = strtotime('today midnight');
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*) as cnt'))
           ->from('learning_leitner_items')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)))
           ->andWhere($qb->expr()->gte('last_reviewed', $qb->createNamedParameter($todayStart)));
        $result = $qb->execute();
        $cardsToday = (int)$result->fetch()['cnt'];
        $result->closeCursor();

        // Sessions today
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*) as cnt'))
           ->from('learning_sessions')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($this->userId)))
           ->andWhere($qb->expr()->isNotNull('completed_at'))
           ->andWhere($qb->expr()->gte('completed_at', $qb->createNamedParameter($todayStart)));
        $result = $qb->execute();
        $sessionsToday = (int)$result->fetch()['cnt'];
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
            'daily_progress' => [
                'cards_reviewed_today' => $cardsToday,
                'daily_goal' => $dailyGoal,
                'goal_reached' => $cardsToday >= $dailyGoal,
                'sessions_today' => $sessionsToday,
            ],
        ];

        $cache->set($cacheKey, $data, 30);

        return new DataResponse($data);
    }
}
