<?php
declare(strict_types=1);
namespace OCA\Learning\Tests\Unit\Db;

use OCA\Learning\Db\DbErrors;
use PHPUnit\Framework\TestCase;

/**
 * DbErrors::isMissingTable is the single rule behind two very different reactions:
 * UninstallCommand skips work when a table is absent, and CurriculumScopeMapper /
 * CourseService degrade a feature instead of failing a page.
 *
 * Both only stay safe while this predicate is narrow. A false positive would turn a
 * real database outage into silently empty results, so "unclassified" must mean "no".
 */
class DbErrorsTest extends TestCase {

    public function testReasonDatabaseObjectNotFoundIsMissingTable(): void {
        $e = new \OCP\DB\Exception(\OCP\DB\Exception::REASON_DATABASE_OBJECT_NOT_FOUND, 'no such table');
        $this->assertTrue(DbErrors::isMissingTable($e));
    }

    public function testOtherDatabaseReasonsAreNotMissingTable(): void {
        $e = new \OCP\DB\Exception(\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION, 'duplicate');
        $this->assertFalse(DbErrors::isMissingTable($e));
    }

    /**
     * Drivers that reach us unwrapped carry SQLSTATE as the exception code.
     * 42P01 = PostgreSQL undefined_table, 42S02 = MySQL/MariaDB base table not found.
     */
    public function testPostgresSqlstateIsMissingTable(): void {
        $this->assertTrue(DbErrors::isMissingTable($this->codedException('42P01')));
    }

    public function testMysqlSqlstateIsMissingTable(): void {
        $this->assertTrue(DbErrors::isMissingTable($this->codedException('42S02')));
    }

    public function testUnrelatedThrowableIsNotMissingTable(): void {
        $this->assertFalse(DbErrors::isMissingTable(new \RuntimeException('disk on fire')));
    }

    /** SQLSTATE codes are strings; \Exception::$code is typed int, so subclass to carry it. */
    private function codedException(string $sqlstate): \Throwable {
        return new class($sqlstate) extends \Exception {
            public function __construct(string $sqlstate) {
                parent::__construct('driver error');
                $this->code = $sqlstate;
            }
        };
    }
}
