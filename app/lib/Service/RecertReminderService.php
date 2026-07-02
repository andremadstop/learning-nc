<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\CertificateMapper;
use OCA\Learning\Db\RecertReminderMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Sends T-30 / T-7 cert-expiry reminder notifications (RECERT-06).
 *
 * Idempotency contract (impl in 164-06):
 *   For each non-revoked, non-anonymized cert with expires_at ∈ (now + threshold) ± window:
 *     1. Compute threshold = 30 or 7 days-before-expiry (DateTimeImmutable, DST-safe).
 *     2. Call RecertReminderMapper::insertOnce(certId, threshold, now):
 *        - true  → first send → create Notification (IManager) → subject: 'recert_reminder'
 *        - false → UNIQUE hit ("already sent") → skip
 *
 * Clock: ITimeFactory is always injected — never call time() directly in testable date logic.
 * Thresholds: ConfigDefaults::RECERT_REMINDER_THRESHOLDS = [30, 7].
 *
 * @see \OCA\Learning\AppInfo\ConfigDefaults::RECERT_REMINDER_THRESHOLDS
 */
class RecertReminderService {
    private const APP_ID = 'learning';

    public function __construct(
        private readonly CertificateMapper $certMapper,
        private readonly RecertReminderMapper $reminderMapper,
        private readonly IManager $notificationManager,
        private readonly ITimeFactory $timeFactory,
        private readonly IConfig $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Scan all expiring certs and send T-30/T-7 reminders. Returns count of reminders sent.
     *
     * Idempotent: safe to call multiple times; UNIQUE(cert_id, threshold_days) in
     * learning_recert_reminders prevents double-sends.
     *
     * @throws \LogicException until implemented in 164-06
     */
    public function sendRecertReminders(): int {
        throw new \LogicException('RecertReminderService::sendRecertReminders not implemented — impl in 164-06');
    }
}
