<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\CertKey;
use OCA\Learning\Db\Certificate;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CertificateMapper;
use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\ComplianceAuditException;
use OCA\Learning\Service\ComplianceEventTypes;
use OCA\Learning\Service\IssuanceService;
use OCA\Learning\Service\KeyService;
use OCA\Learning\Service\PassResult;
use OCA\Learning\Service\SigningService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use OCP\Defaults;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * IssuanceService — turns a "passed" PassResult into a signed, stored, self-contained
 * OB3/VC credential plus a single student notification (155-04, CERT-05/06/11/12).
 *
 * Strategy: the REAL SigningService is used with a throwaway sodium keypair so the test can
 * decode the actual signed payload and assert the credential is self-contained (CERT-06).
 * The mapper / notification / theming collaborators are mocked.
 *
 * Behaviours:
 *  1. issue-once     — passed + no existing cert → exactly one Certificate inserted; 3-segment JWT.
 *  2. idempotent     — existing non-revoked cert → returned, NO second insert.
 *  3. not-passed     — passed=false → null, no insert, no signing.
 *  4. self-contained — decoded payload carries course title, threshold, validFrom; validUntil
 *                      present when cert_validity_days>0 and ABSENT when 0.
 *  5. branding       — issuer.name == themed name; issuer.image.id == themed logo URL.
 */
class IssuanceServiceTest extends TestCase {

    private const HOST_DID = 'did:web:example.com:apps:learning';
    private const USER = 'alice';
    private const COURSE_ID = 7;
    private const ISSUED_AT = 1750000000;

    private string $publicRaw;
    private string $secretRaw;
    private CertKey $key;

    protected function setUp(): void {
        if (!extension_loaded('sodium')) {
            $this->markTestSkipped('ext-sodium not loaded');
        }
        $keypair = sodium_crypto_sign_keypair();
        $this->publicRaw = sodium_crypto_sign_publickey($keypair);
        $this->secretRaw = sodium_crypto_sign_secretkey($keypair);

        $this->key = new CertKey();
        $this->key->setKeyId('PUBKEY_kid_b64u');
        $this->key->setPublicKeyB64u('PUBKEY_kid_b64u');
        $this->key->setStatus('active');
        $this->key->setCreatedAt(1700000000);
    }

    private function makeCourse(int $validityDays = 0): Course {
        $course = new Course();
        $course->setTitle('Security+ Kurs');
        $course->setDescription('Compliance-Grundlagen');
        $course->setCertValidityDays($validityDays);
        return $course;
    }

    private function passResult(bool $passed = true, ?int $score = 92, int $threshold = 80): PassResult {
        return new PassResult($passed, $score, $threshold, true, $passed ? self::ISSUED_AT : null);
    }

    /**
     * @param Certificate|null $existing  what findByUserAndCourse returns
     * @param-out Certificate|null $captured  the entity passed to insert() (by reference holder)
     */
    private function makeService(
        ?Certificate $existing,
        ?Course $course,
        ?object &$capture,
        string $logoUrl = 'https://cloud.example/logo.png',
        string $issuerName = 'DevCloud Academy',
        bool $expectInsert = true,
        bool $expectNotify = true,
        string $displayName = 'Alice Example',
        ?AuditService $audit = null,
        ?FakeDbConnection $db = null,
        int $issuedAt = self::ISSUED_AT
    ): IssuanceService {
        $audit = $audit ?? $this->createMock(AuditService::class);
        $db = $db ?? new FakeDbConnection();
        $keyService = $this->createMock(KeyService::class);
        $keyService->method('hostDid')->willReturn(self::HOST_DID);
        $keyService->method('getActiveSigningMaterial')->willReturn([
            'key' => $this->key,
            'secret' => $this->secretRaw,
        ]);
        $signingService = new SigningService($keyService);

        $certMapper = $this->createMock(CertificateMapper::class);
        $certMapper->method('findByUserAndCourse')->willReturn($existing);
        if ($expectInsert) {
            $captureRef = &$capture;
            $certMapper->expects($this->once())->method('insert')
                ->willReturnCallback(function (Certificate $c) use (&$captureRef): Certificate {
                    $captureRef = $c;
                    return $c;
                });
        } else {
            $certMapper->expects($this->never())->method('insert');
        }

        $courseMapper = $this->createMock(CourseMapper::class);
        if ($course !== null) {
            $courseMapper->method('findById')->willReturn($course);
        }

        $notif = $this->createMock(INotification::class);
        foreach (['setApp', 'setUser', 'setObject', 'setSubject', 'setDateTime'] as $m) {
            $notif->method($m)->willReturnSelf();
        }
        $manager = $this->createMock(IManager::class);
        $manager->method('createNotification')->willReturn($notif);
        $manager->method('getCount')->willReturn(0);
        if ($expectNotify) {
            $manager->expects($this->once())->method('notify');
        } else {
            $manager->expects($this->never())->method('notify');
        }

        $theming = $this->createMock(Defaults::class);
        $theming->method('getName')->willReturn($issuerName);
        $theming->method('getLogo')->willReturn($logoUrl);

        $url = $this->createMock(IURLGenerator::class);
        $url->method('getAbsoluteURL')->willReturnCallback(fn(string $p): string => 'https://cloud.example' . $p);
        $url->method('imagePath')->willReturn('/apps/learning/img/app.svg');

        $recipient = $this->createMock(IUser::class);
        $recipient->method('getDisplayName')->willReturn($displayName);
        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->willReturn($recipient);

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn($issuedAt);
        $time->method('getDateTime')->willReturn(new \DateTime('@' . $issuedAt));

        $logger = $this->createMock(LoggerInterface::class);

        return new IssuanceService(
            $certMapper,
            $courseMapper,
            $signingService,
            $keyService,
            $manager,
            $theming,
            $url,
            $userManager,
            $time,
            $logger,
            $audit,
            $db,
        );
    }

