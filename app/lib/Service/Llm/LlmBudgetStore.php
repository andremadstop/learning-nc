<?php
declare(strict_types=1);

namespace OCA\Learning\Service\Llm;

use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Atomic counters in learning_llm_budget (Task B2, spec 5.1). One row per bucket and window.
 *
 * Every reservation is a single conditional UPDATE whose affected-row count decides; there is no
 * read-then-write and no outer transaction (a unique violation inside a PostgreSQL transaction
 * would poison it). A missing row is created with insertIgnoreConflict() — ON CONFLICT DO
 * NOTHING / INSERT IGNORE, no exception — and the UPDATE is tried once more.
 *
 * MySQL runs with CLIENT_FOUND_ROWS (matched, not changed rows): every UPDATE here changes the
 * rows it matches, so both engines report the same number.
 *
 * Count buckets: window_start is the window, count the reservations. A new window deletes only
 * windows older than the previous one, so a request that computed its window just before the
 * rollover (or on a node whose clock runs behind) still meets the exhausted previous row instead
 * of recreating it with count 0; a window that is two or more windows old is never recreated.
 *
 * Concurrency buckets: one row per slot (window_start = slot 1..max). `inflight` holds the
 * owner's random token (0 = free) and `updated_at` that owner's acquisition time. A slot is taken
 * over when free or when its own acquisition is older than the TTL; release only clears the slot
 * while it still carries the releasing ticket's token, so a ticket from before a takeover cannot
 * free the new holder's slot, and acquiring one slot never keeps another one alive.
 *
 * Expressions use getColumnName() for quoting; never hand-written double quotes (string
 * literals on MariaDB).
 *
 * Deadlocks (#62): under parallel load MariaDB can report 1213 and PostgreSQL 40P01/40001 for a
 * single one of these statements (gap and row locks of INSERT IGNORE, UPDATE and DELETE on the
 * same bucket). The engine has then rolled back exactly that statement, because it ran on its
 * own in autocommit, so running it again is a clean restart. Every statement therefore runs
 * through retryOnDeadlock(): at most MAX_ATTEMPTS attempts with a short random pause, after that
 * the original exception goes up as before. Inside a transaction someone else opened nothing is
 * retried: that transaction is already rolled back (MariaDB) or poisoned (PostgreSQL 25P02), and
 * only its owner can start it again. Lock-wait timeouts (MariaDB 1205) are not retried.
 */
class LlmBudgetStore {
    public const TABLE = 'learning_llm_budget';

    /** Attempts per statement when the database reports a deadlock (#62). */
    public const MAX_ATTEMPTS = 3;

    /** SQLSTATEs of a deadlock or serialization failure (40001 also carries MariaDB 1213). */
    private const RETRY_SQLSTATES = ['40001', '40P01'];

    public function __construct(
        private IDBConnection $db,
    ) {
    }

    /** count+1 in (bucket, window) if count < limit. */
    public function tryIncrementCount(string $bucket, int $windowStart, int $windowLength, int $limit, int $now): bool {
        if ($limit < 1 || $windowLength < 1) {
            return false;
        }
        if ($this->incrementIfBelow($bucket, $windowStart, $limit, $now)) {
            return true;
        }
        // Its row was deleted on purpose when a newer window began: the request is stale.
        if ($this->hasWindowAfter($bucket, $windowStart + $windowLength)) {
            return false;
        }
        if ($this->createRow($bucket, $windowStart, $now)) {
            $this->deleteWindowsBefore($bucket, $windowStart - $windowLength);
        }
        return $this->incrementIfBelow($bucket, $windowStart, $limit, $now);
    }

    /** Gives back one count of a reservation that was not completed. */
    public function decrementCount(string $bucket, int $windowStart): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('count', $qb->createFunction($qb->getColumnName('count') . ' - 1'))
            ->where($qb->expr()->eq('bucket', $qb->createNamedParameter($bucket)))
            ->andWhere($qb->expr()->eq('window_start', $qb->createNamedParameter($windowStart, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->gt('count', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
        $this->retryOnDeadlock(fn (): int => $qb->executeStatement());
    }

    /**
     * Takes one of $max slots: a free one, or one whose own acquisition is older than $ttl
     * seconds (orphaned by a crashed request).
     *
     * @return array{index: int, token: int}|null null if every slot is held
     */
    public function tryAcquireSlot(string $bucket, int $max, int $now, int $ttl): ?array {
        // fits a signed 32-bit INTEGER on every engine; 0 means free
        $token = random_int(1, 2147483647);
        for ($index = 1; $index <= $max; $index++) {
            if ($this->claimSlot($bucket, $index, $token, $now, $ttl)
                || ($this->createRow($bucket, $index, $now) && $this->claimSlot($bucket, $index, $token, $now, $ttl))) {
                return ['index' => $index, 'token' => $token];
            }
        }
        return null;
    }

    /** Frees the slot, but only while it still belongs to this token. */
    public function releaseSlot(string $bucket, int $index, int $token): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('inflight', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('bucket', $qb->createNamedParameter($bucket)))
            ->andWhere($qb->expr()->eq('window_start', $qb->createNamedParameter($index, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('inflight', $qb->createNamedParameter($token, IQueryBuilder::PARAM_INT)));
        $this->retryOnDeadlock(fn (): int => $qb->executeStatement());
    }

    private function incrementIfBelow(string $bucket, int $windowStart, int $limit, int $now): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('count', $qb->createFunction($qb->getColumnName('count') . ' + 1'))
            ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('bucket', $qb->createNamedParameter($bucket)))
            ->andWhere($qb->expr()->eq('window_start', $qb->createNamedParameter($windowStart, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->lt('count', $qb->createNamedParameter($limit, IQueryBuilder::PARAM_INT)));
        return $this->retryOnDeadlock(fn (): int => $qb->executeStatement()) === 1;
    }

    /** @phpstan-impure the outcome depends on the row, which another request may change in between */
    private function claimSlot(string $bucket, int $index, int $token, int $now, int $ttl): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('inflight', $qb->createNamedParameter($token, IQueryBuilder::PARAM_INT))
            ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('bucket', $qb->createNamedParameter($bucket)))
            ->andWhere($qb->expr()->eq('window_start', $qb->createNamedParameter($index, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->eq('inflight', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)),
                $qb->expr()->lt('updated_at', $qb->createNamedParameter($now - $ttl, IQueryBuilder::PARAM_INT)),
            ));
        return $this->retryOnDeadlock(fn (): int => $qb->executeStatement()) === 1;
    }

    /** True if this call created the row (false: it already existed). */
    private function createRow(string $bucket, int $windowStart, int $now): bool {
        return $this->retryOnDeadlock(fn (): int => $this->db->insertIgnoreConflict(self::TABLE, [
            'bucket' => $bucket,
            'window_start' => $windowStart,
            'count' => 0,
            'inflight' => 0,
            'updated_at' => $now,
        ])) === 1;
    }

    private function hasWindowAfter(string $bucket, int $windowStart): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('window_start')->from(self::TABLE)
            ->where($qb->expr()->eq('bucket', $qb->createNamedParameter($bucket)))
            ->andWhere($qb->expr()->gt('window_start', $qb->createNamedParameter($windowStart, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        return $this->retryOnDeadlock(function () use ($qb): bool {
            $result = $qb->executeQuery();
            $found = $result->fetchOne() !== false;
            $result->closeCursor();
            return $found;
        });
    }

    /** Keeps the table bounded: a new window drops every window before the previous one. */
    private function deleteWindowsBefore(string $bucket, int $windowStart): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('bucket', $qb->createNamedParameter($bucket)))
            ->andWhere($qb->expr()->lt('window_start', $qb->createNamedParameter($windowStart, IQueryBuilder::PARAM_INT)));
        $this->retryOnDeadlock(fn (): int => $qb->executeStatement());
    }

    /**
     * Runs one autocommit statement, again after a deadlock, at most MAX_ATTEMPTS times.
     *
     * @template T
     * @param callable(): T $statement
     * @return T
     */
    private function retryOnDeadlock(callable $statement): mixed {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $statement();
            } catch (\Throwable $e) {
                if ($attempt >= self::MAX_ATTEMPTS || !self::isDeadlock($e) || $this->db->inTransaction()) {
                    throw $e;
                }
                $this->pause($attempt);
            }
        }
    }

    /** Deadlock or serialization failure: MariaDB/MySQL 1213, PostgreSQL 40P01 and 40001. */
    private static function isDeadlock(\Throwable $e): bool {
        for ($t = $e; $t !== null; $t = $t->getPrevious()) {
            if ($t instanceof Exception && $t->getReason() === Exception::REASON_DEADLOCK) {
                return true;
            }
            // the driver exception below the wrapper (Doctrine), in case a wrapper lost the reason
            if (method_exists($t, 'getSQLState') && in_array($t->getSQLState(), self::RETRY_SQLSTATES, true)) {
                return true;
            }
        }
        return false;
    }

    /** Short random backoff before the next attempt, so the competing requests drift apart. */
    protected function pause(int $attempt): void {
        usleep(random_int(5_000, 20_000) * $attempt);
    }
}
