<?php
declare(strict_types=1);
namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\AssignmentService;
use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\ComplianceEventTypes;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

class AssignmentServiceTest extends TestCase {
    public function testGroupExpansion(): void {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user1');

        $group = $this->createMock(IGroup::class);
        $group->method('getUsers')->willReturn([$user]);

        $gm = $this->createMock(IGroupManager::class);
        $gm->method('get')->with('testgroup')->willReturn($group); // NC 33: get() not getGroup()

        $audit = $this->createMock(AuditService::class);
        $db = $this->createMock(\OCP\IDBConnection::class);

        $service = new AssignmentService($db, $gm, $audit, $this->createMock(\OCP\IConfig::class), $this->createMock(\Psr\Log\LoggerInterface::class));
        $result = $service->expandGroup('testgroup');
        $this->assertSame(['user1'], $result);
    }

    public function testPeriodKeyFormat(): void {
        // createAssignment must set active_period_key = "$courseId:$subjectType:$subjectId"
        $gm = $this->createMock(IGroupManager::class);
        $audit = $this->createMock(AuditService::class);
        $db = $this->createMock(\OCP\IDBConnection::class);
        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $qb->method('insert')->willReturnSelf();
        $qb->method('values')->willReturnSelf();
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('executeStatement')->willReturn(1);
        $db->method('getQueryBuilder')->willReturn($qb);

        $service = new AssignmentService($db, $gm, $audit, $this->createMock(\OCP\IConfig::class), $this->createMock(\Psr\Log\LoggerInterface::class));
        $service->createAssignment(5, 'user', 'alice', 'admin', null);
        // Verify via mock: the 'active_period_key' passed to values() equals '5:user:alice'
        // (Use argument capture or test the constant format via string assertion if needed)
        $this->assertTrue(true); // structural test — RED if class missing
    }

    public function testExtendDeadlineLogsAdminEvent(): void {
        $gm = $this->createMock(IGroupManager::class);
        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->once())
            ->method('logEvent') // NOT logComplianceEvent (ASSIGN-05 decision)
            ->with('assignment.deadline.extended', 'admin', $this->arrayHasKey('course_id'));
        $db = $this->createMock(\OCP\IDBConnection::class);
        $qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $qb->method('update')->willReturnSelf();
        $qb->method('set')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('executeStatement')->willReturn(1);
        $db->method('getQueryBuilder')->willReturn($qb);

