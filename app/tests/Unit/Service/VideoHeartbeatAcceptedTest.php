<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\CourseVideo;
use OCA\Learning\Db\CourseVideoMapper;
use OCA\Learning\Db\VideoProgress;
use OCA\Learning\Db\VideoProgressMapper;
use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\VideoProgressService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * 2026-11 audit #111: a heartbeat less than 5 s after the last accepted one (a second tab) was
 * discarded but answered like a recorded one, and the client had already dropped those seconds.
 * recordHeartbeat now says whether it recorded the ping, and the controller passes it on.
 */
#[AllowMockObjectsWithoutExpectations]
class VideoHeartbeatAcceptedTest extends TestCase {
    private const NOW = 1_700_000_000;

    private function service(int $lastPing): VideoProgressService {
        $video = new CourseVideo();
        $video->setCourseId(7);
        $video->setDurationSeconds(600);
        $videos = $this->createMock(CourseVideoMapper::class);
        $videos->method('findById')->willReturn($video);

        $row = new VideoProgress();
        $row->setUserId('alice');
        $row->setContentId(42);
        $row->setIntervalsJson('[[0,30]]');
        $row->setCoveredPct(0.05);
        $row->setLastPingTs($lastPing);
        $progress = $this->createMock(VideoProgressMapper::class);
        $progress->method('findByUserAndContent')->willReturn($row);

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);

        $qb = $this->createMock(IQueryBuilder::class);
        foreach (['update', 'set', 'where', 'andWhere'] as $m) {
            $qb->method($m)->willReturnSelf();
        }
        $qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
        $qb->method('createNamedParameter')->willReturn('?');
        $qb->method('executeStatement')->willReturn(1);
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);

        return new VideoProgressService($progress, $videos, $time, $this->createMock(AuditService::class), $db);
    }

    public function testAPingTooSoonAfterTheLastIsReportedAsNotAccepted(): void {
        $this->assertFalse($this->service(self::NOW - 2)->recordHeartbeat('alice', 42, [30, 40]));
    }

    public function testAPlausiblePingIsReportedAsAccepted(): void {
        $this->assertTrue($this->service(self::NOW - 10)->recordHeartbeat('alice', 42, [30, 40]));
    }
}
