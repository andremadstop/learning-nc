<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\BackgroundJob\ConsistencyCheckJob;
use OCA\Learning\Service\PoolService;
use OCA\Learning\Service\XpService;
use OCP\AppFramework\App;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * 2026-11 audit, high finding: the nightly ConsistencyCheckJob recalculated total_xp from completed
 * sessions and Leitner counters only and wrote it over the stored value. Every XP source the
 * recalculation does not know (per-question training XP, streak multipliers, rating-based Leitner
 * XP, missions, goals, gameshow) was taken away again each night, and the level could drop.
 * The recalculation may raise XP and level, never lower them; session and mastered counts are
 * still taken from the recalculation.
 * Runs on PostgreSQL and MariaDB (scripts/nc-integration.sh).
 */
final class ConsistencyCheckXpTest extends TestCase {
    private const OWNER = 'h3-xp-owner';
    private const KEPT = 'h3-xp-kept';
    private const KEPT_LEVEL = 'h3-xp-kept-level';
    private const RAISED = 'h3-xp-raised';

    private ContainerInterface $container;
    private IDBConnection $db;
    private int $poolId;

    protected function setUp(): void {
        $this->container = (new App('learning'))->getContainer();
        $this->db = Server::get(IDBConnection::class);
        $this->cleanUp();
        $this->poolId = $this->container->get(PoolService::class)->create('H3 XP pool', 'audit h3', self::OWNER)->getId();
    }

    protected function tearDown(): void {
        $this->cleanUp();
    }

    public function testNightlyReconciliationNeverLowersXpOrLevelButRaisesThemAndRefreshesCounts(): void {
        $xp = $this->container->get(XpService::class);

        // 10 of 10 correct in training: (10 + 10 * 5) * 1.5 = 90 XP from the recalculation,
        // while the learner really earned 140 (per-question XP the recalculation does not see).
        $this->storeStats(self::KEPT, 140, $xp->getLevelFromXp(140)['level'], 7, 3);
        $this->completedSession(self::KEPT, 10, 10);

        // Same recalculation (90 XP = level 1) against a stored level 3.
        $this->assertSame(3, $xp->getLevelFromXp(300)['level'], 'setup: 300 XP is level 3');
        $this->storeStats(self::KEPT_LEVEL, 300, 3, 0, 0);
        $this->completedSession(self::KEPT_LEVEL, 10, 10);

        // 20 of 20 correct: (10 + 20 * 5) * 1.5 = 165 XP = level 2, stored only 20 XP / level 1.
        $this->assertSame(2, $xp->getLevelFromXp(165)['level'], 'setup: 165 XP is level 2');
        $this->storeStats(self::RAISED, 20, 1, 0, 0);
        $this->completedSession(self::RAISED, 20, 20);

        $job = $this->container->get(ConsistencyCheckJob::class);
        $run = new \ReflectionMethod($job, 'run');
        $run->setAccessible(true);
        $run->invoke($job, null);

        $kept = $this->stats(self::KEPT);
        $this->assertSame(140, $kept['total_xp'], 'stored XP above the recalculation is kept');
        $this->assertSame(1, $kept['total_sessions'], 'session count still comes from the recalculation');
        $this->assertSame(0, $kept['total_mastered'], 'mastered count still comes from the recalculation');

        $keptLevel = $this->stats(self::KEPT_LEVEL);
        $this->assertSame(300, $keptLevel['total_xp']);
        $this->assertSame(3, $keptLevel['current_level'], 'the level never drops');

        $raised = $this->stats(self::RAISED);
        $this->assertSame(165, $raised['total_xp'], 'a higher recalculation is taken over');
        $this->assertSame(2, $raised['current_level'], 'and raises the level');
        $this->assertSame(1, $raised['total_sessions']);
    }

    private function storeStats(string $uid, int $totalXp, int $level, int $sessions, int $mastered): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_user_stats')->values([
            'user_id' => $qb->createNamedParameter($uid),
            'total_xp' => $qb->createNamedParameter($totalXp, IQueryBuilder::PARAM_INT),
            'current_level' => $qb->createNamedParameter($level, IQueryBuilder::PARAM_INT),
            'current_streak' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
            'longest_streak' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
            'total_sessions' => $qb->createNamedParameter($sessions, IQueryBuilder::PARAM_INT),
            'total_mastered' => $qb->createNamedParameter($mastered, IQueryBuilder::PARAM_INT),
            // oldest first: the job takes the 500 least recently updated rows
            'updated_at' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
        ])->executeStatement();
    }

    private function completedSession(string $uid, int $total, int $correct): void {
        $now = time();
        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_sessions')->values([
            'pool_id' => $qb->createNamedParameter($this->poolId, IQueryBuilder::PARAM_INT),
            'user_id' => $qb->createNamedParameter($uid),
            'mode' => $qb->createNamedParameter('training'),
            'started_at' => $qb->createNamedParameter($now - 600, IQueryBuilder::PARAM_INT),
            'completed_at' => $qb->createNamedParameter($now - 60, IQueryBuilder::PARAM_INT),
            'total_questions' => $qb->createNamedParameter($total, IQueryBuilder::PARAM_INT),
            'correct_answers' => $qb->createNamedParameter($correct, IQueryBuilder::PARAM_INT),
        ])->executeStatement();
    }

    /** @return array{total_xp:int,current_level:int,total_sessions:int,total_mastered:int} */
    private function stats(string $uid): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('total_xp', 'current_level', 'total_sessions', 'total_mastered')
            ->from('learning_user_stats')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)));
        $r = $qb->executeQuery();
        $row = $r->fetch();
        $r->closeCursor();
        $this->assertIsArray($row, "stats row of $uid exists");
        return [
            'total_xp' => (int)$row['total_xp'],
            'current_level' => (int)$row['current_level'],
            'total_sessions' => (int)$row['total_sessions'],
            'total_mastered' => (int)$row['total_mastered'],
        ];
    }

    private function cleanUp(): void {
        $uids = [self::KEPT, self::KEPT_LEVEL, self::RAISED];
        foreach (['learning_sessions', 'learning_user_stats'] as $table) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($table)
                ->where($qb->expr()->in('user_id', $qb->createNamedParameter($uids, IQueryBuilder::PARAM_STR_ARRAY)))
                ->executeStatement();
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete('learning_pools')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::OWNER)))
            ->executeStatement();
    }
}
