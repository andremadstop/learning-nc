<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\BackgroundJob\ConsistencyCheckJob;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\XpService;
use OCA\Learning\Tests\Support\FakeCacheFactory;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #36 (pre-existing): with gamification off, StreakService::getStreak() returns
 * last_activity_date = null, and the nightly ConsistencyCheckJob wrote that null into
 * learning_user_stats. The at-risk signals read every learner as "never active", the progress
 * table's "Last active" column stayed empty and the classbook online dot was off. Session
 * completion did not touch the row either (incrementSessionXp returned early).
 */
/** A named parameter that can also be concatenated into SQL (the job's CASE expression). */
final class StringableParam {
    public function __construct(public mixed $value) {}
    public function __toString(): string {
        return ':param';
    }
}

final class StringableParamQueryBuilder extends FakeQueryBuilder {
    public function createNamedParameter(mixed $value, mixed $type = null): StringableParam {
        return new StringableParam($value);
    }
}

#[AllowMockObjectsWithoutExpectations]
class LastActivityWithoutGamificationTest extends TestCase {
    private function config(bool $gamification): IConfig {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            static fn (string $app, string $key, $default = '') => $key === 'gamification_enabled' ? ($gamification ? 'yes' : 'no') : $default
        );
        return $config;
    }

    private function qb(?FakeResult $result = null): StringableParamQueryBuilder {
        return new StringableParamQueryBuilder($result);
    }

    /** @param list<?FakeResult> $results one builder per query, in order; the job's update gets the last */
    private function db(array $results): FakeDbConnection {
        $builders = array_map(fn (?FakeResult $r): StringableParamQueryBuilder => $this->qb($r), $results);
        $builders[] = $this->qb();
        $builders[] = $this->qb();
        return new FakeDbConnection($builders);
    }

    /** @return mixed the last_activity_date the job wrote into learning_user_stats */
    private function runJob(FakeDbConnection $db, bool $gamification): mixed {
        $job = new ConsistencyCheckJob(
            $this->createMock(ITimeFactory::class),
            $db,
            $this->createMock(XpService::class),
            new StreakService($db, $this->config($gamification)),
            new FakeCacheFactory(),
            $this->createMock(LoggerInterface::class)
        );
        $method = new \ReflectionMethod($job, 'run');
        $method->setAccessible(true);
        $method->invoke($job, null);

        $updates = array_values(array_filter(
            $db->issuedBuilders,
            static fn (FakeQueryBuilder $qb): bool => $qb->operation === 'update' && $qb->table === 'learning_user_stats' && isset($qb->setCalls['last_activity_date'])
        ));
        $this->assertCount(1, $updates, 'one stats update per user');
        $written = $updates[0]->setCalls['last_activity_date'];
        return $written instanceof StringableParam ? $written->value : $written['value'];
    }

    public function testNightlyReconciliationKeepsTheLastActivityWithGamificationOff(): void {
        $db = $this->db([
            new FakeResult([['user_id' => 'learner']]),
            // MAX(completed_at) of the learner's sessions: 2026-03-04 10:00 UTC
            FakeResult::fromFetchOne(gmmktime(10, 0, 0, 3, 4, 2026)),
        ]);

        $this->assertSame('2026-03-04', $this->runJob($db, false));
    }

    public function testNightlyReconciliationWritesNullOnlyWithoutAnySession(): void {
        $db = $this->db([
            new FakeResult([['user_id' => 'learner']]),
            FakeResult::fromFetchOne(null),
        ]);

        $this->assertNull($this->runJob($db, false));
    }

    public function testNightlyReconciliationWithGamificationOnStillTakesTheStreakDay(): void {
        $day = gmmktime(9, 0, 0, 5, 6, 2026);
        $db = $this->db([
            new FakeResult([['user_id' => 'learner']]),
            // activityDates(): the completed sessions, newest first; then the freeze state
            new FakeResult([['completed_at' => $day]]),
            new FakeResult(),
        ]);

        $this->assertSame('2026-05-06', $this->runJob($db, true));
    }

    public function testCompletedSessionUpdatesLastActivityAndCountWithGamificationOff(): void {
        $db = new FakeDbConnection([new FakeQueryBuilder(null, 1)]);  // the stats row exists
        $xp = new XpService($db, $this->config(false), new FakeCacheFactory());

        $xp->incrementSessionXp('learner', 0, 0);

        $this->assertCount(1, $db->issuedBuilders);
        $update = $db->issuedBuilders[0];
        $this->assertSame('update', $update->operation);
        $this->assertSame('learning_user_stats', $update->table);
        $this->assertSame(gmdate('Y-m-d'), $update->setCalls['last_activity_date']['value']);
        $this->assertSame('total_sessions + 1', $update->setCalls['total_sessions']);
        $this->assertArrayNotHasKey('total_xp', $update->setCalls, 'no XP with gamification off');
        $this->assertArrayNotHasKey('current_streak', $update->setCalls, 'no streak with gamification off');
    }

    public function testCompletedSessionCreatesTheStatsRowWithoutXpWhenThereIsNone(): void {
        $db = new FakeDbConnection([
            new FakeQueryBuilder(null, 0),                         // update: no row yet
            new FakeQueryBuilder(new FakeResult([['cnt' => 3]])),  // completed sessions so far
            new FakeQueryBuilder(null, 1),                         // insert
        ]);
        $xp = new XpService($db, $this->config(false), new FakeCacheFactory());

        $xp->incrementSessionXp('learner', 0, 0);

        $inserts = array_values(array_filter($db->issuedBuilders, static fn (FakeQueryBuilder $qb): bool => $qb->operation === 'insert'));
        $this->assertCount(1, $inserts, 'a learner without a stats row gets one');
        $values = array_map(static fn ($param) => $param['value'], $inserts[0]->insertValues);
        $this->assertSame('learning_user_stats', $inserts[0]->table);
        $this->assertSame(gmdate('Y-m-d'), $values['last_activity_date']);
        $this->assertSame(3, $values['total_sessions']);
        $this->assertSame(0, $values['total_xp'], 'no XP with gamification off');
        $this->assertSame(1, $values['current_level']);
    }
}
