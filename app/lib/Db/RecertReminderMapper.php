<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception as DBException;
use OCP\IDBConnection;

/**
 * Mapper for learning_recert_reminders — reminder idempotency store (RECERT-06).
 *
 * UNIQUE(cert_id, threshold_days) = exactly one send per (cert, threshold) regardless of how
 * many times the job runs or the user dismisses the notification.
 *
 * insertOnce() is the CAS primitive: insert → if UNIQUE violation → "already sent" → return false.
 * Impl in 164-06.
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
     *
     * @throws \LogicException until implemented in 164-06
     */
    public function insertOnce(int $certId, int $thresholdDays, int $sentAt): bool {
        throw new \LogicException('RecertReminderMapper::insertOnce not implemented — impl in 164-06');
    }

    /**
     * Find the reminder record for a specific cert and threshold, or null if not yet sent.
     *
     * @throws \LogicException until implemented in 164-06
     */
    public function findByCertAndThreshold(int $certId, int $thresholdDays): ?RecertReminder {
        throw new \LogicException('RecertReminderMapper::findByCertAndThreshold not implemented — impl in 164-06');
    }
}
