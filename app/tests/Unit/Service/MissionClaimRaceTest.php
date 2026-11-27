<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\MissionService;
use OCA\Learning\Service\XpService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * (Pre-existing) Two requests that claim the same mission at once both pass the "already
 * claimed" check; the second INSERT then hits UNIQUE(user_id, mission_key, mission_date).
 * Nextcloud reports that as OCP\DB\Exception, never as the Doctrine class the service caught, so
 * the second request fell through to the generic catch: the controller answered 400 "Failed to
 * claim mission" instead of "Mission already claimed". Found while emptying the PHPStan baseline
 * (27.11.2026).
 */
#[AllowMockObjectsWithoutExpectations]
final class MissionClaimRaceTest extends TestCase {
    private function insertThatFails(int $reason): FakeQueryBuilder {
        return new class($reason) extends FakeQueryBuilder {
            public function __construct(private int $reason) {
                parent::__construct();
            }
            public function executeStatement(): int {
                throw new \OCP\DB\Exception($this->reason, 'insert failed');
            }
        };
    }

    /** The mission is done and not yet claimed, as far as the checks before the insert can see. */
    private function db(FakeQueryBuilder $insert): FakeDbConnection {
        return new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetch(['cnt' => 1])), // sessions today
            new FakeQueryBuilder(FakeResult::fromFetch(['cnt' => 0])), // Leitner cards today
            new FakeQueryBuilder(FakeResult::fromFetch(false)),        // high score session
            new FakeQueryBuilder(FakeResult::fromFetchAll([])),        // claims today: none yet
            $insert,
        ]);
    }

    private function service(FakeDbConnection $db, XpService $xp): MissionService {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturn('yes');
        return new MissionService($db, $config, $xp);
    }

    public function testTheLosingRequestOfARaceIsTold_AlreadyClaimed(): void {
        $db = $this->db($this->insertThatFails(\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION));
        $xp = $this->createMock(XpService::class);
        $xp->expects($this->never())->method('incrementLeitnerXp');

        try {
            $this->service($db, $xp)->claimMission('lena', 'daily_sessions_1');
            $this->fail('the second claim must not succeed');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Mission already claimed', $e->getMessage());
        }
        $this->assertSame(1, $db->rollBackCalls);
        $this->assertSame(0, $db->commitCalls);
    }

    public function testAnyOtherDatabaseErrorStaysAnError(): void {
        $db = $this->db($this->insertThatFails(\OCP\DB\Exception::REASON_DEADLOCK));
        $xp = $this->createMock(XpService::class);

        $this->expectException(\OCP\DB\Exception::class);
        try {
            $this->service($db, $xp)->claimMission('lena', 'daily_sessions_1');
        } finally {
            $this->assertSame(1, $db->rollBackCalls);
        }
    }
}
