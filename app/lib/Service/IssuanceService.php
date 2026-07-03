<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\CertKey;
use OCA\Learning\Db\Certificate;
use OCA\Learning\Db\CertificateMapper;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Defaults;
use Psr\Log\LoggerInterface;

/**
 * IssuanceService — turns a "passed" PassResult into a signed, stored, self-contained
 * OB3/VC credential plus a single student notification (Phase 155, CERT-05/06/11/12).
 *
 * Flow (issueIfPassed):
 *   1. Bail if the result is not a pass.
 *   2. OWN idempotency guard — findByUserAndCourse(); a non-revoked existing cert is returned
 *      verbatim (NO re-issue). This is independent of the 154 audit-event guard.
 *   3. Build the OB3/VC JSON-LD with EVERY field frozen at signing time (CERT-06): course
 *      title/description, score, threshold, validFrom, validUntil (from cert_validity_days),
 *      issuer identity + theming branding (CERT-11), and the verification-id.
 *   4. Sign it via SigningService with the active key (KeyService::getActiveSigningMaterial()).
 *   5. Persist the Certificate (credential_json = the compact VC-JWT).
 *   6. Fire exactly one NC notification (deduped via getCount).
 *
 * DSGVO: the subject identifier is the issuer's display branding only — NO plaintext email is
 * ever embedded. The student's identity in the credential is intentionally minimal.
 *
 * Concurrency: the SELECT-then-INSERT idempotency guard is NOT atomic (no UNIQUE constraint —
 * revoke + re-issue must stay possible). A rare concurrent double-issue is deduped ON READ by
 * callers (the earliest non-revoked cert wins). This consciously inherits the accepted 154 race.
 */
class IssuanceService {
    private CertificateMapper $certificateMapper;
    private CourseMapper $courseMapper;
    private SigningService $signingService;
    private KeyService $keyService;
    private INotificationManager $notificationManager;
    private Defaults $themingDefaults;
    private IURLGenerator $urlGenerator;
    private IUserManager $userManager;
    private ITimeFactory $timeFactory;
    private LoggerInterface $logger;
    private AuditService $auditService;
    private IDBConnection $db;

    public function __construct(
        CertificateMapper $certificateMapper,
        CourseMapper $courseMapper,
        SigningService $signingService,
        KeyService $keyService,
        INotificationManager $notificationManager,
        Defaults $themingDefaults,
        IURLGenerator $urlGenerator,
        IUserManager $userManager,
        ITimeFactory $timeFactory,
        LoggerInterface $logger,
        AuditService $auditService,
        IDBConnection $db
    ) {
        $this->certificateMapper = $certificateMapper;
        $this->courseMapper = $courseMapper;
        $this->signingService = $signingService;
        $this->keyService = $keyService;
        $this->notificationManager = $notificationManager;
        $this->themingDefaults = $themingDefaults;
        $this->urlGenerator = $urlGenerator;
        $this->userManager = $userManager;
        $this->timeFactory = $timeFactory;
        $this->logger = $logger;
        $this->auditService = $auditService;
        $this->db = $db;
    }

