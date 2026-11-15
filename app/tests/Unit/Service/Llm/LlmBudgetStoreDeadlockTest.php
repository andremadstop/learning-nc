<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service\Llm;

use OCA\Learning\Service\Llm\LlmBudgetStore;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The shared IDBConnection stub has no insertIgnoreConflict() (adding it would force every fake
 * connection to implement it); the real interface has it since NC 16.
 */
interface InsertIgnoreDbConnection extends IDBConnection {
    public function insertIgnoreConflict(string $table, array $values): int;
}

/** Store whose backoff only records the attempts instead of sleeping. */
class NoSleepBudgetStore extends LlmBudgetStore {
    /** @var list<int> */
    public array $pauses = [];

    protected function pause(int $attempt): void {
        $this->pauses[] = $attempt;
    }
}

/** A driver-level exception as Doctrine raises it below the Nextcloud wrapper. */
class SqlStateException extends \RuntimeException {
    public function __construct(private string $sqlState) {
        parent::__construct("SQLSTATE[{$sqlState}]");
    }

    public function getSQLState(): string {
        return $this->sqlState;
    }
}

/**
 * GitHub #62: under parallel reservations MariaDB reported "1213 Deadlock found ... try
 * restarting transaction" for one budget statement and the reservation failed. A deadlock or
 * serialization failure is now retried (at most three attempts, short backoff); anything else,
 * the exhausted retries and statements inside a caller's transaction fail as before.
 */
#[AllowMockObjectsWithoutExpectations]
class LlmBudgetStoreDeadlockTest extends TestCase {
    private InsertIgnoreDbConnection&MockObject $db;
    private IQueryBuilder&MockObject $qb;
    private NoSleepBudgetStore $store;
    private int $statements = 0;

    protected function setUp(): void {
        $expr = $this->createMock(IExpressionBuilder::class);
        $this->qb = $this->createMock(IQueryBuilder::class);
        foreach (['update', 'set', 'where', 'andWhere', 'delete', 'select', 'from', 'setMaxResults'] as $fluent) {
            $this->qb->method($fluent)->willReturnSelf();
        }
        $this->qb->method('expr')->willReturn($expr);
        $this->qb->method('createNamedParameter')->willReturn(':p');
        $this->qb->method('getColumnName')->willReturn('count');

        $this->db = $this->createMock(InsertIgnoreDbConnection::class);
        $this->db->method('getQueryBuilder')->willReturn($this->qb);
        $this->store = new NoSleepBudgetStore($this->db);
    }

    // the test stub of OCP\DB\Exception takes the reason directly (tests/Support/PhpUnitStubs.php)
    private static function deadlock(): DbException {
        return new DbException(DbException::REASON_DEADLOCK,
            'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
    }

    private static function lockWaitTimeout(): DbException {
        return new DbException(DbException::REASON_LOCK_WAIT_TIMEOUT,
            'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded');
    }

    /** executeStatement throws the given exceptions in order, then reports one affected row. */
    private function statementFails(\Throwable ...$failures): void {
        $this->qb->method('executeStatement')->willReturnCallback(function () use (&$failures): int {
            $this->statements++;
            $next = array_shift($failures);
            if ($next !== null) {
                throw $next;
            }
            return 1;
        });
    }

    public function testADeadlockedIncrementIsRunAgainAndReserves(): void {
        $this->statementFails(self::deadlock());

        $this->assertTrue($this->store->tryIncrementCount('g:d', 0, 86400, 10, 5));
        $this->assertSame(2, $this->statements);
        $this->assertSame([1], $this->store->pauses);
    }

    public function testTwoDeadlocksInARowStillSucceedOnTheThirdAttempt(): void {
        $this->statementFails(self::deadlock(), self::deadlock());

        $this->assertNotNull($this->store->tryAcquireSlot('u:alice:inflight', 3, 5, 120));
        $this->assertSame(3, $this->statements);
        $this->assertSame([1, 2], $this->store->pauses);
    }

    public function testAPostgresDeadlockBelowTheWrapperIsRetriedToo(): void {
        $wrapped = new DbException(0, 'deadlock detected', new SqlStateException('40P01'));
        $this->statementFails($wrapped);

        $this->store->decrementCount('g:d', 0);
        $this->assertSame(2, $this->statements, 'the compensation of a rejected call is not lost');
    }

    public function testAfterThreeDeadlocksTheOriginalErrorGoesUp(): void {
        $third = self::deadlock();
        $this->statementFails(self::deadlock(), self::deadlock(), $third, self::deadlock());

        try {
            $this->store->tryIncrementCount('g:d', 0, 86400, 10, 5);
            $this->fail('expected the deadlock after three attempts');
        } catch (DbException $e) {
            $this->assertSame($third, $e);
        }
        $this->assertSame(LlmBudgetStore::MAX_ATTEMPTS, $this->statements);
        $this->assertSame(3, LlmBudgetStore::MAX_ATTEMPTS);
    }

    public function testALockWaitTimeoutIsNotRetried(): void {
        $timeout = self::lockWaitTimeout();
        $this->statementFails($timeout);

        try {
            $this->store->releaseSlot('u:alice:inflight', 1, 42);
            $this->fail('expected the lock wait timeout');
        } catch (DbException $e) {
            $this->assertSame($timeout, $e);
        }
        $this->assertSame(1, $this->statements);
        $this->assertSame([], $this->store->pauses);
    }

    public function testOtherErrorsAreNotRetried(): void {
        $this->statementFails(new DbException(0, 'syntax error', new SqlStateException('42601')));

        $this->expectException(DbException::class);
        try {
            $this->store->tryIncrementCount('g:d', 0, 86400, 10, 5);
        } finally {
            $this->assertSame(1, $this->statements);
        }
    }

    /** Inside a caller's transaction a retry would run outside the rolled-back/poisoned block. */
    public function testNothingIsRetriedInsideACallersTransaction(): void {
        $this->db->method('inTransaction')->willReturn(true);
        $this->statementFails(self::deadlock());

        $this->expectException(DbException::class);
        try {
            $this->store->tryIncrementCount('g:d', 0, 86400, 10, 5);
        } finally {
            $this->assertSame(1, $this->statements);
            $this->assertSame([], $this->store->pauses);
        }
    }

    public function testADeadlockedRowCreationIsRunAgain(): void {
        // UPDATE finds no row (0), the window check finds no newer window, INSERT IGNORE deadlocks
        // once and then creates the row, old windows are dropped, the second UPDATE reserves.
        $updates = [0, 0, 1]; // first UPDATE, DELETE of old windows, second UPDATE
        $this->qb->method('executeStatement')->willReturnCallback(function () use (&$updates): int {
            return (int)array_shift($updates);
        });
        $result = $this->createMock(\OCP\DB\IResult::class);
        $result->method('fetchOne')->willReturn(false);
        $this->qb->method('executeQuery')->willReturn($result);
        $inserts = 0;
        $this->db->method('insertIgnoreConflict')->willReturnCallback(function () use (&$inserts): int {
            if (++$inserts === 1) {
                throw self::deadlock();
            }
            return 1;
        });

        $this->assertTrue($this->store->tryIncrementCount('g:d', 86400, 86400, 10, 86405));
        $this->assertSame(2, $inserts);
    }
}
