<?php
declare(strict_types=1);
namespace OCA\Learning\Tests\Unit\Db;

use OCA\Learning\Db\CurriculumScopeMapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Codeberg #7 (second report): a course view died outright because
 * oc_learning_course_curriculum_scopes was absent on the reporter's install.
 *
 * The table is created by a rename migration that deliberately aborts rather than
 * creating an empty table over existing data. When that abort happens, the table is
 * simply missing — and a missing table must not take down the whole course view.
 *
 * Two invariants, and the second matters as much as the first:
 *   1. "table not found" degrades to null, so callers fall back to their defaults.
 *   2. ANY other database error still propagates. Swallowing those would turn real
 *      outages into silent wrong answers.
 */
class CurriculumScopeMapperTest extends TestCase {

    /** Build a fluent IQueryBuilder mock whose execution throws $toThrow. */
    private function dbThrowing(\Throwable $toThrow): IDBConnection {
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('eq')->willReturn('course_id = :cid');

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturn(':cid');
        $qb->method('executeQuery')->willThrowException($toThrow);
        $qb->method('execute')->willThrowException($toThrow);

        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);
        return $db;
    }

    /**
     * An OCP\DB\Exception carrying the given reason code.
     *
     * Uses the project's PhpUnitStubs signature (reason first, message second); the real
     * Nextcloud class wraps a Doctrine exception and is never constructed directly here.
     */
    private function dbException(int $reason): \OCP\DB\Exception {
        return new \OCP\DB\Exception($reason, 'db failure');
    }

    public function testMissingTableDegradesToNull(): void {
        $logger = $this->createMock(LoggerInterface::class);
        // The cause must stay visible to admins -- degrading silently is how the
        // original defect stayed hidden for months.
        $logger->expects($this->once())->method('warning');

        $db = $this->dbThrowing($this->dbException(\OCP\DB\Exception::REASON_DATABASE_OBJECT_NOT_FOUND));
        $mapper = new CurriculumScopeMapper($db, $logger);

        $this->assertNull(
            $mapper->findByCourse(42),
            'A missing curriculum-scope table must degrade to null, not abort the course view'
        );
    }

    public function testOtherDatabaseErrorsStillPropagate(): void {
        $logger = $this->createMock(LoggerInterface::class);
        $db = $this->dbThrowing($this->dbException(\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION));
        $mapper = new CurriculumScopeMapper($db, $logger);

        $this->expectException(\OCP\DB\Exception::class);
        $mapper->findByCourse(42);
    }

    public function testNonDatabaseThrowablesStillPropagate(): void {
        // Guards against the catch being widened to \Throwable later: anything that is not
        // a database error must keep bubbling up untouched.
        $logger = $this->createMock(LoggerInterface::class);
        $db = $this->dbThrowing(new \RuntimeException('something else entirely'));
        $mapper = new CurriculumScopeMapper($db, $logger);

        $this->expectException(\RuntimeException::class);
        $mapper->findByCourse(42);
    }
}
