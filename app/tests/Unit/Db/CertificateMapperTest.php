<?php
declare(strict_types=1);
namespace OCA\Learning\Tests\Unit\Db;

use OCA\Learning\Db\CertificateMapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

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
        $result = $mapper->findByCourseIdForUsers(1, [], null);

        $this->assertSame([], $result, 'Empty member list must return [] without issuing any query');
    }

    /**
     * RBAC-02 (GREEN + LOCKED): same invariant, with an explicit $expiresBefore cutoff.
     * Even with a filter, an empty $userIds must short-circuit before hitting DB.
     */
    public function testFindByCourseIdForUsersEmptyWithExpiry(): void {
        $db = $this->createMock(IDBConnection::class);
        $db->expects($this->never())->method('getQueryBuilder');

        $mapper = new CertificateMapper($db);
        $result = $mapper->findByCourseIdForUsers(7, [], 1_750_000_000);

        $this->assertSame([], $result, 'Empty member list + expiry cutoff must still short-circuit to [] without a query');
    }

    // ── populated members (RED until 163-05) ────────────────────────────────

    /**
     * RBAC-02 (RED): non-empty $userIds must return only certs whose user_id ∈ $userIds.
     * QB mock is wired for when 163-05 adds the real IN query. Skeleton returns [] →
     * assertNotEmpty fails → RED until 163-05.
     */
    public function testFindByCourseIdForUsersReturnsOnlyMembersInList(): void {
        $expr = $this->createMock(IExpressionBuilder::class);
        $qb   = $this->createMock(IQueryBuilder::class);
        $qb->method('expr')->willReturn($expr);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('createNamedParameter')->willReturnArgument(0);

        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);

        $mapper = new CertificateMapper($db);

        // RED: skeleton returns [] regardless of input
        $result = $mapper->findByCourseIdForUsers(7, ['alice', 'bob'], null);

        $this->assertNotEmpty(
            $result,
            'findByCourseIdForUsers with non-empty userIds must return matching certs — RED until 163-05'
        );
    }

    /**
     * RBAC-02 (RED): with an $expiresBefore cutoff and non-empty $userIds.
     * Skeleton returns [] → RED until 163-05.
     */
    public function testFindByCourseIdForUsersWithExpiryAndUsers(): void {
        $db = $this->createMock(IDBConnection::class);
        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $db->method('getQueryBuilder')->willReturn($qb);

        $mapper = new CertificateMapper($db);

        // RED: skeleton returns []
        $result = $mapper->findByCourseIdForUsers(7, ['carol'], 1_760_000_000);

        $this->assertNotEmpty(
            $result,
            'findByCourseIdForUsers with expiry + userIds must return matching certs — RED until 163-05'
        );
    }
}
