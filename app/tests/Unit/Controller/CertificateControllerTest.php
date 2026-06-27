<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\CertificateController;
use OCA\Learning\Db\Certificate;
use OCA\Learning\Db\CertificateMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Phase 155 Plan 05 — CertificateController ownership + OB3 JSON-LD download contract.
 *
 * Asserts the student-facing, authenticated certificate endpoints:
 *   - index()    returns ONLY the current user's certificates (no cross-user listing).
 *   - show()     uniform 404 on a foreign cert (IDOR guard, no existence oracle) AND on an unknown id.
 *   - download() default → an Open Badges 3.0 JSON-LD EnvelopedVerifiableCredential
 *                wrapping the stored compact VC-JWT (application/ld+json), CERT-09.
 *   - download(?format=jwt) → the raw 3-segment compact JWT (application/vc+jwt).
 *
 * The PUBLIC verify route is Phase 157 — NOT exercised here. All routes are owner-scoped.
 *
 * @group cert-05
 */
class CertificateControllerTest extends TestCase {
    // Verification ids must be valid UUIDv4 — the controller 404s malformed ids before any DB lookup.
    private const VID_OWN = '11111111-1111-4111-8111-111111111111';
    private const VID_FOREIGN = '22222222-2222-4222-8222-222222222222';
    private const VID_MISSING = '33333333-3333-4333-8333-333333333333';

    /** @var CertificateMapper&\PHPUnit\Framework\MockObject\MockObject */
    private $mapperMock;
    /** @var IRequest&\PHPUnit\Framework\MockObject\MockObject */
    private $requestMock;

    protected function setUp(): void {
        $this->mapperMock = $this->createMock(CertificateMapper::class);
        $this->requestMock = $this->createMock(IRequest::class);
    }

    private function makeController(?string $userId): CertificateController {
        return new CertificateController('learning', $this->requestMock, $this->mapperMock, $userId);
    }

    private function makeCert(string $vid, string $userId, string $jwt): Certificate {
        $cert = new Certificate();
        $cert->setVerificationId($vid);
        $cert->setUserId($userId);
        $cert->setCourseId(7);
        $cert->setKeyId('key-1');
        $cert->setCredentialJson($jwt);
        $cert->setRevoked(false);
        $cert->setIssuedAt(1700000000);
        $cert->setExpiresAt(null);
        return $cert;
    }

    /**
     * Test 1 (list own): index() returns ONLY the current user's certificates —
     * the mapper is queried with the authenticated uid, never a caller-supplied one.
     */
    public function testIndexReturnsOnlyOwnCertificates(): void {
        $own = $this->makeCert('vid-A', 'alice', 'h.p.s');
        $this->mapperMock->expects($this->once())
            ->method('findByUserId')
            ->with('alice')
            ->willReturn([$own]);

        $resp = $this->makeController('alice')->index();

        $this->assertInstanceOf(JSONResponse::class, $resp);
        $data = $resp->getData();
        $this->assertCount(1, $data);
        $this->assertSame('vid-A', $data[0]['verification_id']);
        $this->assertSame('alice', $data[0]['user_id']);
    }

    /**
     * Test 2a (ownership, FIX R3-3): show() for a verification-id owned by another user → uniform 404
     * (NOT 403) — the owner-scoped mapper query yields no row, so a foreign cert is indistinguishable
     * from a non-existent one (no existence oracle). The body must NOT contain the cert.
     */
    public function testShowForeignCertReturns404WithoutBody(): void {
        $this->mapperMock->method('findByVerificationIdAndUserId')
            ->with(self::VID_FOREIGN, 'alice')
            ->willThrowException(new DoesNotExistException('not owned'));

        $resp = $this->makeController('alice')->show(self::VID_FOREIGN);

        $this->assertInstanceOf(JSONResponse::class, $resp);
        $this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
        $this->assertArrayNotHasKey('credential_json', $resp->getData());
        $this->assertArrayNotHasKey('verification_id', $resp->getData());
    }