    /**
     * Issue (once) a signed credential for a passing student, or return the existing one.
     *
     * @return Certificate|null The issued/existing certificate, or null when not passed.
     */
    public function issueIfPassed(string $userId, int $courseId, PassResult $result): ?Certificate {
        if (!$result->isPassed()) {
            return null;
        }

        // OWN idempotency guard (not the 154 audit guard): a non-revoked cert already exists →
        // return it, never re-issue. A revoked newest cert falls through so re-issue stays possible.
        $existing = $this->certificateMapper->findByUserAndCourse($userId, $courseId);
        if ($existing !== null && !$existing->getRevoked()) {
            return $existing;
        }

        $course = $this->courseMapper->findById($courseId);

        $issuedAt = $this->timeFactory->getTime();
        // DST-safe expiry via cert_validity_months (RECERT-01, 164-04). Replaces the old
        // +validityDays*86400 formula. Override arg is null here (override is per-assignment;
        // issueIfPassed is the self-enrolled / direct path).
        $expiresAt = $this->computeExpiry($issuedAt, $course, null);
        $verificationId = $this->uuidv4();
        $recipientName = $this->resolveDisplayName($userId);

        $credential = $this->buildCredential($verificationId, $courseId, $course, $result, $recipientName, $issuedAt, $expiresAt);

        $material = $this->keyService->getActiveSigningMaterial();
        /** @var CertKey $key */
        $key = $material['key'];
        // Secret zeroing must survive a sign() throw → try/finally. Pass the secret straight from
        // $material so there is no second live copy to forget; sodium_memzero clears it in place.
        try {
            $jwt = $this->signingService->sign($credential, $key, $material['secret']);
        } finally {
            sodium_memzero($material['secret']);
        }

        $cert = new Certificate();
        $cert->setVerificationId($verificationId);
        $cert->setUserId($userId);
        $cert->setCourseId($courseId);
        $cert->setKeyId($key->getKeyId());
        $cert->setCredentialJson($jwt);
        $cert->setRevoked(false);
        $cert->setIssuedAt($issuedAt);
        $cert->setExpiresAt($expiresAt);
        // Atomic idempotency slot: the UNIQUE index on active_idem_key turns the racy
        // SELECT-then-INSERT guard above into a hard DB-level guarantee — two concurrent passes for
        // the same (user,course) can no longer both produce a valid cert.
        // NOTE: revocation (Phase 156/157) MUST set active_idem_key = NULL to free this slot for re-issue.
        $cert->setActiveIdemKey($userId . ':' . $courseId);

        // FIX-2 (atomicity, fail-closed): the cert INSERT and its CERT_ISSUED compliance-audit append
        // commit or roll back TOGETHER. If the audit append throws (ComplianceAuditException), the
        // whole transaction rolls back so no orphan cert (one without a chained audit event) can ever
        // exist, and the error propagates (HTTP 500). logComplianceEvent opens its own CAS transaction —
        // NC nests it via a savepoint (Connection::setNestTransactionsWithSavepoints(true)), so this is safe.
        $this->db->beginTransaction();
        try {
            $stored = $this->certificateMapper->insert($cert);

            // AUDIT-03 compliance event — inside the SAME transaction as the INSERT. On the
            // unique-constraint loser path the INSERT throws first, so this never runs for the loser.
            $this->auditService->logComplianceEvent(ComplianceEventTypes::CERT_ISSUED, $userId, [
                'course_id'       => $courseId,
                'verification_id' => $verificationId,
            ]);

            $this->db->commit();
        } catch (\OCP\DB\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                // A concurrent request won the race and already inserted the active cert. Return the
                // winner verbatim (no re-issue, no notification) — the loser dedupes onto it.
                $winner = $this->certificateMapper->findByUserAndCourse($userId, $courseId);
                if ($winner !== null) {
                    return $winner;
                }
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e; // audit failure or other — roll back so no orphan cert survives (fail-closed)
        }

        // Side effects AFTER the atomic commit only — a rolled-back issuance must never notify/log success.
        $this->notify($userId, $verificationId, (string)$course->getTitle());

        $this->logger->info('Issued certificate {vid} for user {user} course {course}', [
            'vid' => $verificationId,
            'user' => $userId,
            'course' => $courseId,
        ]);

        return $stored;
    }

