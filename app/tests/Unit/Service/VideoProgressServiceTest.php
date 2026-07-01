<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\CourseVideoMapper;
use OCA\Learning\Db\VideoProgressMapper;
use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\ComplianceEventTypes;
use OCA\Learning\Service\VideoProgressService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Security contract for VideoProgressService (Phase 162, VIDEO-02/06/07).
 *
 * Completion is decided ONLY by server-merged watch intervals reaching 95% — client flags are
 * never trusted. Heartbeats less than 5s apart are silently discarded. At completion the transient
 * segments (intervals_json, covered_pct, last_ping_ts) are cleared — only (user_id, content_id,
 * completed_at) persist (DSGVO Art.5). The course.video.completed compliance event carries ONLY
 * {course_id, content_id} — never covered_pct, intervals, or watch timestamps — and is emitted
 * EXACTLY once (guarded by an atomic `completed_at IS NULL` UPDATE).
 *
 * The pure decision methods (mergeIntervals, coveredPct, decideComplete, isPlausiblePing,
 * computeCompletionState, isDurationValidForGate, computeHeartbeatTransition) are tested DB-free.
 * markComplete/markDocumentRead are tested with a fluent QueryBuilder stub whose executeStatement()
 * return value drives the exactly-once guard.
 */
class VideoProgressServiceTest extends TestCase {

    private const USER = 'alice';
    private const CONTENT_ID = 42;
    private const COURSE_ID = 7;
    private const NOW = 1_700_000_000;

    private function timeAt(int $now = self::NOW): ITimeFactory {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn($now);
        return $time;
    }

    /**
     * A fluent IQueryBuilder stub: all builder setters return self, expr() returns an inert
     * expression builder, executeStatement() returns the given affected-row counts in sequence.
     */
    private function fluentQb(int ...$affected): IQueryBuilder {
        $expr = $this->createMock(IExpressionBuilder::class);
        $qb = $this->createMock(IQueryBuilder::class);
        foreach (['update', 'insert', 'delete', 'select', 'from', 'set', 'values', 'where', 'andWhere', 'orWhere', 'orderBy', 'setMaxResults'] as $m) {
            $qb->method($m)->willReturnSelf();
        }
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturn('?');
        if ($affected === []) {
            $affected = [1];
        }
        $qb->method('executeStatement')->willReturnOnConsecutiveCalls(...$affected);
        return $qb;
    }

    private function makeService(
        ?AuditService $audit = null,
        ?IDBConnection $db = null,
        ?ITimeFactory $time = null,
        ?VideoProgressMapper $progressMapper = null,
        ?CourseVideoMapper $videoMapper = null
    ): VideoProgressService {
        return new VideoProgressService(
            $progressMapper ?? $this->createMock(VideoProgressMapper::class),
            $videoMapper ?? $this->createMock(CourseVideoMapper::class),
            $time ?? $this->timeAt(),
            $audit ?? $this->createMock(AuditService::class),
            $db ?? $this->createMock(IDBConnection::class),
        );
    }

    // ---- mergeIntervals -----------------------------------------------------

    public function testMergeOverlappingIntervals(): void {
        $svc = $this->makeService();
        $this->assertEquals([[0, 15]], $svc->mergeIntervals([[0, 10]], [5, 15]));
    }

    public function testMergeDisjointIntervalsStaySeparate(): void {
        $svc = $this->makeService();
        $this->assertEquals([[0, 10], [20, 30]], $svc->mergeIntervals([[0, 10]], [20, 30]));
    }

    public function testMergeAdjacentTouchingIntervals(): void {
        $svc = $this->makeService();
        // [0,10] and [10,20] touch → single interval
        $this->assertEquals([[0, 20]], $svc->mergeIntervals([[0, 10]], [10, 20]));
    }

    // ---- coveredPct ---------------------------------------------------------

    public function testCoveredPctFullReachesThreshold(): void {
        $svc = $this->makeService();
        $this->assertEqualsWithDelta(0.95, $svc->coveredPct([[0, 95]], 100), 1e-9);
    }

    public function testCoveredPctSeekGapDefeatsCompletion(): void {
        $svc = $this->makeService();
        // 50 + 39 = 89 covered of 100 → below threshold (a seek gap cannot be faked away)
        $pct = $svc->coveredPct([[0, 50], [60, 99]], 100);
        $this->assertLessThan(0.95, $pct);
    }

    public function testCoveredPctNullDurationIsUncomputable(): void {
        $svc = $this->makeService();
        $this->assertNull($svc->coveredPct([[0, 100]], null));
        $this->assertNull($svc->coveredPct([[0, 100]], 0));
    }

    // ---- decideComplete -----------------------------------------------------

    public function testDecideCompleteThreshold(): void {
        $svc = $this->makeService();
        $this->assertTrue($svc->decideComplete(0.95));
        $this->assertFalse($svc->decideComplete(0.9499));
        $this->assertFalse($svc->decideComplete(0.94));
        $this->assertFalse($svc->decideComplete(null));
    }

    // ---- isPlausiblePing ----------------------------------------------------

