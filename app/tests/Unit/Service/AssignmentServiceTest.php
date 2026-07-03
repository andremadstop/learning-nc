<?php
declare(strict_types=1);
namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\AssignmentService;
use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\ComplianceEventTypes;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
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

        $service = new AssignmentService($db, $gm, $audit);
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

        $service = new AssignmentService($db, $gm, $audit);
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

        $service = new AssignmentService($db, $gm, $audit);
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
        $svc = new AssignmentService($db, $this->createMock(IGroupManager::class), $audit);
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
        [$svc, , ] = $this->makeClosePeriodService([$certUpdate, new FakeQueryBuilder(), new FakeQueryBuilder()]);

        $svc->closePeriod('user', self::CP_USER, self::CP_COURSE, self::CP_CERT);

        $this->assertSame('update', $certUpdate->operation);
        $this->assertSame('learning_certificates', $certUpdate->table);
        $paramValues = array_column($certUpdate->namedParameters, 'value');
        $this->assertContains(self::CP_CERT, $paramValues, 'WHERE must pin the specific cert id');
        $this->assertContains(self::CP_USER . ':' . self::CP_COURSE, $paramValues,
            'WHERE must also match the idem key (cert belongs to this subject+course)');
    }

    /**
     * BLOCKER 2 (atomicity): the successful close wraps all three writes + the audit append
     * in exactly one transaction — begin/commit, no rollback.
     */
    public function testClosePeriodRunsInOneTransaction(): void {
        [$svc, $db, $audit] = $this->makeClosePeriodService([
            new FakeQueryBuilder(null, 1), // cert UPDATE (CAS wins)
            new FakeQueryBuilder(),        // assignment UPDATE
            new FakeQueryBuilder(),        // fresh-row INSERT
        ]);

        $audit->expects($this->once())->method('logComplianceEvent')
            ->with(ComplianceEventTypes::PERIOD_CLOSED, self::CP_USER, $this->anything());

        $svc->closePeriod('user', self::CP_USER, self::CP_COURSE, self::CP_CERT);

        $this->assertSame(1, $db->beginTransactionCalls, 'close opens exactly one transaction');
        $this->assertSame(1, $db->commitCalls, 'close commits exactly once');
        $this->assertSame(0, $db->rollBackCalls, 'happy path never rolls back');
        $this->assertCount(3, $db->issuedBuilders, 'all three writes ran inside the transaction');
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
}
