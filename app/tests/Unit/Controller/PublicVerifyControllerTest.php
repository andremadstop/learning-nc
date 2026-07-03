<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\PublicVerifyController;
use OCA\Learning\Db\CertKeyMapper;
use OCA\Learning\Db\CertificateMapper;
use OCA\Learning\Service\CertificateVerifyService;
use OCA\Learning\Service\KeyService;
use OCA\Learning\Service\SigningService;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Defaults;
use OCP\IL10N;
use OCP\IRequest;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

/**
 * Phase 157 Plan 04 — PublicVerifyController (the public, unauthenticated verify page).
 *
 * The controller is a thin shell over the 157-02 CertificateVerifyService: it does the
 * UUID-format precheck BEFORE any DB/service call (anti-enumeration), throttles ONLY the
 * unknown/malformed branch (never valid/withdrawn/expired/invalid), and projects the
 * service's DSGVO DTO into a server-rendered PublicTemplateResponse. It always returns
 * HTTP 200 (withdrawn/expired/unknown are tombstone renders, NOT 404).
 *
 * throttle()/AnonRateLimit/BruteForceProtection are middleware-level side effects that are
 * bypassed when the controller is instantiated directly (like @NoAdminRequired in 155/156),
 * so these tests assert the BRANCH logic (the response carries the throttle marker / the
 * generic params) — the live throttle is verified manually per VALIDATION.
 *
 * @group verify-06
 */
class PublicVerifyControllerTest extends TestCase {
    private const VID_VALID = '11111111-1111-4111-8111-111111111111';
    private const VID_MISSING = '00000000-0000-4000-8000-000000000000';
    private const MALFORMED = '../../etc/passwd';

    /** @var IRequest&\PHPUnit\Framework\MockObject\MockObject */
    private $requestMock;

    protected function setUp(): void {
        $this->requestMock = $this->createMock(IRequest::class);
    }

    /** Build the controller over a (mock or real) CertificateVerifyService. */
    private function makeController(CertificateVerifyService $service): PublicVerifyController {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnArgument(0);
        $factory = $this->createMock(IFactory::class);
        $factory->method('get')->willReturn($l);
        return new PublicVerifyController(
            'learning',
            $this->requestMock,
            $service,
            $factory,
            $this->createMock(Defaults::class)
        );
    }

    /**
     * Test 1 (VERIFY-06, anti-enumeration): a malformed (non-UUIDv4) verificationId is rejected by the
     * UUID precheck BEFORE any DB query. We wire a REAL CertificateVerifyService over a MOCKED
     * CertificateMapper and assert the mapper's findByVerificationId is NEVER called — proving the
     * precheck short-circuits before the service touches the database. The render is the generic
     * "unknown" page and the throttle marker is set.
     */
    public function testMalformedUuid(): void {
        $certMapper = $this->createMock(CertificateMapper::class);
        $certMapper->expects($this->never())->method('findByVerificationId');

        $service = new CertificateVerifyService(
            $certMapper,
            $this->createMock(CertKeyMapper::class),
            $this->createMock(SigningService::class),
            $this->createMock(KeyService::class),
            $this->createMock(ITimeFactory::class),
            $this->createMock(\OCP\IConfig::class)
        );

        $resp = $this->makeController($service)->verify(self::MALFORMED);

        $this->assertInstanceOf(PublicTemplateResponse::class, $resp);
        $this->assertSame('unknown', $resp->getParams()['status']);
        $this->assertArrayNotHasKey('course_title', $resp->getParams());
        $this->assertArrayNotHasKey('issuer_name', $resp->getParams());
        $this->assertTrue($resp->isThrottled(), 'malformed must take the throttle branch');
    }

