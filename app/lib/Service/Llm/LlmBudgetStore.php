<?php
declare(strict_types=1);

namespace OCA\Learning\Service\Llm;

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
 * Expressions use getColumnName() for quoting; never hand-written double quotes (string
 * literals on MariaDB).
 */
class LlmBudgetStore {
    public const TABLE = 'learning_llm_budget';

    public function __construct(
        private IDBConnection $db,
    ) {
    }

    /** count+1 in (bucket, window) if count < limit. */
    public function tryIncrementCount(string $bucket, int $windowStart, int $limit, int $now): bool {
        if ($limit < 1) {
            return false;
        }
        if ($this->incrementIfBelow($bucket, $windowStart, $limit, $now)) {
            return true;
        }
        if ($this->createRow($bucket, $windowStart, $now)) {
            $this->deleteOlderWindows($bucket, $windowStart);
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
        $qb->executeStatement();
    }

    /**
     * One concurrency slot (window_start 0). Slots of a bucket whose last acquisition is older
     * than $ttl seconds count as orphaned: the acquisition starts over at 1.
     */
    public function tryAcquireSlot(string $bucket, int $max, int $now, int $ttl): bool {
        if ($this->acquireIfFree($bucket, $max, $now, $ttl)) {
            return true;
        }
        $this->createRow($bucket, 0, $now);
        return $this->acquireIfFree($bucket, $max, $now, $ttl);
    }

    public function releaseSlot(string $bucket): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('inflight', $qb->createFunction($qb->getColumnName('inflight') . ' - 1'))
            ->where($qb->expr()->eq('bucket', $qb->createNamedParameter($bucket)))
            ->andWhere($qb->expr()->eq('window_start', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->gt('inflight', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    private function incrementIfBelow(string $bucket, int $windowStart, int $limit, int $now): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->update(self::TABLE)
            ->set('count', $qb->createFunction($qb->getColumnName('count') . ' + 1'))
            ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('bucket', $qb->createNamedParameter($bucket)))
            ->andWhere($qb->expr()->eq('window_start', $qb->createNamedParameter($windowStart, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->lt('count', $qb->createNamedParameter($limit, IQueryBuilder::PARAM_INT)));
        return $qb->executeStatement() === 1;
    }

    private function acquireIfFree(string $bucket, int $max, int $now, int $ttl): bool {
        $qb = $this->db->getQueryBuilder();
        $inflight = $qb->getColumnName('inflight');
        $updated = $qb->getColumnName('updated_at');
        // two placeholders for the same value: a named parameter is never bound twice
        $staleInSet = $qb->createNamedParameter($now - $ttl, IQueryBuilder::PARAM_INT);
        $staleInWhere = $qb->createNamedParameter($now - $ttl, IQueryBuilder::PARAM_INT);
        $qb->update(self::TABLE)
            ->set('inflight', $qb->createFunction(
                'CASE WHEN ' . $updated . ' < ' . $staleInSet . ' THEN 1 ELSE ' . $inflight . ' + 1 END'
            ))
            ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('bucket', $qb->createNamedParameter($bucket)))
            ->andWhere($qb->expr()->eq('window_start', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->lt('inflight', $qb->createNamedParameter($max, IQueryBuilder::PARAM_INT)),
                $qb->expr()->lt('updated_at', $staleInWhere),
            ));
        return $qb->executeStatement() === 1;
    }

    /** True if this call created the row (false: it already existed). */
    private function createRow(string $bucket, int $windowStart, int $now): bool {
        return $this->db->insertIgnoreConflict(self::TABLE, [
            'bucket' => $bucket,
            'window_start' => $windowStart,
            'count' => 0,
            'inflight' => 0,
            'updated_at' => $now,
        ]) === 1;
    }

    /** Keeps the table bounded: a new window replaces the bucket's older ones. */
    private function deleteOlderWindows(string $bucket, int $windowStart): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('bucket', $qb->createNamedParameter($bucket)))
            ->andWhere($qb->expr()->lt('window_start', $qb->createNamedParameter($windowStart, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
