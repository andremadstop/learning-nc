<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\CertKey;
use OCA\Learning\Service\KeyService;
use OCA\Learning\Service\SigningService;
use PHPUnit\Framework\TestCase;

/**
 * SigningService — VC-JWT (VC-JOSE-COSE) EdDSA signer (155-03, CERT-06).
 *
 * Proves the FROZEN signing contract (155-ADR-ANCHOR.md) end to end:
 *  1. round-trip       — sign() then verify() with the matching public key → true.
 *  2. tamper-payload   — flipping one byte of the payload segment → verify() → false.
 *  3. header-contract  — header is EXACTLY {alg:EdDSA, typ:vc+jwt, cty:vc, kid:...}; the
 *                        payload carries NO `vc` wrapper; kid contains 'apps:learning#'.
 *  4. byte-stability   — the signed payload bytes == json_encode(credential, JSON_UNESCAPED_SLASHES)
 *                        (zero canonicalization: signed bytes == emitted bytes).
 *  5. independent      — an INDEPENDENT verifier (Python `cryptography` Ed25519, NOT the app's own
 *                        code) accepts a valid JWT (exit 0) and rejects a tampered one (non-zero).
 *                        This automates ADR-001 follow-up #2.
 */
class SigningServiceTest extends TestCase {

    private const HOST_DID = 'did:web:example.com:apps:learning';