    /**
     * Like issueIfPassed() but returns an IssueResult that exposes WHETHER this call created
     * the certificate row (wasCreated=true) or deduped onto a concurrent winner (wasCreated=false).
     *
     * Key differences from issueIfPassed():
     *   1. NO !revoked pre-check: closePeriod() nulls active_idem_key to free the slot for a
     *      new period. The UNIQUE constraint (active_idem_key) is the ONLY idempotency guard.
     *   2. UNIQUE-constraint loser path returns wasCreated=false — the caller (PassCriteriaService)
     *      uses this to skip the COURSE_PASSED emit (RECERT-06 concurrency guarantee).
     *
     * PassCriteriaService uses wasCreated in 164-04 to decide whether to emit COURSE_PASSED:
     * only the WINNER emits; the UNIQUE-loser dedupes silently so no duplicate audit row appears.
     */
    public function issueIfPassedResult(string $userId, int $courseId, PassResult $result): ?IssueResult {
        if (!$result->isPassed()) {
            return null;
        }
        // NO !revoked guard here (RECERT-05): closePeriod() nulls active_idem_key, freeing
        // the UNIQUE slot. The INSERT below is the CAS gate — the winner inserts, the loser
        // catches REASON_UNIQUE_CONSTRAINT_VIOLATION and returns wasCreated=false.

        $course = $this->courseMapper->findById($courseId);

        $issuedAt = $this->timeFactory->getTime();
        // DST-safe expiry via cert_validity_months (RECERT-01, 164-04). Override arg is null
        // here; recertification override months live in the assignment row and are wired via
        // AssignmentService when closePeriod triggers a fresh issuance.
        $expiresAt = $this->computeExpiry($issuedAt, $course, null);
        $verificationId = $this->uuidv4();
        $recipientName = $this->resolveDisplayName($userId);

        $credential = $this->buildCredential($verificationId, $courseId, $course, $result, $recipientName, $issuedAt, $expiresAt);

        $material = $this->keyService->getActiveSigningMaterial();
        /** @var CertKey $key */
        $key = $material['key'];
        try {
            $jwt = $this->signingService->sign($credential, $key, $material['secret']);
        } finally {
            sodium_memzero($material['secret']);
        }

        $cert = new Certificate();
        $cert->setVerificationId($verificationId);
        $cert->setUserId($userId);
        $cert->setCourseId($courseId);
        $cert->setKeyId($key->getKeyId());
        $cert->setCredentialJson($jwt);
        $cert->setRevoked(false);
        $cert->setIssuedAt($issuedAt);
        $cert->setExpiresAt($expiresAt);
        // CAS slot: the UNIQUE active_idem_key means only the first concurrent INSERT wins.
        // closePeriod() nulls this field to open the slot for the next recertification period.
        $cert->setActiveIdemKey($userId . ':' . $courseId);

        $this->db->beginTransaction();
        try {
            $stored = $this->certificateMapper->insert($cert);
            $this->auditService->logComplianceEvent(ComplianceEventTypes::CERT_ISSUED, $userId, [
                'course_id'       => $courseId,
                'verification_id' => $verificationId,
            ]);
            $this->db->commit();
        } catch (\OCP\DB\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                // UNIQUE-constraint loser: a concurrent winner already inserted the active cert.
                // Re-fetch the winner and return wasCreated=false so the caller skips the emit.
                $winner = $this->certificateMapper->findByUserAndCourse($userId, $courseId);
                if ($winner !== null) {
                    return new IssueResult($winner, false);
                }
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        // Side effects after the atomic commit only — a rolled-back issuance must never notify.
        $this->notify($userId, $verificationId, (string)$course->getTitle());
        $this->logger->info('Issued certificate {vid} for user {user} course {course}', [
            'vid'    => $verificationId,
            'user'   => $userId,
            'course' => $courseId,
        ]);

        return new IssueResult($stored, true);
    }

    /**
     * DST-safe expiry computation (RECERT-01/02) — implemented in Wave 4 (164-04).
     *
     * Validity in months = per-assignment override ?? course.cert_validity_months ?? 12.
     * months <= 0 → null (no expiry).
     *
     * MUST use DateTimeImmutable::modify('+N months') — NEVER +N*86400. The naive
     * seconds formula diverges by 3600s across a DST boundary (e.g. issuing on 2026-03-29 in
     * Europe/Berlin yields a different timestamp via +365*86400 vs +12 months).
     *
     * Uses the server's configured timezone (date_default_timezone_get()) so that the expiry
     * wall-clock time is stable across DST transitions for the server locale.
     */
    private function computeExpiry(int $issuedAt, Course $course, ?int $assignmentOverrideMonths): ?int {
        $months = $assignmentOverrideMonths ?? $course->getCertValidityMonths() ?? 12;
        if ($months <= 0) {
            return null;
        }
        $tz = new \DateTimeZone(date_default_timezone_get());
        return (new \DateTimeImmutable('@' . $issuedAt))
            ->setTimezone($tz)
            ->modify('+' . $months . ' months')
            ->getTimestamp();
    }

    /**
     * Build the self-contained OB3/VC 2.0 credential object (CERT-06). Every value here is
     * frozen into the signed payload — the credential never reads back from the DB to verify.
     *
     * @param \OCA\Learning\Db\Course $course
     * @return array<string, mixed>
     */
    private function buildCredential(
        string $verificationId,
        int $courseId,
        $course,
        PassResult $result,
        string $recipientName,
        int $issuedAt,
        ?int $expiresAt
    ): array {
        $threshold = $result->getThreshold();
        $score = $result->getScore();

        $credential = [
            '@context' => [
                'https://www.w3.org/ns/credentials/v2',
                'https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json',
            ],
            'id' => 'urn:uuid:' . $verificationId,
            'type' => ['VerifiableCredential', 'OpenBadgeCredential'],
            'issuer' => [
                'id' => $this->keyService->hostDid(),
                'type' => ['Profile'],
                'name' => $this->themingDefaults->getName(),
                'image' => [
                    'id' => $this->absoluteLogoUrl(),
                    'type' => 'Image',
                ],
            ],
            'validFrom' => $this->iso8601($issuedAt),
        ];

        if ($expiresAt !== null) {
            $credential['validUntil'] = $this->iso8601($expiresAt);
        }

        $credential['credentialSubject'] = [
            'type' => ['AchievementSubject'],
            // Recipient identity is the display name ONLY (CERT-06 / plan <interfaces>): a
            // recipient-bound certificate without leaking a plaintext email (DSGVO).
            'name' => $recipientName,
            'achievement' => [
                'id' => 'urn:learning:course:' . $courseId,
                'type' => ['Achievement'],
                'name' => (string)$course->getTitle(),
                'description' => (string)($course->getDescription() ?? ''),
                'criteria' => [
                    'narrative' => sprintf(
                        'Passed with score >= %d%% (lucky guesses excluded).',
                        $threshold
                    ),
                ],
            ],
            'result' => [[
                'type' => ['Result'],
                'resultDescription' => sprintf(
                    'score:%s; threshold:%d',
                    $score === null ? '' : (string)$score,
                    $threshold
                ),
            ]],
        ];

        return $credential;
    }

    /**
     * Fire exactly one NC notification for the freshly issued certificate (CERT-12).
     * Deduped via getCount() (mirrors NotificationJob::sendNotification).
     */
    private function notify(string $userId, string $verificationId, string $courseTitle): void {
        $notification = $this->notificationManager->createNotification();
        $notification->setApp('learning')
            ->setUser($userId)
            ->setObject('certificate', $verificationId)
            ->setSubject('certificate_issued', ['course_title' => $courseTitle]);

        if ($this->notificationManager->getCount($notification) === 0) {
            $notification->setDateTime($this->timeFactory->getDateTime());
            $this->notificationManager->notify($notification);
        }
    }

    /**
     * Neutral DSGVO fallback recipient name. Used when the account is unresolvable, has no display
     * name, or whose only candidate identifier is email-shaped. A backend constant (NOT a t() key —
     * IL10N is not injected here) so it never depends on request locale.
     */
    private const FALLBACK_RECIPIENT = 'Teilnehmer:in';

    /**
     * The recipient's NC display name, frozen into the credential as its only PII (DSGVO: display
     * name, never an email). NC user-ids can themselves BE emails, so a raw userId is NEVER returned;
     * an unresolvable account, an empty display name, or an email-shaped name all map to the neutral
     * pseudonym fallback so no plaintext email lands in the signed, shareable credential (R3-7).
     */
    private function resolveDisplayName(string $userId): string {
        $user = $this->userManager->get($userId);
        $name = $user !== null ? $user->getDisplayName() : '';
        if ($name === '' || $this->looksLikeEmail($name)) {
            return self::FALLBACK_RECIPIENT;
        }
        return $name;
    }

    /**
     * True when the candidate CONTAINS an email-shaped token (local@domain.tld) ANYWHERE — not just
     * as the whole string. The candidate is trimmed first and the pattern is unanchored, so surrounding
     * whitespace (" alice@example.com "), a display-name wrapper ("Alice <alice@example.com>") or a
     * prefix ("contact: a@b.de") can never smuggle a plaintext email into the signed credential (R3-7).
     */
    private function looksLikeEmail(string $candidate): bool {
        return preg_match('/[^@\s]+@[^@\s]+\.[^@\s]+/', trim($candidate)) === 1;
    }

    /**
     * The themed issuer logo as an absolute URL (CERT-11). Falls back to the app icon if the
     * instance has no custom logo. Absolute URLs from theming are passed through untouched.
     */
    private function absoluteLogoUrl(): string {
        $logo = $this->themingDefaults->getLogo();
        if ($logo === '') {
            $logo = $this->urlGenerator->imagePath('learning', 'app.svg');
        }
        if (str_starts_with($logo, 'http://') || str_starts_with($logo, 'https://')) {
            return $logo;
        }
        return $this->urlGenerator->getAbsoluteURL($logo);
    }

    /**
     * ISO 8601 UTC timestamp (e.g. 2026-06-27T12:00:00Z) for validFrom / validUntil.
     */
    private function iso8601(int $unix): string {
        return gmdate('Y-m-d\TH:i:s\Z', $unix);
    }

    /**
     * RFC 4122 UUIDv4 from random_bytes(16) — no ext-uuid dependency.
     */
    private function uuidv4(): string {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        /** @var string[] $chunks */
        $chunks = str_split(bin2hex($b), 4);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', $chunks);
    }
}

/**
 * Return type of IssuanceService::issueIfPassedResult().
 *
 * certificate — the issued (or deduped-winner) Certificate row.
 * wasCreated  — true when THIS call was the row creator; false when the DB UNIQUE constraint
 *               (active_idem_key) fired and this call deduped onto a concurrent winner.
 *
 * PassCriteriaService inspects wasCreated in 164-04 to decide whether to emit COURSE_PASSED.
 * Only the WINNER (wasCreated=true) emits; the loser path dedupes silently.
 *
 * SKELETON note (164-02): IssuanceService::issueIfPassedResult() always sets wasCreated=true
 * at this wave. The concurrency test (testConcurrentPassSingleEventAndCert) is genuinely RED
 * against this skeleton and will stay RED until 164-04 wires the winner/loser distinction.
 *
 * Placement: defined in IssuanceService.php so it is always loaded when IssuanceService is
 * referenced, without requiring a separate PSR-4 file at Wave 2.
 */
final class IssueResult {
    public function __construct(
        public readonly Certificate $certificate,
        public readonly bool $wasCreated,
    ) {}
}
