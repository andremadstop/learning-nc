<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\CertKey;
use OCA\Learning\Db\Certificate;
use OCA\Learning\Db\CertificateMapper;
use OCA\Learning\Db\CourseMapper;
use OCP\AppFramework\Utility\ITimeFactory;
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
        LoggerInterface $logger
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
        $validityDays = $course->getCertValidityDays() ?? 0;
        $expiresAt = $validityDays > 0 ? $issuedAt + $validityDays * 86400 : null;
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
        try {
            $stored = $this->certificateMapper->insert($cert);
        } catch (\OCP\DB\Exception $e) {
            if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                // A concurrent request won the race and already inserted the active cert. Return the
                // winner verbatim (no re-issue, no notification) — the loser dedupes onto it.
                $winner = $this->certificateMapper->findByUserAndCourse($userId, $courseId);
                if ($winner !== null) {
                    return $winner;
                }
            }
            throw $e;
        }

        $this->notify($userId, $verificationId, (string)$course->getTitle());

        $this->logger->info('Issued certificate {vid} for user {user} course {course}', [
            'vid' => $verificationId,
            'user' => $userId,
            'course' => $courseId,
        ]);

        return $stored;
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
     * The recipient's NC display name, frozen into the credential as its only PII (DSGVO:
     * display name, never the email). Falls back to the user id if the account is unresolvable.
     */
    private function resolveDisplayName(string $userId): string {
        $user = $this->userManager->get($userId);
        if ($user === null) {
            return $userId;
        }
        $name = $user->getDisplayName();
        return $name !== '' ? $name : $userId;
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
