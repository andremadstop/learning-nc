<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\CertKey;
use OCA\Learning\Db\Certificate;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CertificateMapper;
use OCA\Learning\Service\IssuanceService;
use OCA\Learning\Service\KeyService;
use OCA\Learning\Service\PassResult;
use OCA\Learning\Service\SigningService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use OCP\Defaults;
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
        bool $expectNotify = true
    ): IssuanceService {
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

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::ISSUED_AT);
        $time->method('getDateTime')->willReturn(new \DateTime('@' . self::ISSUED_AT));

        $logger = $this->createMock(LoggerInterface::class);

        return new IssuanceService(
            $certMapper,
            $courseMapper,
            $signingService,
            $keyService,
            $manager,
            $theming,
            $url,
            $time,
            $logger,
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
}
