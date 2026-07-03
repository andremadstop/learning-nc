<?php
declare(strict_types=1);

namespace OCA\Learning\AppInfo;

/**
 * Policy defaults for the cert-lifecycle loop (Phase 164).
 *
 * All string constants feed IConfig->getAppValue() calls, e.g.:
 *   $years = (int) $config->getAppValue('learning', 'retention_years', ConfigDefaults::RETENTION_YEARS_DEFAULT);
 *
 * Instance-level overrides are stored as IConfig app values (key: 'learning').
 * Per-course validity lives in learning_courses.cert_validity_months (Version009600).
 *
 * ⚠ RETENTION_YEARS_DEFAULT is locked at 3 years (research suggested 5; conservative
 *   choice pending AWO/DSGVO confirmation). Art.17(3)(b) ArbSchG/AGG requires multi-year
 *   retention for compliance evidence. Confirm the final default before production rollout.
 */
final class ConfigDefaults {
    /**
     * How many years to retain cert/audit/assignment data before crypto-erasure (DSGVO-03).
     *
     * Config key: 'retention_years' (IConfig app value, app='learning').
     * Locked at 3; FLAGGED for AWO/DSGVO confirmation (research suggested 5 per Art.17(3)(b)).
     */
    public const RETENTION_YEARS_DEFAULT = '3';

    /**
     * Grace period in days after cert expiry before status flips to "overdue" (RECERT-03).
     *
     * Config key: 'recert_grace_days' (IConfig app value, app='learning').
     */
    public const RECERT_GRACE_DAYS_DEFAULT = '14';

    /**
     * Suggested cert validity in months for the admin UI (RECERT-02) — a FORM PREFILL, not a
     * runtime fallback.
     *
     * ⚠ Post-impl review HIGH 4: IssuanceService::computeExpiry() deliberately has NO implicit
     * months default. cert_validity_months = NULL means "unset" → the legacy cert_validity_days
     * contract governs (0/NULL = no expiry). An automatic 12-month fallback would silently turn
     * every legacy no-expiry course into an expiring one. Expiring validity is ALWAYS an
     * explicit per-course (or per-assignment override) choice; this constant may only ever be
     * used to prefill the months field in the course settings UI (164-07).
     */
    public const CERT_VALIDITY_MONTHS_DEFAULT = 12;

    /**
     * Days-before-expiry thresholds at which reminder notifications are sent (RECERT-06).
     *
     * Reminder idempotency is backed by learning_recert_reminders UNIQUE(cert_id, threshold_days).
     *
     * @var int[]
     */
    public const RECERT_REMINDER_THRESHOLDS = [30, 7];

    /**
     * SINGLE parser for the reminder thresholds (164-07 review MED 5): RecertReminderService
     * (send decision) and CertificateVerifyService::deriveLifecycleState ('expiring' window)
     * MUST agree — a cert that gets a reminder while the UI still reads 'valid' is a
     * contradiction. Config key 'recert_reminder_thresholds' (CSV of positive days);
     * empty/garbage values fall back to the shipped defaults. Descending order.
     *
     * @return int[] never empty
     */
    public static function reminderThresholds(\OCP\IConfig $config): array {
        $raw = $config->getAppValue(
            'learning', 'recert_reminder_thresholds', implode(',', self::RECERT_REMINDER_THRESHOLDS)
        );
        $days = array_values(array_filter(
            array_map('intval', explode(',', $raw)),
            static fn (int $d): bool => $d > 0
        ));
        if ($days === []) {
            $days = self::RECERT_REMINDER_THRESHOLDS; // empty/garbage config → shipped defaults
        }
        rsort($days);
        return $days;
    }

    /**
     * SINGLE parser for the recert grace window (164-07 review HIGH 4): a NEGATIVE
     * 'recert_grace_days' would flip the cutoff into the future — closeExpiredPeriods would
     * close still-valid certs and deriveLifecycleState would mark unexpired certs 'overdue'.
     * Negatives fall back to the shipped default.
     */
    public static function graceDays(\OCP\IConfig $config): int {
        $days = (int)$config->getAppValue(
            'learning', 'recert_grace_days', self::RECERT_GRACE_DAYS_DEFAULT
        );
        return $days >= 0 ? $days : (int)self::RECERT_GRACE_DAYS_DEFAULT;
    }
}
