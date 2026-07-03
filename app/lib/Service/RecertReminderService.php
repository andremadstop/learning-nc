<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\AppInfo\ConfigDefaults;
use OCA\Learning\Db\Certificate;
use OCA\Learning\Db\CertificateMapper;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\RecertReminderMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Sends T-30 / T-7 cert-expiry reminder notifications (RECERT-06).
 *
 * Idempotency contract:
 *   For each active cert (owns its idem slot, not revoked, not anonymized) inside the reminder
 *   window (expires_at within max(threshold) days, not yet expired):
 *     1. Compute each threshold boundary as expires_at − N days via DateTimeImmutable::modify()
 *        in the server timezone (DST-safe — never N*86400).
 *     2. When now has reached a boundary, call RecertReminderMapper::insertOnce(certId, N, now):
 *        - true  → first send → create Notification (IManager) → subject: 'recert_reminder'
 *        - false → UNIQUE(cert_id, threshold_days) hit ("already sent") → skip
 *
 * INotificationManager is the primary, mail-less-safe channel (RECERT-06): every reminder lands
 * in the NC bell even for accounts without an email address.
 *
 * Clock: ITimeFactory is always injected — never call time() directly in testable date logic.
 * Thresholds: IConfig app value 'recert_reminder_thresholds' (CSV of days), default
 * ConfigDefaults::RECERT_REMINDER_THRESHOLDS = [30, 7].
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
        private readonly CourseMapper $courseMapper,
    ) {}

    /**
     * Scan all expiring certs and send T-30/T-7 reminders. Returns count of reminders sent.
     *
     * Idempotent: safe to call any number of times (hourly job, re-runs, crash-retries);
     * UNIQUE(cert_id, threshold_days) in learning_recert_reminders prevents double-sends.
     * Per-cert isolation: one failing cert (logged) never blocks the rest of the sweep.
     */
    public function sendRecertReminders(): int {
        $now = $this->timeFactory->getTime();
        $thresholds = $this->thresholds();
        if ($thresholds === []) {
            return 0;
        }

        // Window: certs expiring within max(threshold) days, strictly not yet expired —
        // expired certs are the RecertPeriodCloseJob's territory, not the reminder's.
        $certs = $this->certMapper->findActiveExpiringBetween($now, $now + max($thresholds) * 86400);

        $sent = 0;
        foreach ($certs as $cert) {
            try {
                $sent += $this->remindCert($cert, $thresholds, $now);
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'sendRecertReminders: cert {cert} failed, retrying next run: {msg}',
                    ['cert' => (int)$cert->getId(), 'msg' => $e->getMessage()]
                );
            }
        }
        return $sent;
    }

    /**
     * A pending claim (delivered_at NULL) older than this is considered failed/crashed and is
     * retried. Well above any realistic notify() latency, well below the daily job cadence —
     * a same-instant concurrent runner is never double-delivered, a failed delivery is
     * retried on the next daily run.
     */
    private const PENDING_RETRY_AFTER_SECONDS = 3600;

    /**
     * Fire every threshold that `now` has reached for this cert. Outbox semantics (164-07
     * review pass 2): insertOnce = CLAIM, markDelivered = PROOF. A claim whose delivery failed
     * (or whose runner crashed) leaves delivered_at NULL and is retried once stale — the row
     * is never treated as "sent" just because it exists. This survives even the failure mode
     * where compensation itself would fail (the old delete-on-error approach did not).
     *
     * @param int[] $thresholds days-before-expiry values, e.g. [30, 7]
     */
    private function remindCert(Certificate $cert, array $thresholds, int $now): int {
        $exp = $cert->getExpiresAt();
        if ($exp === null) {
            return 0;
        }
        $tz = new \DateTimeZone(date_default_timezone_get());
        $certId = (int)$cert->getId();
        $sent = 0;
        foreach ($thresholds as $days) {
            // DST-safe boundary: expires_at − N calendar days in the server timezone.
            $fireAt = (new \DateTimeImmutable('@' . $exp))
                ->setTimezone($tz)
                ->modify('-' . $days . ' days')
                ->getTimestamp();
            if ($now < $fireAt) {
                continue; // boundary not reached yet
            }

            $existing = $this->reminderMapper->findByCertAndThreshold($certId, $days);
            if ($existing !== null) {
                if ($existing->getDeliveredAt() !== null) {
                    continue; // delivered — permanent skip
                }
                if ($now - (int)$existing->getSentAt() < self::PENDING_RETRY_AFTER_SECONDS) {
                    continue; // fresh claim — most likely a concurrent runner mid-delivery
                }
                // stale pending claim (earlier delivery failed/crashed) → retry below
            } elseif (!$this->reminderMapper->insertOnce($certId, $days, $now)) {
                continue; // lost the claim race to a concurrent runner — leave it to them
            }

            // notify() is NC-level deduped (getCount on the identical notification), so even
            // the markDelivered-throws-after-successful-notify edge cannot double-bell.
            $this->notify($cert, $days);
            $this->reminderMapper->markDelivered($certId, $days, $now);
            $sent++;
        }
        return $sent;
    }

    /**
     * One NC bell notification per (cert, threshold). The course title is resolved here
     * (sender-side, mirrors the compliance_reminder pattern) so the Notifier stays dumb.
     */
    private function notify(Certificate $cert, int $thresholdDays): void {
        $courseTitle = '';
        try {
            $courseTitle = (string)$this->courseMapper->findById($cert->getCourseId())->getTitle();
        } catch (\Throwable $e) {
            // course deleted/unresolvable — reminder still goes out, just without the title
        }

        $notification = $this->notificationManager->createNotification();
        $notification->setApp(self::APP_ID)
            ->setUser((string)$cert->getUserId())
            // objectId carries cert + threshold so T-30 and T-7 are distinct notifications
            ->setObject('recert_reminder', $cert->getVerificationId() . ':' . $thresholdDays)
            ->setSubject('recert_reminder', [
                'course_title' => $courseTitle,
                'days_left'    => $thresholdDays,
                'expires_at'   => $cert->getExpiresAt(),
            ]);

        // NC-level dedupe (mirrors IssuanceService::notify): the identical notification already
        // in the bell means an earlier delivery DID succeed but markDelivered failed/crashed —
        // do not double-bell on the retry.
        if ($this->notificationManager->getCount($notification) === 0) {
            $notification->setDateTime($this->timeFactory->getDateTime());
            $this->notificationManager->notify($notification);
        }
    }

    /**
     * Reminder thresholds in days-before-expiry, descending — the SHARED parser
     * (ConfigDefaults::reminderThresholds) so the send decision and the lifecycle
     * 'expiring' window can never diverge (164-07 review MED 5).
     *
     * @return int[]
     */
    private function thresholds(): array {
        return ConfigDefaults::reminderThresholds($this->config);
    }
}