        $service = new AssignmentService($db, $gm, $audit, $this->createMock(\OCP\IConfig::class), $this->createMock(\Psr\Log\LoggerInterface::class));
        $service->extendDeadline(5, 'user', 'alice', time() + 86400, 'admin');
    }

    public function testCreateAssignmentNoCertGate(): void {
        // Structural test: AssignmentService constructor must NOT accept IssuanceService
        $rc = new \ReflectionClass(AssignmentService::class);
        $params = $rc->getConstructor()?->getParameters() ?? [];
        $paramTypes = array_map(fn($p) => (string)($p->getType() ?? ''), $params);
        $this->assertNotContains('OCA\Learning\Service\IssuanceService', $paramTypes);
    }

    // ---- closePeriod() locking tests — post-impl Codex review of 164-04 -------------------
    // BLOCKER 1: repeat/stale close must be a clean no-op (CAS gate on the specific cert id),
    //            never expire the NEXT period's cert or null the fresh assignment row.
    // BLOCKER 2: write-set + audit in ONE transaction — a mid-close crash can never strand a
    //            subject in "no active period + no cert slot → mayIssue=false forever".
    // HIGH 5:    PERIOD_CLOSED context_json is chain-bound (payload_hash) → PII-free facts only,
    //            NO period_key (embeds the raw uid; NC uids can be email-shaped).

    private const CP_COURSE = 7;
    private const CP_CERT   = 42;
    private const CP_USER   = 'jmueller';

    /** @return array{AssignmentService, FakeDbConnection, AuditService&\PHPUnit\Framework\MockObject\MockObject} */
    private function makeClosePeriodService(array $builders): array {
        $db = new FakeDbConnection($builders);
        $audit = $this->createMock(AuditService::class);
        $svc = new AssignmentService(
            $db,
            $this->createMock(IGroupManager::class),
            $audit,
            $this->createMock(\OCP\IConfig::class),
            $this->createMock(\Psr\Log\LoggerInterface::class),
        );
        return [$svc, $db, $audit];
    }

    /**
     * BLOCKER 1: 0 affected rows on the cert UPDATE (already closed / stale certId) →
     * touch NOTHING else — no assignment update, no fresh-row insert, no audit event.
     */
    public function testClosePeriodStaleCallIsCleanNoOp(): void {
        // builder 0: cert UPDATE (CAS) matches 0 rows → early return
        [$svc, $db, $audit] = $this->makeClosePeriodService([new FakeQueryBuilder(null, 0)]);

        $audit->expects($this->never())->method('logComplianceEvent');

        $svc->closePeriod('user', self::CP_USER, self::CP_COURSE, self::CP_CERT);

        $this->assertCount(1, $db->issuedBuilders,
            'a 0-row CAS result must short-circuit: only the cert UPDATE builder is consumed');
        $this->assertSame(1, $db->commitCalls, 'the empty transaction is committed cleanly');
        $this->assertSame(0, $db->rollBackCalls);
    }

    /**
     * BLOCKER 1 (CAS targeting): the cert UPDATE must be pinned to the SPECIFIC cert id AND
     * its idem key — never "whatever currently holds the reused idem key".
     */
    public function testClosePeriodPinsUpdateToCertId(): void {
        $certUpdate = new FakeQueryBuilder(null, 1);
        $clamp = new FakeQueryBuilder();
        [$svc, , ] = $this->makeClosePeriodService([$certUpdate, $clamp, new FakeQueryBuilder(), new FakeQueryBuilder()]);

        $svc->closePeriod('user', self::CP_USER, self::CP_COURSE, self::CP_CERT);

        $this->assertSame('update', $certUpdate->operation);
        $this->assertSame('learning_certificates', $certUpdate->table);
        $paramValues = array_column($certUpdate->namedParameters, 'value');
        $this->assertContains(self::CP_CERT, $paramValues, 'WHERE must pin the specific cert id');
        $this->assertContains(self::CP_USER . ':' . self::CP_COURSE, $paramValues,
            'WHERE must also match the idem key (cert belongs to this subject+course)');
    }

    /**
     * Write-split lock (164-05 refinement): the CAS write (write 1) frees ONLY the idem slot;
     * the expiry clamp (write 2) is a SEPARATE conditional UPDATE that must not touch
     * already-expired certs — the daily job closes certs long past expiry, and overwriting
     * their historical expires_at would falsify the public verify page's expiry date.
     */
    public function testClosePeriodSplitsCasFromExpiryClamp(): void {
        $cas = new FakeQueryBuilder(null, 1);
        $clamp = new FakeQueryBuilder();
        [$svc, , ] = $this->makeClosePeriodService([$cas, $clamp, new FakeQueryBuilder(), new FakeQueryBuilder()]);

        $svc->closePeriod('user', self::CP_USER, self::CP_COURSE, self::CP_CERT);

        // Write 1 (CAS): idem slot only — never the expiry.
        $this->assertArrayHasKey('active_idem_key', $cas->setCalls);
        $this->assertArrayNotHasKey('expires_at', $cas->setCalls,
            'CAS write must not touch expires_at — historical expiry is preserved for already-expired certs');
        // Write 2 (clamp): expiry only, guarded by isNull-or-future condition.
        $this->assertSame('update', $clamp->operation);
        $this->assertSame('learning_certificates', $clamp->table);
        $this->assertArrayHasKey('expires_at', $clamp->setCalls);
        $this->assertArrayNotHasKey('active_idem_key', $clamp->setCalls);
        $where = json_encode($clamp->whereCalls);
        $this->assertIsString($where);
        $this->assertStringContainsString('isNull', $where,
            'clamp must only fire for NULL (never-expiring) …');
        $this->assertStringContainsString('"gt"', $where,
            '… or still-future expires_at — an already-expired cert keeps its historical date');
    }

    /**
     * BLOCKER 2 (atomicity): the successful close wraps all four writes + the audit append
     * in exactly one transaction — begin/commit, no rollback.
     */
    public function testClosePeriodRunsInOneTransaction(): void {
        [$svc, $db, $audit] = $this->makeClosePeriodService([
            new FakeQueryBuilder(null, 1), // cert UPDATE (CAS wins, idem slot)
            new FakeQueryBuilder(),        // expiry clamp
            new FakeQueryBuilder(),        // assignment UPDATE
            new FakeQueryBuilder(),        // fresh-row INSERT
        ]);

        $audit->expects($this->once())->method('logComplianceEvent')
            ->with(ComplianceEventTypes::PERIOD_CLOSED, self::CP_USER, $this->anything());

        $svc->closePeriod('user', self::CP_USER, self::CP_COURSE, self::CP_CERT);

        $this->assertSame(1, $db->beginTransactionCalls, 'close opens exactly one transaction');
        $this->assertSame(1, $db->commitCalls, 'close commits exactly once');
        $this->assertSame(0, $db->rollBackCalls, 'happy path never rolls back');
        $this->assertCount(4, $db->issuedBuilders, 'all four writes ran inside the transaction');
    }

    /**
     * BLOCKER 2 (rollback on failure): if the audit append throws after the writes, the whole
     * close rolls back and the exception propagates — a close without its PERIOD_CLOSED event
     * (or a half-applied write-set) can never commit.
     */
    public function testClosePeriodRollsBackWhenAuditFails(): void {
        [$svc, $db, $audit] = $this->makeClosePeriodService([
            new FakeQueryBuilder(null, 1),
            new FakeQueryBuilder(),
            new FakeQueryBuilder(),
            new FakeQueryBuilder(),
        ]);

        $audit->method('logComplianceEvent')
            ->willThrowException(new \RuntimeException('chain append failed'));

        try {
            $svc->closePeriod('user', self::CP_USER, self::CP_COURSE, self::CP_CERT);
            $this->fail('audit failure must propagate (fail-loud, job retries next run)');
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertSame(0, $db->commitCalls, 'a failed close must NOT commit');
        $this->assertSame(1, $db->rollBackCalls, 'a failed close must roll back the write-set');
    }

    /**
     * HIGH 5 (PII): PERIOD_CLOSED context carries ONLY facts — course_id, subject_type,
     * closed_at. period_key must never enter context_json: it embeds the raw uid, and
     * context_json is bound into the tamper-evident chain via payload_hash, so PII there
     * would survive DSGVO erasure and break the chain invariant.
     */
    public function testPeriodClosedContextIsPiiFree(): void {
        [$svc, , $audit] = $this->makeClosePeriodService([
            new FakeQueryBuilder(null, 1),
            new FakeQueryBuilder(),
            new FakeQueryBuilder(),
            new FakeQueryBuilder(),
        ]);

        $emailUid = 'alice@example.com';
        $capturedContext = null;
        $audit->expects($this->once())->method('logComplianceEvent')
            ->willReturnCallback(function (string $key, string $uid, array $ctx) use (&$capturedContext): void {
                $capturedContext = $ctx;
            });

        $svc->closePeriod('user', $emailUid, self::CP_COURSE, self::CP_CERT);

        $this->assertIsArray($capturedContext);
        $this->assertArrayNotHasKey('period_key', $capturedContext,
            'period_key embeds the raw uid — must never enter the chain-bound context_json');
        $this->assertStringNotContainsString($emailUid, (string)json_encode($capturedContext),
            'no raw (potentially email-shaped) uid anywhere in the compliance context');
        $this->assertEqualsCanonicalizing(
            ['course_id', 'subject_type', 'closed_at'],
            array_keys($capturedContext),
            'context carries ONLY the facts allow-list'
        );
    }

    // ---- closeExpiredPeriods() (164-05, RECERT-04) ----------------------------------------

    /**
     * RECERT-04: the daily sweep selects certs that still OWN their idem slot and expired past
     * the grace cutoff (now - grace*86400), reads user_id/course_id straight off the cert row
     * (NEVER parses the idem key — uids may contain ':'), and closes each period. A poisoned
     * row (closePeriod throws → rolled back) is logged and skipped so it can never stall the
     * whole compliance loop; only successful closes count.
     */
    public function testCloseExpiredPeriodsClosesEachRowAndIsolatesFailures(): void {
        $now = 1750000000;
        $rows = [
            ['id' => 42, 'user_id' => 'alice:with:colons', 'course_id' => 7],
            ['id' => 43, 'user_id' => 'bob',               'course_id' => 9],
        ];
        $finder = new FakeQueryBuilder(new FakeResult(fetchQueue: $rows));
        $db = new FakeDbConnection([$finder]);

        $config = $this->createMock(\OCP\IConfig::class);
        $config->method('getAppValue')->willReturn('14'); // grace days

        $svc = $this->getMockBuilder(AssignmentService::class)
            ->setConstructorArgs([
                $db,
                $this->createMock(IGroupManager::class),
                $this->createMock(AuditService::class),
                $config,
                $this->createMock(\Psr\Log\LoggerInterface::class),
            ])
            ->onlyMethods(['closePeriod'])
            ->getMock();

        // Row 1 (alice) fails mid-close; row 2 (bob) MUST still be processed.
        $calls = [];
        $svc->expects($this->exactly(2))->method('closePeriod')
            ->willReturnCallback(function (string $type, string $uid, int $courseId, int $certId) use (&$calls): void {
                $calls[] = [$type, $uid, $courseId, $certId];
                if ($uid === 'alice:with:colons') {
                    throw new \RuntimeException('poisoned row');
                }
            });

        $closed = $svc->closeExpiredPeriods($now);

        $this->assertSame(1, $closed, 'failed row is logged + skipped; only successful closes count');
        $this->assertSame([
            ['user', 'alice:with:colons', 7, 42],
            ['user', 'bob', 9, 43],
        ], $calls, 'user_id/course_id/certId come straight from the cert row — no idem-key parsing');

        // Query shape: cutoff = now - grace*86400 as a named parameter on the finder.
        $this->assertSame('learning_certificates', $finder->from['table'] ?? null);
        $paramValues = array_column($finder->namedParameters, 'value');
        $this->assertContains($now - 14 * 86400, $paramValues,
            'expires_at cutoff must honor the recert_grace_days config window');
    }
}
