<?php
declare(strict_types=1);
namespace OCA\Learning\BackgroundJob;

use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\XpService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class ConsistencyCheckJob extends TimedJob {
    private IDBConnection $db;
    private XpService $xpService;
    private StreakService $streakService;
    private ICacheFactory $cacheFactory;
    private LoggerInterface $logger;

    private const BATCH_SIZE = 500;

    public function __construct(
        ITimeFactory $time,
        IDBConnection $db,
        XpService $xpService,
        StreakService $streakService,
        ICacheFactory $cacheFactory,
        LoggerInterface $logger
    ) {
        parent::__construct($time);
        $this->setInterval(24 * 3600); // Once per day
        $this->db = $db;
        $this->xpService = $xpService;
        $this->streakService = $streakService;
        $this->cacheFactory = $cacheFactory;
        $this->logger = $logger;
    }

    protected function run($argument): void {
        // Process oldest-updated users first — bounded batch ensures scalability,
        // ORDER BY ensures all users are eventually reconciled (R2 #4)
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_id')
           ->from('learning_user_stats')
           ->orderBy('updated_at', 'ASC')
           ->setMaxResults(self::BATCH_SIZE);
        $result = $qb->execute();

        $cache = $this->cacheFactory->createDistributed('learning');
        $count = 0;

        while ($row = $result->fetch()) {
            $userId = $row['user_id'];
            try {
                $this->reconcileUser($userId, $cache);
                $count++;
            } catch (\Throwable $e) {
                $this->logger->warning('ConsistencyCheckJob: failed to reconcile user {userId}: {error}', [
                    'userId' => $userId,
                    'error' => $e->getMessage(),
                    'app' => 'learning',
                ]);
            }
        }
        $result->closeCursor();

        $this->logger->info('ConsistencyCheckJob: reconciled {count} users', [
            'count' => $count,
            'app' => 'learning',
        ]);
    }

    private function reconcileUser(string $userId, $cache): void {
        // Recalculate XP, level, sessions, mastered from source of truth
        $this->xpService->updateUserStats($userId);

        // Recalculate streak from session history
        $streak = $this->streakService->getStreak($userId);

        $qb = $this->db->getQueryBuilder();
        $qb->update('learning_user_stats')
           ->set('current_streak', $qb->createNamedParameter($streak['current_streak']))
           ->set('longest_streak', $qb->createFunction(
               'CASE WHEN ' . $qb->createNamedParameter($streak['longest_streak']) .
               ' > longest_streak THEN ' . $qb->createNamedParameter($streak['longest_streak']) .
               ' ELSE longest_streak END'
           ))
           ->set('last_activity_date', $qb->createNamedParameter($streak['last_activity_date']))
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $qb->execute();

        $cache->remove('user_state_' . $userId);
    }
}