    private function b64uDecode(string $s): array {
        $bin = base64_decode(strtr($s, '-_', '+/'), true);
        return json_decode($bin === false ? '' : $bin, true) ?? [];
    }

    private function payloadOf(Certificate $cert): array {
        $parts = explode('.', $cert->getCredentialJson());
        return $this->b64uDecode($parts[1] ?? '');
    }

    public function testIssuesExactlyOnceOnFirstPass(): void {
        $captured = null;
        $service = $this->makeService(null, $this->makeCourse(), $captured);

        $cert = $service->issueIfPassed(self::USER, self::COURSE_ID, $this->passResult());

        $this->assertInstanceOf(Certificate::class, $cert);
        $this->assertSame(self::USER, $cert->getUserId());
        $this->assertSame(self::COURSE_ID, $cert->getCourseId());
        $this->assertSame($this->key->getKeyId(), $cert->getKeyId());
        $this->assertFalse($cert->getRevoked());
        $this->assertSame(2, substr_count($cert->getCredentialJson(), '.'), 'credential_json is a 3-segment compact JWT');
        $this->assertNotNull($captured);
    }

    public function testIdempotentWhenNonRevokedCertExists(): void {
        $existing = new Certificate();
        $existing->setVerificationId('existing-vid');
        $existing->setUserId(self::USER);
        $existing->setCourseId(self::COURSE_ID);
        $existing->setRevoked(false);
        $existing->setCredentialJson('a.b.c');

        $captured = null;
        $service = $this->makeService($existing, null, $captured, expectInsert: false, expectNotify: false);

        $cert = $service->issueIfPassed(self::USER, self::COURSE_ID, $this->passResult());

        $this->assertSame($existing, $cert, 'an existing non-revoked cert is returned, not re-issued');
    }

    /**
     * Behaviour 6 (atomic dedup, FIX R2-2): the fast-path read sees no cert (null), but a concurrent
     * request wins the race → insert() hits the active_idem_key UNIQUE index and throws a
     * REASON_UNIQUE_CONSTRAINT_VIOLATION. The service must re-fetch findByUserAndCourse() and return
     * the concurrent WINNER verbatim — no duplicate cert, no notification.
     */
    public function testDedupesOnUniqueConstraintViolation(): void {
        $winner = new Certificate();
        $winner->setVerificationId('winner-vid');
        $winner->setUserId(self::USER);
        $winner->setCourseId(self::COURSE_ID);
        $winner->setRevoked(false);
        $winner->setCredentialJson('a.b.c');

        $keyService = $this->createMock(KeyService::class);
        $keyService->method('hostDid')->willReturn(self::HOST_DID);
        $keyService->method('getActiveSigningMaterial')->willReturn([
            'key' => $this->key,
            'secret' => $this->secretRaw,
        ]);
        $signingService = new SigningService($keyService);

        $certMapper = $this->createMock(CertificateMapper::class);
        // null on the fast-path read, the winner on the post-collision re-fetch.
        $certMapper->method('findByUserAndCourse')->willReturnOnConsecutiveCalls(null, $winner);
        $certMapper->expects($this->once())->method('insert')
            ->willThrowException(new \OCP\DB\Exception(\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION));

        $courseMapper = $this->createMock(CourseMapper::class);
        $courseMapper->method('findById')->willReturn($this->makeCourse());

        $manager = $this->createMock(IManager::class);
        $notif = $this->createMock(INotification::class);
        foreach (['setApp', 'setUser', 'setObject', 'setSubject', 'setDateTime'] as $m) {
            $notif->method($m)->willReturnSelf();
        }
        $manager->method('createNotification')->willReturn($notif);
        $manager->method('getCount')->willReturn(0);
        $manager->expects($this->never())->method('notify');

        $theming = $this->createMock(Defaults::class);
        $theming->method('getName')->willReturn('DevCloud Academy');
        $theming->method('getLogo')->willReturn('https://cloud.example/logo.png');

        $url = $this->createMock(IURLGenerator::class);
        $url->method('getAbsoluteURL')->willReturnCallback(fn(string $p): string => 'https://cloud.example' . $p);
        $url->method('imagePath')->willReturn('/apps/learning/img/app.svg');

        $recipient = $this->createMock(IUser::class);
        $recipient->method('getDisplayName')->willReturn('Alice Example');
        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->willReturn($recipient);

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::ISSUED_AT);
        $time->method('getDateTime')->willReturn(new \DateTime('@' . self::ISSUED_AT));