    public function testPingTooCloseIsImplausible(): void {
        $svc = $this->makeService();
        $this->assertFalse($svc->isPlausiblePing(1000, 1003)); // 3s < 5s
    }

    public function testPingFarEnoughIsPlausible(): void {
        $svc = $this->makeService();
        $this->assertTrue($svc->isPlausiblePing(1000, 1006)); // 6s >= 5s
    }

    public function testFirstPingNullLastIsAlwaysPlausible(): void {
        // M1: last_ping_ts null (no prior ping) must never be rejected.
        $svc = $this->makeService();
        $this->assertTrue($svc->isPlausiblePing(null, self::NOW));
    }

    // ---- computeHeartbeatTransition (the recordHeartbeat decision core) ------

    public function testHeartbeatImplausiblePingLeavesCoverageUnchanged(): void {
        // Two pings 3s apart via the heartbeat path: the second is discarded silently (returns null),
        // so covered_pct never advances and no exception is thrown.
        $svc = $this->makeService();

        $first = $svc->computeHeartbeatTransition([], null, [0, 6], 1000, 100);
        $this->assertNotNull($first);
        $this->assertEqualsWithDelta(0.06, $first['covered_pct'], 1e-9);

        // second ping only 3s later → implausible → discard
        $second = $svc->computeHeartbeatTransition($first['intervals'], 1000, [6, 40], 1003, 100);
        $this->assertNull($second, 'implausible <5s ping must be discarded');
    }

    public function testHeartbeatPlausiblePingMergesAndCanComplete(): void {
        $svc = $this->makeService();
        $t = $svc->computeHeartbeatTransition([[0, 50]], 1000, [40, 100], 1010, 100);
        $this->assertNotNull($t);
        $this->assertEquals([[0, 100]], $t['intervals']);
        $this->assertEqualsWithDelta(1.0, $t['covered_pct'], 1e-9);
        $this->assertTrue($t['complete']);
        $this->assertSame(1010, $t['last_ping_ts']);
    }

    // ---- computeCompletionState (DSGVO transient cleanup, VIDEO-06) ----------

    public function testComputeCompletionStateClearsTransientFields(): void {
        $svc = $this->makeService();
        $state = $svc->computeCompletionState(self::NOW);
        $this->assertSame(self::NOW, $state['completed_at']);
        $this->assertNull($state['intervals_json']);
        $this->assertNull($state['covered_pct']);
        $this->assertNull($state['last_ping_ts']);
    }

    // ---- duration integrity / document exemption (W1) -----------------------

    public function testVideoSourceWithNullOrZeroDurationIsInvalidGate(): void {
        $svc = $this->makeService();
        $this->assertFalse($svc->isDurationValidForGate('nc-files', null));
        $this->assertFalse($svc->isDurationValidForGate('vimeo', 0));
        $this->assertFalse($svc->isDurationValidForGate('youtube-nocookie', null));
        $this->assertTrue($svc->isDurationValidForGate('nc-files', 120));
    }

    public function testDocumentSourceIsExemptFromDurationCheck(): void {
        // A document legitimately has null duration and must never be blocked for it.
        $svc = $this->makeService();
        $this->assertTrue($svc->isDurationValidForGate('document', null));
        $this->assertTrue($svc->isDurationValidForGate('document', 0));
    }

    // ---- markComplete: audit-payload minimalism + exactly-once (advisor #1/#3) --

    public function testMarkCompleteEmitsMinimalAuditPayloadExactlyOnce(): void {
        $db = $this->createMock(IDBConnection::class);
        // First guarded UPDATE affects the row (1); a racing second call affects 0.
        $db->method('getQueryBuilder')->willReturn($this->fluentQb(1, 0));

        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->once())
            ->method('logComplianceEvent')
            ->with(
                $this->equalTo(ComplianceEventTypes::VIDEO_COMPLETED),
                $this->equalTo(self::USER),
                $this->callback(function ($context): bool {
                    // MUST be exactly {course_id, content_id} — no covered_pct, intervals, timestamps.
                    return $context === ['course_id' => self::COURSE_ID, 'content_id' => self::CONTENT_ID];
                })
            );

        $svc = $this->makeService(audit: $audit, db: $db);
        // Two crossings of 95% (two tabs) → only the winning UPDATE (affected=1) emits.
        $svc->markComplete(self::USER, self::CONTENT_ID, self::COURSE_ID);
        $svc->markComplete(self::USER, self::CONTENT_ID, self::COURSE_ID);
    }

    // ---- markDocumentRead: completion without intervals (VIDEO-07) ----------

    public function testMarkDocumentReadCompletesWithMinimalPayload(): void {
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($this->fluentQb(1)); // INSERT succeeds

        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->once())
            ->method('logComplianceEvent')
            ->with(
                $this->equalTo(ComplianceEventTypes::VIDEO_COMPLETED),
                $this->equalTo(self::USER),
                $this->callback(function ($context): bool {
                    return $context === ['course_id' => self::COURSE_ID, 'content_id' => self::CONTENT_ID];
                })
            );

        $svc = $this->makeService(audit: $audit, db: $db);
        $svc->markDocumentRead(self::USER, self::CONTENT_ID, self::COURSE_ID);
    }
}