    /** @var string 32-byte Ed25519 public key (throwaway, no DB). */
    private string $publicRaw;
    /** @var string 64-byte Ed25519 secret key. */
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
        $this->key->setKeyId('PUBKEYID_b64u_fragment');
        $this->key->setPublicKeyB64u('PUBKEYID_b64u_fragment');
        $this->key->setStatus('active');
        $this->key->setCreatedAt(1750000000);
    }

    /**
     * kid is derived from KeyService::hostDid() (NOT a private re-derivation) so the JWT kid is the
     * SAME string as DidController's verificationMethod.id by construction — kills kid-drift (Pitfall 4).
     */
    private function makeService(): SigningService {
        $keyService = $this->createMock(KeyService::class);
        $keyService->method('hostDid')->willReturn(self::HOST_DID);
        return new SigningService($keyService);
    }

    /** Sample OB3-style credential object — used DIRECTLY as the payload (no `vc` wrapper). */
    private function sampleCredential(): array {
        return [
            '@context' => [
                'https://www.w3.org/ns/credentials/v2',
                'https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json',
            ],
            'type' => ['VerifiableCredential', 'OpenBadgeCredential'],
            'issuer' => self::HOST_DID,
            'credentialSubject' => [
                'type' => ['AchievementSubject'],
                'achievement' => ['name' => 'Security+ Kurs bestanden'],
            ],
        ];
    }

    private function b64uDecode(string $s): string {
        $bin = base64_decode(strtr($s, '-_', '+/'), true);
        return $bin === false ? '' : $bin;
    }

    private function b64uEncode(string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public function testRoundTripVerifies(): void {
        $service = $this->makeService();
        $jwt = $service->sign($this->sampleCredential(), $this->key, $this->secretRaw);

        $this->assertSame(3, substr_count($jwt, '.') + 1, 'compact JWT has three dot-separated segments');
        $this->assertTrue($service->verify($jwt, $this->publicRaw), 'valid JWT verifies against its public key');
    }

    public function testTamperedPayloadFailsVerification(): void {
        $service = $this->makeService();
        $jwt = $service->sign($this->sampleCredential(), $this->key, $this->secretRaw);

        [$h, $p, $s] = explode('.', $jwt);
        // Flip one character of the payload segment → different signing input → signature no longer valid.
        $first = $p[0];
        $p[0] = $first === 'A' ? 'B' : 'A';
        $tampered = $h . '.' . $p . '.' . $s;

        $this->assertNotSame($jwt, $tampered, 'tampering actually changed the JWT');
        $this->assertFalse($service->verify($tampered, $this->publicRaw), 'tampered payload must fail verification');
    }

    /**
     * Forge a compact JWT with an arbitrary header but a VALID Ed25519 signature over the forged
     * signing input. This proves the header-contract gate (FIX 3 / R3-4) rejects independently of
     * signature validity — a confused header is denied even when the signature itself is correct.
     */
    private function forge(array $header, array $payload, string $secretRaw): string {
        $h = $this->b64uEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $p = $this->b64uEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $sig = sodium_crypto_sign_detached($h . '.' . $p, $secretRaw);
        return $h . '.' . $p . '.' . $this->b64uEncode($sig);
    }

    public function testVerifyRejectsAlgNoneHeader(): void {
        $service = $this->makeService();
        // alg:none but otherwise plausible — and signed with a VALID signature over the forged input.
        $jwt = $this->forge(
            ['alg' => 'none', 'typ' => 'vc+jwt', 'cty' => 'vc', 'kid' => self::HOST_DID . '#x'],
            $this->sampleCredential(),
            $this->secretRaw
        );
        $this->assertFalse(
            $service->verify($jwt, $this->publicRaw),
            'alg:none header must be rejected even with a structurally valid signature'
        );
    }

    public function testVerifyRejectsWrongTyp(): void {
        $service = $this->makeService();
        $jwt = $this->forge(
            ['alg' => 'EdDSA', 'typ' => 'JWT', 'cty' => 'vc', 'kid' => self::HOST_DID . '#x'],
            $this->sampleCredential(),
            $this->secretRaw
        );
        $this->assertFalse($service->verify($jwt, $this->publicRaw), 'wrong typ must be rejected');
    }

    public function testVerifyRejectsWrongCty(): void {
        $service = $this->makeService();
        $jwt = $this->forge(
            ['alg' => 'EdDSA', 'typ' => 'vc+jwt', 'cty' => 'JWT', 'kid' => self::HOST_DID . '#x'],
            $this->sampleCredential(),
            $this->secretRaw
        );
        $this->assertFalse($service->verify($jwt, $this->publicRaw), 'wrong cty must be rejected');
    }

    public function testVerifyRejectsMalformedHeader(): void {
        $service = $this->makeService();
        // Header segment is valid base64url but NOT JSON.
        $h = $this->b64uEncode('this-is-not-json{');
        $p = $this->b64uEncode(json_encode($this->sampleCredential(), JSON_UNESCAPED_SLASHES));
        $sig = sodium_crypto_sign_detached($h . '.' . $p, $this->secretRaw);
        $jwt = $h . '.' . $p . '.' . $this->b64uEncode($sig);

        $this->assertFalse($service->verify($jwt, $this->publicRaw), 'malformed/non-JSON header must be rejected');
    }

    public function testVerifyAcceptsValidContractToken(): void {
        // Counterpart to the rejection tests: a correctly-signed, contract-compliant token still verifies.
        $service = $this->makeService();
        $jwt = $service->sign($this->sampleCredential(), $this->key, $this->secretRaw);
        $this->assertTrue($service->verify($jwt, $this->publicRaw), 'a valid contract token still verifies');
    }

    public function testHeaderContractAndNoVcWrapper(): void {
        $service = $this->makeService();
        $jwt = $service->sign($this->sampleCredential(), $this->key, $this->secretRaw);
        [$h, $p] = explode('.', $jwt);

        $header = json_decode($this->b64uDecode($h), true);
        $this->assertSame(
            ['alg' => 'EdDSA', 'typ' => 'vc+jwt', 'cty' => 'vc', 'kid' => self::HOST_DID . '#' . $this->key->getKeyId()],
            $header,
            'header is EXACTLY the frozen {alg,typ,cty,kid} contract'
        );
        $this->assertStringContainsString('apps:learning#', $header['kid'], 'kid is the did:web verificationMethod.id form');

        $payload = json_decode($this->b64uDecode($p), true);
        $this->assertArrayNotHasKey('vc', $payload, 'payload is the credential DIRECTLY — no vc wrapper');
        $this->assertArrayNotHasKey('vp', $payload, 'no vp wrapper either');
        $this->assertArrayNotHasKey('iss', $payload, 'no registered-claim mirroring (VC-JWT 1.1 is forbidden)');
        $this->assertArrayNotHasKey('sub', $payload);
        $this->assertArrayNotHasKey('nbf', $payload);
        $this->assertArrayNotHasKey('jti', $payload);
        $this->assertArrayHasKey('credentialSubject', $payload, 'the OB3 credential object IS the payload');
    }

    public function testByteStabilityNoCanonicalization(): void {
        $service = $this->makeService();
        $credential = $this->sampleCredential();
        $jwt = $service->sign($credential, $this->key, $this->secretRaw);
        [, $p] = explode('.', $jwt);

        $this->assertSame(
            json_encode($credential, JSON_UNESCAPED_SLASHES),
            $this->b64uDecode($p),
            'signed payload bytes == json_encode(credential, JSON_UNESCAPED_SLASHES) — no canonicalization step'
        );
    }

    /**
     * ADR-001 follow-up #2: a THIRD-PARTY verifier (Python `cryptography` Ed25519, not the app's own
     * sodium_crypto_sign_verify_detached) must accept the valid JWT and reject a tampered one. The
     * phase gate (155-07) re-runs this on a REAL issued cert and MUST fail if no independent verifier
     * is available — so we do NOT silently skip on a missing module; only python3-absent skips.
     *
     * This is deliberate, not an oversight: on a bare Nextcloud app container (no `pip`, no
     * `cryptography`), this test is EXPECTED to fail rather than skip. Install `cryptography`
     * there, or run this suite where it is a CI/dev machine with the module present — do not
     * "fix" it by widening the skip condition to a missing module.
     */
    public function testIndependentPythonVerifier(): void {
        $python = $this->findPython3();
        if ($python === null) {
            $this->markTestSkipped('python3 is not available — independent verifier cannot run');
        }
        $script = $this->resolveVerifyScript();
        $this->assertNotSame('', $script, 'scripts/verify-credential.py must be resolvable (set VERIFY_SCRIPT in CI/container)');

        $service = $this->makeService();
        $jwt = $service->sign($this->sampleCredential(), $this->key, $this->secretRaw);
        $xB64u = $this->b64uEncode($this->publicRaw);

        // Valid JWT → independent verifier exits 0.
        $validRc = $this->runVerifier($python, $script, $jwt, $xB64u);
        $this->assertSame(0, $validRc, 'independent Python Ed25519 verifier accepts the valid JWT');

        // Tampered JWT → independent verifier exits non-zero.
        [$h, $p, $s] = explode('.', $jwt);
        $p[0] = $p[0] === 'A' ? 'B' : 'A';
        $tampered = $h . '.' . $p . '.' . $s;
        $tamperRc = $this->runVerifier($python, $script, $tampered, $xB64u);
        $this->assertNotSame(0, $tamperRc, 'independent verifier rejects the tampered JWT');
    }

    private function findPython3(): ?string {
        foreach (['python3', '/usr/bin/python3'] as $candidate) {
            $out = [];
            $rc = 1;
            @exec(escapeshellarg($candidate) . ' --version 2>/dev/null', $out, $rc);
            if ($rc === 0) {
                return $candidate;
            }
        }
        return null;
    }

    /** Resolve the dev-only verifier across repo (local) and container layouts. */
    private function resolveVerifyScript(): string {
        $env = getenv('VERIFY_SCRIPT');
        $candidates = array_filter([
            is_string($env) && $env !== '' ? $env : null,
            __DIR__ . '/../../../../scripts/verify-credential.py', // repo: app/tests/Unit/Service → repo/scripts
            __DIR__ . '/../../../scripts/verify-credential.py',     // container: custom_apps/learning/tests/Unit/Service → app-root/scripts
            '/tmp/verify-credential.py',                            // container: docker cp'd here
        ]);
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        return '';
    }

    private function runVerifier(string $python, string $script, string $jwt, string $xB64u): int {
        $cmd = escapeshellarg($python) . ' ' . escapeshellarg($script) . ' '
            . escapeshellarg($jwt) . ' ' . escapeshellarg($xB64u) . ' 2>&1';
        $out = [];
        $rc = 1;
        exec($cmd, $out, $rc);
        return $rc;
    }
}
