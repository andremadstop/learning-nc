<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Reminder idempotency row (table learning_recert_reminders) — Phase 164 (RECERT-06).
 *
 * One row per (cert_id, threshold_days) pair — UNIQUE index `learn_rcrt_rem_uq` prevents
 * duplicate sends across job re-runs AND notification dismissals. Set by
 * RecertReminderMapper::insertOnce(); catch REASON_UNIQUE_CONSTRAINT_VIOLATION = "already sent".
 *
 * @method int getCertId()
 * @method void setCertId(int $certId)
 * @method int getThresholdDays()
 * @method void setThresholdDays(int $thresholdDays)
 * @method int getSentAt()
 * @method void setSentAt(int $sentAt)
 */
class RecertReminder extends Entity {
    /** @var int Foreign key → learning_certificates.id */
    protected $certId;
    /** @var int Days-before-expiry threshold (30 | 7) */
    protected $thresholdDays;
    /** @var int Unix timestamp at which the reminder was sent */
    protected $sentAt;

    public function __construct() {
        $this->addType('id',            'integer');
        $this->addType('certId',        'integer');
        $this->addType('thresholdDays', 'integer');
        $this->addType('sentAt',        'integer');
    }
}
