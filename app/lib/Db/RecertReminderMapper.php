<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for learning_recert_reminders — reminder idempotency store (RECERT-06).
 *
 * UNIQUE(cert_id, threshold_days) = exactly one send per (cert, threshold) regardless of how
 * many times the job runs or the user dismisses the notification.
 *
 * insertOnce() is the CAS primitive: insert → if UNIQUE violation → "already sent" → return false.
 *
 * @extends QBMapper<RecertReminder>
 */
class RecertReminderMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_recert_reminders', RecertReminder::class);
    }

    /**
     * Attempt to record a reminder send. Returns true if the row was inserted (first send),
     * false if a UNIQUE(cert_id, threshold_days) violation occurred ("already sent").
     *
     * DST note: the threshold window (T-30 / T-7) is computed from expires_at in the SERVICE;
     * this mapper only stores the fact that the reminder fired.
     */
    public function insertOnce(int $certId, int $thresholdDays, int $sentAt): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->insert($this->getTableName())
            ->values([
                'cert_id'        => $qb->createNamedParameter($certId, IQueryBuilder::PARAM_INT),
                'threshold_days' => $qb->createNamedParameter($thresholdDays, IQueryBuilder::PARAM_INT),
                'sent_at'        => $qb->createNamedParameter($sentAt, IQueryBuilder::PARAM_INT),
            ]);
        try {
            $qb->executeStatement();
            return true;
        } catch (DBException $e) {
            if ($e->getReason() === DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                return false; // already sent — the UNIQUE slot is the idempotency guarantee
            }
            throw $e;
        }
    }

    /**
     * Compensation for a failed delivery (164-07 review HIGH 3): insertOnce committed the
     * idempotency row but IManager::notify() threw — delete the row so the next job run
     * retries the send instead of silently skipping the threshold forever.
     */
    public function deleteByCertAndThreshold(int $certId, int $thresholdDays): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('cert_id', $qb->createNamedParameter($certId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('threshold_days', $qb->createNamedParameter($thresholdDays, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * Find the reminder record for a specific cert and threshold, or null if not yet sent.
     */
    public function findByCertAndThreshold(int $certId, int $thresholdDays): ?RecertReminder {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('cert_id', $qb->createNamedParameter($certId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('threshold_days', $qb->createNamedParameter($thresholdDays, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        try {
            /** @var RecertReminder */
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }
}