        // AUDIT-03: the unique-constraint loser must NOT fire CERT_ISSUED (it returns early in catch).
        $auditLoser = $this->createMock(AuditService::class);
        $auditLoser->expects($this->never())->method('logComplianceEvent');

        $service = new IssuanceService(
            $certMapper, $courseMapper, $signingService, $keyService, $manager,
            $theming, $url, $userManager, $time, $this->createMock(LoggerInterface::class),
            $auditLoser, new FakeDbConnection(),
        );

        $cert = $service->issueIfPassed(self::USER, self::COURSE_ID, $this->passResult());

        $this->assertSame($winner, $cert, 'the concurrent winner is returned, no duplicate issued');
    }

    /**
     * FIX-2 (atomicity, fail-closed): if the CERT_ISSUED compliance-audit append throws
     * (ComplianceAuditException), the whole issue transaction rolls back — the exception propagates,
     * the student is NOT notified, and no orphan cert (one without a chained audit event) is left behind.
     */
    public function testAuditFailureRollsBackIssuance(): void {
        $audit = $this->createMock(AuditService::class);
        $audit->method('logComplianceEvent')
            ->willThrowException(new ComplianceAuditException('chain append failed'));

        $db = new FakeDbConnection();
        $captured = null;
        // expectNotify:false — a rolled-back issuance must never fire the notification.
        $service = $this->makeService(null, $this->makeCourse(), $captured, expectNotify: false, audit: $audit, db: $db);

        try {
            $service->issueIfPassed(self::USER, self::COURSE_ID, $this->passResult());
            $this->fail('expected ComplianceAuditException to propagate (fail-closed)');
        } catch (ComplianceAuditException $e) {
            // expected
        }

        $this->assertSame(1, $db->beginTransactionCalls, 'a transaction was opened for the atomic issue');
        $this->assertSame(0, $db->commitCalls, 'a failed audit append must NOT commit');
        $this->assertSame(1, $db->rollBackCalls, 'the issue transaction must roll back on audit failure');
    }

    // AUDIT-03: happy path fires CERT_ISSUED exactly once; unique-constraint loser never fires (see testDedupesOnUniqueConstraintViolation).
    public function testCertIssuedEventFiredOnce(): void {
        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->once())
            ->method('logComplianceEvent')
            ->with(
                ComplianceEventTypes::CERT_ISSUED,
                self::USER,
                $this->callback(fn(array $ctx): bool => ($ctx['course_id'] ?? null) === self::COURSE_ID && isset($ctx['verification_id']))
            );

        $captured = null;
        $service = $this->makeService(null, $this->makeCourse(), $captured, audit: $audit);
        $cert = $service->issueIfPassed(self::USER, self::COURSE_ID, $this->passResult());

        $this->assertInstanceOf(Certificate::class, $cert);
    }

    public function testReturnsNullWhenNotPassed(): void {
        $captured = null;
        $service = $this->makeService(null, null, $captured, expectInsert: false, expectNotify: false);

        $cert = $service->issueIfPassed(self::USER, self::COURSE_ID, $this->passResult(passed: false, score: 40));

        $this->assertNull($cert);
    }

    public function testCredentialIsSelfContainedWithValidUntilWhenExpiring(): void {
        $captured = null;
        $service = $this->makeService(null, $this->makeCourse(validityDays: 365), $captured);

        $cert = $service->issueIfPassed(self::USER, self::COURSE_ID, $this->passResult(score: 92, threshold: 80));
        $this->assertNotNull($captured);
        $payload = $this->payloadOf($captured);

        $this->assertStringStartsWith('urn:uuid:', $payload['id']);
        $this->assertSame('Alice Example', $payload['credentialSubject']['name'], 'recipient display name is frozen in (DSGVO: name only, no email)');
        $this->assertStringNotContainsString('@', json_encode($payload['credentialSubject']), 'no plaintext email in the subject');
        $this->assertSame('Security+ Kurs', $payload['credentialSubject']['achievement']['name']);
        $this->assertStringContainsString('80', $payload['credentialSubject']['achievement']['criteria']['narrative']);
        $this->assertArrayHasKey('validFrom', $payload);
        $this->assertArrayHasKey('validUntil', $payload, 'validUntil present when cert_validity_days>0');
        $this->assertSame(
            self::ISSUED_AT + 365 * 86400,
            $cert->getExpiresAt(),
            'expires_at = issued_at + cert_validity_days*86400',
        );
        $this->assertStringContainsString('92', json_encode($payload['credentialSubject']['result']));
    }

    public function testValidUntilOmittedWhenNoExpiry(): void {
        $captured = null;
        $service = $this->makeService(null, $this->makeCourse(validityDays: 0), $captured);

        $cert = $service->issueIfPassed(self::USER, self::COURSE_ID, $this->passResult());
        $this->assertNotNull($captured);
        $payload = $this->payloadOf($captured);

        $this->assertArrayNotHasKey('validUntil', $payload, 'validUntil ABSENT when cert_validity_days=0');
        $this->assertNull($cert->getExpiresAt());
    }

    /**
     * DSGVO (FIX R3-7): an email-shaped userId combined with an empty display name MUST NOT leak the
     * email into the signed credential — credentialSubject.name is the neutral pseudonym fallback.
     */
    public function testEmailLikeIdentityFallsBackToNeutralName(): void {
        $captured = null;
        $service = $this->makeService(null, $this->makeCourse(), $captured, displayName: '');

        $service->issueIfPassed('a.weber@example.com', self::COURSE_ID, $this->passResult());
        $this->assertNotNull($captured);
        $payload = $this->payloadOf($captured);

        $this->assertSame('Teilnehmer:in', $payload['credentialSubject']['name'], 'neutral fallback, never the email');
        $this->assertStringNotContainsString('@', json_encode($payload['credentialSubject']), 'no plaintext email anywhere in the subject');
    }

    /**
     * DSGVO (FIX R3-7): even a RESOLVED user whose display name is itself email-shaped falls back to
     * the neutral pseudonym — the email never reaches the credential.
     */
    public function testEmailShapedDisplayNameFallsBackToNeutralName(): void {
        $captured = null;
        $service = $this->makeService(null, $this->makeCourse(), $captured, displayName: 'b.mueller@firma.de');

        $service->issueIfPassed('bob', self::COURSE_ID, $this->passResult());
        $this->assertNotNull($captured);
        $payload = $this->payloadOf($captured);

        $this->assertSame('Teilnehmer:in', $payload['credentialSubject']['name']);
        $this->assertStringNotContainsString('@', json_encode($payload['credentialSubject']));
    }

    /**
     * DSGVO (FIX R3-7): the email filter is unanchored + trimmed, so a display name that merely
     * CONTAINS an email-shaped token — leading/trailing whitespace OR a "Name <email>" wrapper —
     * still falls back to the neutral pseudonym. No plaintext email reaches the credential.
     *
     * @dataProvider emailSmugglingNames
     */
    public function testDisplayNameContainingEmailFallsBackToNeutralName(string $displayName): void {
        $captured = null;
        $service = $this->makeService(null, $this->makeCourse(), $captured, displayName: $displayName);

        $service->issueIfPassed('carol', self::COURSE_ID, $this->passResult());
        $this->assertNotNull($captured);
        $payload = $this->payloadOf($captured);

        $this->assertSame('Teilnehmer:in', $payload['credentialSubject']['name'], 'neutral fallback for email-bearing name');
        $this->assertStringNotContainsString('@', json_encode($payload['credentialSubject']), 'no plaintext email anywhere in the subject');
    }

    /** @return array<string, array{string}> */
    public static function emailSmugglingNames(): array {
        return [
            'leading/trailing whitespace' => ['  alice@example.com  '],
            'Name <email> wrapper'        => ['Alice <alice@example.com>'],
            'prefixed token'              => ['contact: a@b.de'],
        ];
    }

    public function testIssuerBrandingFromTheming(): void {
        $captured = null;
        $service = $this->makeService(
            null,
            $this->makeCourse(),
            $captured,
            logoUrl: 'https://cloud.example/themed-logo.png',
            issuerName: 'AWO Akademie',
        );

        $service->issueIfPassed(self::USER, self::COURSE_ID, $this->passResult());
        $this->assertNotNull($captured);
        $payload = $this->payloadOf($captured);

        $this->assertSame('AWO Akademie', $payload['issuer']['name']);
        $this->assertSame('https://cloud.example/themed-logo.png', $payload['issuer']['image']['id']);
        $this->assertSame(self::HOST_DID, $payload['issuer']['id']);
    }

    // ---- RECERT-01/02 RED locking test (Wave 2, 164-02) ------------------------------------

    /**
     * RECERT-02 DST-safe expiry: cert validity MUST be computed via
     * DateTimeImmutable::modify('+N months') — NEVER via +N*86400 (seconds arithmetic).
     *
     * Discriminating scenario: issue on 2026-03-29, the spring-forward day in Europe/Berlin
     * (CET→CEST: clocks jump 01:59→03:00). Under +12 months, the expiry lands on
     * 2027-03-29 00:00 CEST (UTC+2); under +365*86400, it lands on 2027-03-29 02:00 CEST —
     * these differ by exactly 3600 s. The test pinpoints this asymmetry.
     *
     * RED at Wave 2: IssuanceService::computeExpiry() is a stub that throws, and the existing
     * code path uses cert_validity_days (=0 here) → expiresAt=null → assertSame fails.
     * GREEN in 164-04: computeExpiry() is wired using cert_validity_months=12 and
     * DateTimeImmutable::modify('+12 months') → returns the DST-correct $expectedExpiry.
     */
    public function testValidityDstCrossing(): void {
        $originalTz = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');
        try {
            // Spring-forward day: Europe/Berlin jumps from CET (+1) to CEST (+2) on 2026-03-29.
            $springFwdAt = (new \DateTimeImmutable('2026-03-29 00:00:00', new \DateTimeZone('Europe/Berlin')))
                ->getTimestamp();

            // DST-safe expected expiry: calendar months via modify() → 2027-03-29 00:00 CEST (UTC+2).
            $expectedExpiry = (new \DateTimeImmutable('@' . $springFwdAt))
                ->setTimezone(new \DateTimeZone('Europe/Berlin'))
                ->modify('+12 months')
                ->getTimestamp();

            // Naive arithmetic result (WRONG): +365 days in seconds → 2027-03-29 02:00 CEST.
            $naiveExpiry = $springFwdAt + 365 * 86400;

            // DST precondition: the two approaches must yield different timestamps under Europe/Berlin.
            $this->assertNotSame($naiveExpiry, $expectedExpiry,
                'DST precondition: +12 months and +365*86400 must differ under Europe/Berlin '
                . '(expected 3600 s gap across spring-forward)');

            // Course: cert_validity_months=12 (the 164-01 Version009600 column — months-based validity).
            // cert_validity_days=0 so the CURRENT code path produces expiresAt=null (no reuse of days).
            $course = $this->makeCourse(validityDays: 0);
            $course->setCertValidityMonths(12); // magic setter via Entity base class

            $captured = null;
            $service = $this->makeService(
                null,
                $course,
                $captured,
                issuedAt: $springFwdAt, // pin clock to spring-forward day
            );
            $cert = $service->issueIfPassed(self::USER, self::COURSE_ID, $this->passResult());

            $this->assertNotNull($cert, 'cert must be issued');
            // RED: current code uses cert_validity_days (=0) → expiresAt=null → assertSame fails.
            // GREEN in 164-04: computeExpiry(springFwdAt, courseId, null) wired with cert_validity_months=12
            //   → DateTimeImmutable::modify('+12 months') → returns $expectedExpiry (≠ $naiveExpiry).
            $this->assertSame(
                $expectedExpiry,
                $cert->getExpiresAt(),
                'expires_at must be the DST-safe DateTimeImmutable::modify("+12 months") result'
            );
            $this->assertNotSame(
                $naiveExpiry,
                $cert->getExpiresAt(),
                'expires_at must NOT be the naive +365*86400 result (diverges across spring-forward by 3600 s)'
            );
        } finally {
            date_default_timezone_set($originalTz);
        }
    }
}
