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
     * CLAIM a (cert, threshold) reminder slot (outbox pattern, 164-07 review pass 2).
     * Returns true if this call inserted the row (claim won — caller must deliver + call
     * markDelivered), false on the UNIQUE(cert_id, threshold_days) violation (claimed earlier —
     * either delivered, or a pending row another/earlier run will retry).
     *
     * The row is a delivery PROOF only once delivered_at is set; a bare claim row with
     * delivered_at NULL is retryable. This survives the failure mode where compensation
     * (deleting the row after a failed notify) itself fails.
     *
     * DST note: the threshold window (T-30 / T-7) is computed from expires_at in the SERVICE;
     * this mapper only stores claim/delivery state.
     */
    public function insertOnce(int $certId, int $thresholdDays, int $sentAt): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->insert($this->getTableName())
            ->values([
                'cert_id'        => $qb->createNamedParameter($certId, IQueryBuilder::PARAM_INT),
                'threshold_days' => $qb->createNamedParameter($thresholdDays, IQueryBuilder::PARAM_INT),
                'sent_at'        => $qb->createNamedParameter($sentAt, IQueryBuilder::PARAM_INT),
                'delivered_at'   => $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
            ]);
        try {
            $qb->executeStatement();
            return true;
        } catch (DBException $e) {
            if ($e->getReason() === DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                return false; // already claimed — delivered or pending-retry elsewhere
            }
            throw $e;
        }
    }

    /**
     * Atomically RE-CLAIM a stale pending row (164-07 review pass 3): two concurrent runners
     * can both observe the same stale claim — only the one whose CAS UPDATE matches (affected
     * rows === 1) may deliver. Bumping sent_at to $now makes the row read as a FRESH pending
     * claim for everyone else (loser skips; if the winner's delivery fails too, the row goes
     * stale again and is retried on a later run).
     */
    public function reclaimStale(int $certId, int $thresholdDays, int $now, int $staleBefore): bool {
        $qb = $this->db->getQueryBuilder();
        $affected = $qb->update($this->getTableName())
            ->set('sent_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('cert_id', $qb->createNamedParameter($certId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('threshold_days', $qb->createNamedParameter($thresholdDays, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNull('delivered_at'))
            ->andWhere($qb->expr()->lte('sent_at', $qb->createNamedParameter($staleBefore, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
        return $affected === 1;
    }

    /**
     * Confirm delivery: flips the claim row into the permanent "delivered" state. Guarded on
     * delivered_at IS NULL so a late duplicate confirm never overwrites the first timestamp.
     */
    public function markDelivered(int $certId, int $thresholdDays, int $deliveredAt): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('delivered_at', $qb->createNamedParameter($deliveredAt, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq('cert_id', $qb->createNamedParameter($certId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('threshold_days', $qb->createNamedParameter($thresholdDays, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNull('delivered_at'));
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
