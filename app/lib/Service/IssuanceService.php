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
 * Flow (issueIfPassedResult, called only by PassCriteriaService::evaluate() after mayIssue()):
 *   1. Bail if the result is not a pass.
 *   2. Build the OB3/VC JSON-LD with EVERY field frozen at signing time (CERT-06): course
 *      title/description, score, threshold, validFrom, validUntil (computeExpiry), issuer
 *      identity + theming branding (CERT-11), and the verification-id.
 *   3. Sign it via SigningService with the active key (KeyService::getActiveSigningMaterial()).
 *   4. Persist the Certificate (credential_json = the compact VC-JWT) together with its
 *      CERT_ISSUED compliance event, in one transaction.
 *   5. Fire exactly one NC notification (deduped via getCount).
 *
 * DSGVO: the subject identifier is the issuer's display branding only — NO plaintext email is
 * ever embedded. The student's identity in the credential is intentionally minimal.
 *
 * Concurrency: the UNIQUE index on active_idem_key is the idempotency guard — of two concurrent
 * passes only one INSERT wins; the loser dedupes onto the winner (wasCreated=false). Revocation
 * and period close free the slot, so a re-issue stays possible.
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
     * Issue a signed credential for a passing student. Returns an IssueResult that exposes WHETHER
     * this call created the certificate row (wasCreated=true) or deduped onto a concurrent winner
     * (wasCreated=false). The only issuance path: PassCriteriaService::evaluate() calls it after
     * mayIssue() (RECERT-05 union guard). The deprecated issueIfPassed(), which had no such guard
     * and could re-issue over a punitive revocation, is gone (audit #228).
     *
     *   1. NO !revoked pre-check: closePeriod() nulls active_idem_key to free the slot for a
     *      new period. The UNIQUE constraint (active_idem_key) is the ONLY idempotency guard.
     *   2. UNIQUE-constraint loser path returns wasCreated=false — the caller (PassCriteriaService)
     *      uses this to skip the COURSE_PASSED emit (RECERT-06 concurrency guarantee).
     *
     * PassCriteriaService uses wasCreated in 164-04 to decide whether to emit COURSE_PASSED:
     * only the WINNER emits; the UNIQUE-loser dedupes silently so no duplicate audit row appears.
     *
     * Renewal (GitHub #35): $renewing is the learner's current certificate, passed ONLY by
     * PassCriteriaService when its renewal window is open (the first reminder opened it). This
     * method never decides on its own that a call is a renewal — without $renewing a first
     * issuance and a closed-period (Branch B) issuance behave exactly as before. With it:
     *   - inside the transaction, CertificateMapper::releaseSlot() first takes the slot from the
     *     old certificate (CAS on id + slot value); 0 rows = a concurrent renewal won → loser
     *     path: the winner is returned with wasCreated=false, no audit, no notification;
     *   - the old certificate keeps expires_at and revoked: it is superseded, not withdrawn, and
     *     stays valid until its own expiry;
     *   - the new validity is anchored on max(now, old expiry), so renewing early costs no days;
     *     validFrom in the credential stays the issue time;
     *   - CERT_ISSUED carries `renews` => the old verification id (a verification id is already
     *     a CERT_ISSUED fact, no PII; context_json is hashed into the chain as stored).
     */
    public function issueIfPassedResult(string $userId, int $courseId, PassResult $result, ?Certificate $renewing = null): ?IssueResult {
        if (!$result->isPassed()) {
            return null;
        }
        // NO !revoked guard here (RECERT-05): closePeriod() nulls active_idem_key, freeing
        // the UNIQUE slot. The INSERT below is the CAS gate — the winner inserts, the loser
        // catches REASON_UNIQUE_CONSTRAINT_VIOLATION and returns wasCreated=false.

        $course = $this->courseMapper->findById($courseId);
        $idemKey = $userId . ':' . $courseId;

        $issuedAt = $this->timeFactory->getTime();
        // DST-safe expiry from the course's validity (RECERT-01, 164-04); there is no
        // per-assignment override (audit #162).
        // Renewal: the new term continues from the old expiry when that is still ahead.
        $anchor = $issuedAt;
        if ($renewing !== null && $renewing->getExpiresAt() !== null) {
            $anchor = max($issuedAt, (int)$renewing->getExpiresAt());
        }
        $expiresAt = $this->computeExpiry($anchor, $course);
        $verificationId = $this->uuidv4();
        $recipientName = $this->resolveDisplayName($userId);

        $credential = $this->buildCredential($verificationId, $courseId, $course, $result, $recipientName, $issuedAt, $expiresAt);

        $material = $this->activeSigningMaterial();
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
        $cert->setActiveIdemKey($idemKey);

        $auditContext = [
            'course_id'       => $courseId,
            'verification_id' => $verificationId,
        ];
        if ($renewing !== null) {
            $auditContext['renews'] = (string)$renewing->getVerificationId();
        }

        $lostRenewal = false;
        $this->db->beginTransaction();
        try {
            if ($renewing !== null
                && $this->certificateMapper->releaseSlot((int)$renewing->getId(), $idemKey) === 0) {
                // A concurrent renewal already took the slot from the old certificate. Release
                // only our (empty) savepoint; the re-fetch runs after the try, so a failing read
                // can never roll back the caller's outer transaction from the catch below.
                $lostRenewal = true;
                $this->db->rollBack();
            }
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        if ($lostRenewal && $renewing !== null) {
            // Loser path: dedupe onto the winner — no audit, no notification. Usually a concurrent
            // renewal; it can also be the daily close job closing the period in between, in which
            // case nothing was renewed and the next period counts only its own exams — say so.
            $winner = $this->certificateMapper->findByUserAndCourse($userId, $courseId);
            $this->logger->info('Renewal of certificate {vid} for user {user} course {course} found its slot already released; nothing issued', [
                'vid'    => (string)$renewing->getVerificationId(),
                'user'   => $userId,
                'course' => $courseId,
            ]);
            return new IssueResult($winner ?? $renewing, false);
        }

        try {
            $stored = $this->certificateMapper->insert($cert);
            $this->auditService->logComplianceEvent(ComplianceEventTypes::CERT_ISSUED, $userId, $auditContext);
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
        $this->notify($userId, $verificationId, (string)$course->getTitle(), $courseId);
        $this->logger->info('Issued certificate {vid} for user {user} course {course}', [
            'vid'    => $verificationId,
            'user'   => $userId,
            'course' => $courseId,
        ]);

        return new IssueResult($stored, true);
    }

    /**
     * The active issuer key and its secret. Any failure to obtain them (no active key, a key
     * that does not decrypt) becomes a SigningUnavailableException, so callers can tell an
     * issuer-key problem apart from other issuance failures (GitHub #35).
     *
     * @return array{key: CertKey, secret: string}
     */
    private function activeSigningMaterial(): array {
        try {
            return $this->keyService->getActiveSigningMaterial();
        } catch (\RuntimeException $e) {
            // Only what getActiveSigningMaterial() itself raises — no key, undecryptable key,
            // invalid key. A database error (OCP\DB\Exception is not a RuntimeException) or any
            // other failure propagates and is reported as issuance_failed, so a transient outage
            // does not send the administrator after a key that is fine.
            throw new SigningUnavailableException('Certificate signing unavailable: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * DST-safe expiry computation (RECERT-01/02) — implemented in Wave 4 (164-04),
     * hardened in the post-impl review (HIGH 4 + MED 6).
     *
     * Precedence (the course alone decides; there is no per-assignment override — audit #162):
     *   1. course.cert_validity_months — EXPLICIT config: > 0 → that many calendar months,
     *      <= 0 → null (no expiry)
     *   2. LEGACY fallback when cert_validity_months is NULL (unset): honor the pre-164
     *      cert_validity_days contract — days > 0 → calendar days, days 0/NULL → no expiry.
     *      HIGH 4: without this fallback every legacy course (months=NULL, days=0 = "no
     *      expiry") silently became a 12-month expiring cert. There is NO implicit 12-month
     *      default — expiring validity is always an explicit per-course choice.
     *
     * MUST use DateTimeImmutable::modify() — NEVER +N*86400. The naive seconds formula
     * diverges by 3600s across a DST boundary (e.g. issuing on 2026-03-29 in Europe/Berlin).
     *
     * MED 6 (end-of-month clamp): '+N months' on Jan 31 would overflow into Mar 2/3 and grant
     * extra validity. addCalendarMonths() anchors on the 1st, adds months, then clamps the day
     * to the target month's last day (Jan 31 + 1 month → Feb 28/29).
     *
     * Uses the server's configured timezone (date_default_timezone_get()) so that the expiry
     * wall-clock time is stable across DST transitions for the server locale.
     */
    private function computeExpiry(int $issuedAt, Course $course): ?int {
        $months = $course->getCertValidityMonths();
        if ($months !== null) {
            if ($months <= 0) {
                return null; // explicit "no expiry"
            }
            return $this->addCalendarMonths($issuedAt, $months);
        }

        // Legacy fallback: cert_validity_months unset → pre-164 days semantics (0 = no expiry).
        $days = $course->getCertValidityDays() ?? 0;
        if ($days <= 0) {
            return null;
        }
        $tz = new \DateTimeZone(date_default_timezone_get());
        return (new \DateTimeImmutable('@' . $issuedAt))
            ->setTimezone($tz)
            ->modify('+' . $days . ' days')
            ->getTimestamp();
    }

    /**
     * Add N calendar months to a unix timestamp, DST-safe and end-of-month clamped.
     *
     * Anchor-on-the-1st avoids PHP's month overflow: 2026-01-31 '+1 months' would yield
     * Mar 3; here it yields 2026-02-28 (same wall-clock time). For non-EOM dates the result
     * is identical to a plain modify('+N months').
     */
    private function addCalendarMonths(int $ts, int $months): int {
        $tz = new \DateTimeZone(date_default_timezone_get());
        $base = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz);
        $anchor = $base->modify('first day of this month')->modify('+' . $months . ' months');
        $day = min((int)$base->format('j'), (int)$anchor->format('t'));
        return $anchor
            ->setDate((int)$anchor->format('Y'), (int)$anchor->format('n'), $day)
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
     * Deduped via getCount(), as the retired NotificationJob did.
     *
     * BEST-EFFORT (post-impl-review HIGH 3): a throwing notification backend must NEVER turn a
     * committed issuance into an apparent failure — the caller (PassCriteriaService) would then
     * swallow the throw and skip the COURSE_PASSED emit + markPassed for a cert that EXISTS,
     * and no later call could repair it (UNIQUE loser path never emits). Notification failure
     * is logged and dropped; the compliance write-set always wins over the convenience ping.
     */
    private function notify(string $userId, string $verificationId, string $courseTitle, int $courseId): void {
        try {
            $notification = $this->notificationManager->createNotification();
            // course_id lets the Notifier link straight to the course's completion tab (GitHub #35).
            $notification->setApp('learning')
                ->setUser($userId)
                ->setObject('certificate', $verificationId)
                ->setSubject('certificate_issued', ['course_title' => $courseTitle, 'course_id' => $courseId]);

            if ($this->notificationManager->getCount($notification) === 0) {
                $notification->setDateTime($this->timeFactory->getDateTime());
                $this->notificationManager->notify($notification);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Certificate notification failed for {vid}: {msg}', [
                'vid' => $verificationId,
                'msg' => $e->getMessage(),
            ]);
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
            $logo = $this->urlGenerator->imagePath('learning', 'app-color.svg');
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
 * PassCriteriaService inspects wasCreated to decide whether to emit COURSE_PASSED.
 * Only the WINNER (wasCreated=true) emits; the loser path dedupes silently.
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