    /**
     * Test 2 (VERIFY-06, no oracle): a well-formed-but-missing vid (service → ['status'=>'unknown'])
     * renders the SAME generic page as a malformed id — IDENTICAL params, both throttled, neither
     * carrying any cert field. Encodes "no enumeration oracle" structurally.
     */
    public function testUnknownGeneric(): void {
        // not-found path (service returns unknown for a well-formed id)
        $service = $this->createMock(CertificateVerifyService::class);
        $service->expects($this->once())
            ->method('verifyByVerificationId')
            ->with(self::VID_MISSING)
            ->willReturn(['status' => 'unknown']);
        $notFound = $this->makeController($service)->verify(self::VID_MISSING);

        // malformed path (precheck short-circuits, service never called)
        $malformedService = $this->createMock(CertificateVerifyService::class);
        $malformedService->expects($this->never())->method('verifyByVerificationId');
        $malformed = $this->makeController($malformedService)->verify(self::MALFORMED);

        $this->assertInstanceOf(PublicTemplateResponse::class, $notFound);
        $this->assertSame('unknown', $notFound->getParams()['status']);
        $this->assertTrue($notFound->isThrottled());
        // No oracle: not-found and malformed are byte-identical in params + throttle state.
        $this->assertSame($malformed->getParams(), $notFound->getParams());
        $this->assertSame($malformed->isThrottled(), $notFound->isThrottled());
    }

    /**
     * Test 3 (VERIFY-02): a valid result is passed through to the template params (status + the
     * DSGVO display fields) and the happy path is NEVER throttled (legitimate verifiers must not be
     * slowed).
     */
    public function testValidRendersStatus(): void {
        $dto = [
            'status' => 'valid',
            'issuer_name' => 'DevCloud',
            'course_title' => 'Compliance-Schulung',
            'issued_at' => 1700000000,
            'expires_at' => null,
            'verification_id' => self::VID_VALID,
        ];
        $service = $this->createMock(CertificateVerifyService::class);
        $service->method('verifyByVerificationId')->with(self::VID_VALID)->willReturn($dto);

        $resp = $this->makeController($service)->verify(self::VID_VALID);

        $this->assertInstanceOf(PublicTemplateResponse::class, $resp);
        $params = $resp->getParams();
        $this->assertSame('valid', $params['status']);
        $this->assertSame('DevCloud', $params['issuer_name']);
        $this->assertSame('Compliance-Schulung', $params['course_title']);
        $this->assertSame(self::VID_VALID, $params['verification_id']);
        $this->assertFalse($resp->isThrottled(), 'the valid happy path must NOT be throttled');
    }

    /**
     * Test 4 (sharpener): a 'withdrawn' tombstone is a 200 render carrying revoked_at + the display
     * fields, and is NOT throttled.
     */
    public function testWithdrawnRendersTombstone(): void {
        $dto = [
            'status' => 'withdrawn',
            'revoked_at' => 1700500000,
            'issuer_name' => 'DevCloud',
            'course_title' => 'Compliance-Schulung',
            'issued_at' => 1700000000,
            'expires_at' => null,
            'verification_id' => self::VID_VALID,
        ];
        $service = $this->createMock(CertificateVerifyService::class);
        $service->method('verifyByVerificationId')->willReturn($dto);

        $resp = $this->makeController($service)->verify(self::VID_VALID);

        $this->assertSame('withdrawn', $resp->getParams()['status']);
        $this->assertSame(1700500000, $resp->getParams()['revoked_at']);
        $this->assertFalse($resp->isThrottled());
    }

    /**
     * Test 5 (sharpener): a cryptographic 'invalid' (tampered row / sig fail) renders the SAME red
     * banner as unknown (banner-only, no DTO) but is NOT throttled (rare integrity failure, not an
     * enumeration probe). The service returns a bare ['status'=>'invalid'] with no display fields.
     */
    public function testInvalidRendersBannerOnlyNoThrottle(): void {
        $service = $this->createMock(CertificateVerifyService::class);
        $service->method('verifyByVerificationId')->willReturn(['status' => 'invalid']);

        $resp = $this->makeController($service)->verify(self::VID_VALID);

        $this->assertSame('invalid', $resp->getParams()['status']);
        $this->assertArrayNotHasKey('course_title', $resp->getParams());
        $this->assertArrayNotHasKey('issuer_name', $resp->getParams());
        $this->assertFalse($resp->isThrottled(), 'invalid is a rare integrity failure — not throttled');
    }
}