    /**
     * Test 2b (ownership, FIX R3-3): download() for a foreign cert → uniform 404 (never the
     * credential file, never a 403 that would leak existence).
     */
    public function testDownloadForeignCertReturns404(): void {
        $this->mapperMock->method('findByVerificationIdAndUserId')
            ->with(self::VID_FOREIGN, 'alice')
            ->willThrowException(new DoesNotExistException('not owned'));

        $resp = $this->makeController('alice')->download(self::VID_FOREIGN);

        $this->assertInstanceOf(JSONResponse::class, $resp);
        $this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
    }

    /**
     * Test 2c (input validation, FIX R9-9): a malformed (non-UUIDv4) verification id is rejected with
     * 404 BEFORE any DB lookup — the mapper is never called.
     */
    public function testShowMalformedVerificationIdReturns404(): void {
        $this->mapperMock->expects($this->never())->method('findByVerificationIdAndUserId');

        $resp = $this->makeController('alice')->show('../../etc/passwd');

        $this->assertInstanceOf(JSONResponse::class, $resp);
        $this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
    }

    /**
     * Test 3 (download JSON-LD, default): download() for an owned cert returns an OB3
     * EnvelopedVerifiableCredential — type 'EnvelopedVerifiableCredential',
     * id begins 'data:application/vc+jwt,' + the stored JWT, content type application/ld+json.
     */
    public function testDownloadDefaultReturnsEnvelopedJsonLd(): void {
        $jwt = 'eyJhbGciOiJFZERTQSIsInR5cCI6InZjK2p3dCJ9.eyJpZCI6InVybjp1dWlkOnZpZC1BIn0.c2ln';
        $cert = $this->makeCert(self::VID_OWN, 'alice', $jwt);
        $this->mapperMock->method('findByVerificationIdAndUserId')->with(self::VID_OWN, 'alice')->willReturn($cert);

        $resp = $this->makeController('alice')->download(self::VID_OWN);

        $this->assertInstanceOf(DataDownloadResponse::class, $resp);
        $this->assertSame('application/ld+json', $resp->getHeaders()['Content-Type']);
        $this->assertStringContainsString('certificate-' . self::VID_OWN . '.json', $resp->getHeaders()['Content-Disposition']);

        $body = json_decode($resp->render(), true);
        $this->assertIsArray($body);
        $this->assertSame('EnvelopedVerifiableCredential', $body['type']);
        $this->assertStringStartsWith('data:application/vc+jwt,', $body['id']);
        $this->assertStringEndsWith($jwt, $body['id']);
        $this->assertContains('https://www.w3.org/ns/credentials/v2', $body['@context']);
    }

    /**
     * Test 3b (download jwt): download(?format=jwt) returns the raw 3-segment compact JWT,
     * content type application/vc+jwt.
     */
    public function testDownloadJwtFormatReturnsRawCompactJwt(): void {
        $jwt = 'eyJhbGciOiJFZERTQSIsInR5cCI6InZjK2p3dCJ9.eyJpZCI6InVybjp1dWlkOnZpZC1BIn0.c2ln';
        $cert = $this->makeCert(self::VID_OWN, 'alice', $jwt);
        $this->mapperMock->method('findByVerificationIdAndUserId')->with(self::VID_OWN, 'alice')->willReturn($cert);

        $resp = $this->makeController('alice')->download(self::VID_OWN, 'jwt');

        $this->assertInstanceOf(DataDownloadResponse::class, $resp);
        $this->assertSame('application/vc+jwt', $resp->getHeaders()['Content-Type']);
        $this->assertStringContainsString('certificate-' . self::VID_OWN . '.jwt', $resp->getHeaders()['Content-Disposition']);
        $this->assertSame($jwt, $resp->render());
        $this->assertCount(3, explode('.', $resp->render()));
    }

    /**
     * Test 4 (missing): show() for an unknown verification-id → 404.
     */
    public function testShowUnknownVerificationIdReturns404(): void {
        $this->mapperMock->method('findByVerificationIdAndUserId')
            ->willThrowException(new DoesNotExistException('no such cert'));

        $resp = $this->makeController('alice')->show(self::VID_MISSING);

        $this->assertInstanceOf(JSONResponse::class, $resp);
        $this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
    }
}
