<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\XpService;
use OCA\Learning\Tests\Support\FakeCacheFactory;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * (Pre-existing) XpService::updateUserStats() creates a missing stats row. It runs inside
 * LeitnerService::answerQuestion's write transaction (incrementLeitnerXp() without a row, the
 * demotion path) and MissionService::claimMission's. A parallel request that created the row
 * first made the plain INSERT fail on UNIQUE(user_id); on PostgreSQL that aborts the caller's
 * transaction (25P02) and the retry UPDATE fails with it. The insert now reports the existing row
 * without a failing statement (insertIgnoreConflict = 0) and the recalculation goes onto it.
 */
#[AllowMockObjectsWithoutExpectations]
final class XpStatsRowRaceTest extends TestCase {
    private function db(): FakeDbConnection {
        return new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetch(['session_xp' => 30])),                  // session XP
            new FakeQueryBuilder(FakeResult::fromFetch(['total_correct' => 4, 'box5_count' => 1])), // Leitner XP
            new FakeQueryBuilder(FakeResult::fromFetch(['cnt' => 2])),                          // sessions
            new FakeQueryBuilder(FakeResult::fromFetch(['cnt' => 1])),                          // mastered
            self::updateBuilder(0),                                                             // update: no row yet
            self::updateBuilder(1),                                                             // the retry update
        ]);
    }

    /** updateRecalculatedStats() puts its parameters into SQL text, so they must be strings. */
    private static function updateBuilder(int $affected): FakeQueryBuilder {
        return new class(null, $affected) extends FakeQueryBuilder {
            public function createNamedParameter(mixed $value, mixed $type = null): mixed {
                return ':param';
            }
        };
    }

    private function service(FakeDbConnection $db): XpService {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(static fn (string $app, string $key, $default = '') => $default);
        return new XpService($db, $config, new FakeCacheFactory());
    }

    public function testARowCreatedInParallelIsUpdatedWithoutAFailingInsert(): void {
        $db = $this->db();
        $db->ignoreConflictResults = [0];   // the parallel request created the row first

        $this->service($db)->updateUserStats('alice');

        $this->assertCount(1, $db->ignoreConflictInserts);
        $this->assertSame('learning_user_stats', $db->ignoreConflictInserts[0]['table']);
        $this->assertSame('alice', $db->ignoreConflictInserts[0]['values']['user_id']);
        $this->assertSame(75, $db->ignoreConflictInserts[0]['values']['total_xp'], '30 session XP + 4 correct × 5 + 1 box-5 card × 25');
        $operations = array_map(static fn (FakeQueryBuilder $qb): ?string => $qb->operation, $db->issuedBuilders);
        $this->assertNotContains('insert', $operations, 'a plain INSERT that fails on the UNIQUE index aborts a PostgreSQL transaction');
        $updates = array_values(array_filter($db->issuedBuilders, static fn (FakeQueryBuilder $qb): bool => $qb->operation === 'update'));
        $this->assertCount(2, $updates, 'the recalculation goes onto the row the parallel request created');
        $this->assertSame('learning_user_stats', $updates[1]->table);
    }

    public function testAMissingRowIsCreatedOnce(): void {
        $db = $this->db();

        $this->service($db)->updateUserStats('alice');

        $this->assertCount(1, $db->ignoreConflictInserts);
        $updates = array_filter($db->issuedBuilders, static fn (FakeQueryBuilder $qb): bool => $qb->operation === 'update');
        $this->assertCount(1, $updates, 'the row was inserted: no retry');
    }
}
