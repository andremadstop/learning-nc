<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Reminder outbox row (table learning_recert_reminders) — Phase 164 (RECERT-06).
 *
 * One row per (cert_id, threshold_days) pair — UNIQUE index `learn_rcrt_rem_uq` is the CLAIM
 * lock across job re-runs. Outbox state (164-07 review pass 2):
 *   sent_at       = claim timestamp (insertOnce won the CAS)
 *   delivered_at  = NULL   → pending: notify() has not confirmed; a stale pending row
 *                            (claim older than the retry window) is retried next run
 *   delivered_at != NULL   → delivered: never send again (the delivery PROOF)
 *
 * @method int getCertId()
 * @method void setCertId(int $certId)
 * @method int getThresholdDays()
 * @method void setThresholdDays(int $thresholdDays)
 * @method int getSentAt()
 * @method void setSentAt(int $sentAt)
 * @method int|null getDeliveredAt()
 * @method void setDeliveredAt(?int $deliveredAt)
 */
class RecertReminder extends Entity {
    /** @var int Foreign key → learning_certificates.id */
    protected $certId;
    /** @var int Days-before-expiry threshold (30 | 7) */
    protected $thresholdDays;
    /** @var int Unix timestamp of the CLAIM (insertOnce) */
    protected $sentAt;
    /** @var int|null Unix timestamp of confirmed delivery; NULL = pending/retryable */
    protected $deliveredAt;

    public function __construct() {
        $this->addType('id',            'integer');
        $this->addType('certId',        'integer');
        $this->addType('thresholdDays', 'integer');
        $this->addType('sentAt',        'integer');
        $this->addType('deliveredAt',   'integer');
    }
}
