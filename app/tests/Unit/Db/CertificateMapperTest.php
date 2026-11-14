<?php
declare(strict_types=1);
namespace OCA\Learning\Tests\Unit\Db;

use OCA\Learning\Db\CertificateMapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

/**
 * Wave-0 RED tests for CertificateMapper::findByCourseIdForUsers (RBAC-02 DB-level IN).
 *
 * Two invariants:
 *
 * 1. Empty member list → [] immediately, NO query built (no IN () EVER).
 *    GREEN now (skeleton returns []) — must stay GREEN after 163-05.
 *    The never()-constraint on getQueryBuilder() locks the no-IN() invariant.
 *
 * 2. Non-empty member list → only certs whose user_id ∈ $userIds are returned.
 *    RED now (skeleton returns []) — flips GREEN in 163-05 when real IN query is wired.
 */
#[AllowMockObjectsWithoutExpectations]
class CertificateMapperTest extends TestCase {

    // ── empty members ───────────────────────────────────────────────────────

    /**
     * RBAC-02 (GREEN + LOCKED): empty $userIds → [] with NO DB query.
     * This is the "fail-closed" invariant: an empty group roster must never produce
     * an IN () query (would match all rows on some DB engines, or syntax-error on others).
     * The never()-constraint on getQueryBuilder() makes this a permanently locked guard.
     */
    public function testFindByCourseIdForUsersEmptyReturnsEmpty(): void {
        $db = $this->createMock(IDBConnection::class);
        $db->expects($this->never())->method('getQueryBuilder');

        $mapper = new CertificateMapper($db);
        $result = $mapper->findByCourseIdForUsers(1, []);

        $this->assertSame([], $result, 'Empty member list must return [] without issuing any query');
    }

    // ── populated members (RED until 163-05) ────────────────────────────────

    /**
     * RBAC-02 (DB-level IN filter): non-empty $userIds must build a query that filters
     * WHERE user_id IN (:members) via PARAM_STR_ARRAY — this is the security invariant.
     *
     * Row-mapping ("returns only matching certs") is genuinely integration-level (a mocked
     * QueryBuilder cannot produce rows without tautology); that path is covered by Gate 2
     * test-api.sh IDOR assertions (unauthorized group → 403). What we lock HERE at unit level
     * is that the mapper issues a query whose IN clause is on user_id with the member chunk —
     * i.e. the filter is at the DB layer, not applied post-fetch.
     */
    public function testFindByCourseIdForUsersReturnsOnlyMembersInList(): void {
        $expr = $this->createMock(IExpressionBuilder::class);
        // Lock the security-relevant call: IN filter on user_id.
        $expr->expects($this->once())
            ->method('in')
            ->with('user_id', $this->anything())
            ->willReturn('user_id IN (:members)');
        $expr->method('eq')->willReturn('eq_expr');

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('expr')->willReturn($expr);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        // Capture the PARAM type used for the member list.
        $capturedType = null;
        $qb->method('createNamedParameter')
            ->willReturnCallback(function ($value, $type = null) use (&$capturedType) {
                if (is_array($value)) {
                    $capturedType = $type;
                }
                return ':p';
            });

        $db = $this->createMock(IDBConnection::class);
        // Query MUST be built for a non-empty roster (no short-circuit).
        $db->expects($this->atLeastOnce())->method('getQueryBuilder')->willReturn($qb);

        $mapper = new CertificateMapper($db);
        $mapper->findByCourseIdForUsers(7, ['alice', 'bob']);

        $this->assertSame(
            IQueryBuilder::PARAM_STR_ARRAY,
            $capturedType,
            'member list must be bound as PARAM_STR_ARRAY for a DB-level IN () filter'
        );
    }

    /**
     * Audit #222: the group report classifies expiry itself, so the query carries no expiry
     * predicate — only the course, revoked and IN(user_id) filters.
     */
    public function testFindByCourseIdForUsersHasNoExpiryPredicate(): void {
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->expects($this->once())->method('in')->with('user_id', $this->anything())->willReturn('in_expr');
        $expr->method('eq')->willReturn('eq_expr');
        $expr->expects($this->never())->method('isNotNull');
        $expr->expects($this->never())->method('lte');

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('expr')->willReturn($expr);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('createNamedParameter')->willReturnArgument(0);

        $db = $this->createMock(IDBConnection::class);
        $db->expects($this->atLeastOnce())->method('getQueryBuilder')->willReturn($qb);

        $mapper = new CertificateMapper($db);
        $mapper->findByCourseIdForUsers(7, ['carol']);
    }
}
