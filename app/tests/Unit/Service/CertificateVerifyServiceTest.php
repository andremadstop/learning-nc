<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\CertKey;
use OCA\Learning\Db\CertKeyMapper;
use OCA\Learning\Db\Certificate;
use OCA\Learning\Db\CertificateMapper;
use OCA\Learning\Service\AssignmentService;
use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\CertificateVerifyService;
use OCA\Learning\Service\KeyService;
use OCA\Learning\Service\SigningService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * Real-logic contract for CertificateVerifyService::verifyByVerificationId() — the cryptographic
 * heart of Phase 157 (VERIFY-02..05). A verdict of "valid" is the AND of a real Ed25519 signature,
 * a non-revoked signing key, AND claim-binding (kid / issuer.id / credential id) — a valid signature
 * alone is NEVER sufficient. The DSGVO projection must leak no recipient identity.
 *
 * Strategy: the REAL SigningService runs the sodium detached verify (never mocked) over JWTs we sign
 * here with a throwaway keypair; only the two mappers, KeyService::hostDid(), and the clock are mocked.
 * The signer and the service share ONE KeyService mock so the kid binds by construction.
 */
class CertificateVerifyServiceTest extends TestCase {

    private const HOST_DID = 'did:web:example.com:apps:learning';
    private const KEY_ID = 'PUBKEYID_b64u_fragment';
    private const VID = 'eb97720c-59d1-4c65-ba49-229c47341047';
    private const NOW = 1_700_000_000;
    private const RECIPIENT = 'Jürgen Müller';
    private const COURSE_TITLE = 'Datenschutz für Pflegekräfte';
    private const ISSUER_NAME = 'DevCloud';

    /** @var string 32-byte Ed25519 public key (throwaway, no DB). */
    private string $publicRaw;
    /** @var string 64-byte Ed25519 secret key. */
    private string $secretRaw;

    protected function setUp(): void {
        if (!extension_loaded('sodium')) {
            $this->markTestSkipped('ext-sodium not loaded');
        }
        $keypair = sodium_crypto_sign_keypair();
        $this->publicRaw = sodium_crypto_sign_publickey($keypair);
        $this->secretRaw = sodium_crypto_sign_secretkey($keypair);
    }

    // ---- fixtures -----------------------------------------------------------

    private function b64u(string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private function keyServiceWith(string $hostDid = self::HOST_DID): KeyService {
        $keyService = $this->createMock(KeyService::class);
        $keyService->method('hostDid')->willReturn($hostDid);
        return $keyService;
    }

    private function timeAt(int $now = self::NOW): ITimeFactory {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn($now);
        return $time;
    }

    /** A CertKey whose public_key_b64u is OUR throwaway public key, with the given status. */
    private function certKey(string $keyId = self::KEY_ID, string $status = 'active'): CertKey {
        $key = new CertKey();
        $key->setKeyId($keyId);
        $key->setPublicKeyB64u($this->b64u($this->publicRaw));
        $key->setStatus($status);
        $key->setCreatedAt(1_650_000_000);
        return $key;
    }

    /**
     * A frozen OB3 payload (mirrors IssuanceService::buildCredential): credential id =
     * urn:uuid:<vid>, issuer.id = hostDid, recipient name in credentialSubject.name (PII),
     * course title in achievement.name.
     *
     * @return array<string, mixed>
     */
    private function payload(string $vid, string $issuerId = self::HOST_DID): array {
        return [
            '@context' => [
                'https://www.w3.org/ns/credentials/v2',
                'https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json',
            ],
            'id' => 'urn:uuid:' . $vid,
            'type' => ['VerifiableCredential', 'OpenBadgeCredential'],
            'issuer' => [
                'id' => $issuerId,
                'type' => ['Profile'],
                'name' => self::ISSUER_NAME,
            ],
            'validFrom' => '2023-11-14T00:00:00+00:00',
            'credentialSubject' => [
                'type' => ['AchievementSubject'],
                'name' => self::RECIPIENT,
                'achievement' => [
                    'id' => 'urn:learning:course:7',
                    'type' => ['Achievement'],
                    'name' => self::COURSE_TITLE,
                ],
            ],
        ];
    }

    /**
     * Sign a payload into a compact VC-JWT through the REAL SigningService, with a kid derived
     * from $keyId (the kid the verify path will re-bind against).
     *
     * @param array<string, mixed> $payload
     */
    private function signJwt(array $payload, string $keyId = self::KEY_ID): string {
        $signer = new SigningService($this->keyServiceWith());
        return $signer->sign($payload, $this->certKey($keyId), $this->secretRaw);
    }

    private function certificate(
        string $vid,
        string $credentialJson,
        string $keyId = self::KEY_ID,
        bool $revoked = false,
        ?int $revokedAt = null,
        ?int $expiresAt = null,
        int $issuedAt = 1_699_900_000
    ): Certificate {
        $cert = new Certificate();
        $cert->setVerificationId($vid);
        $cert->setUserId('jmueller');
        $cert->setCourseId(7);
        $cert->setKeyId($keyId);
        $cert->setCredentialJson($credentialJson);
        $cert->setRevoked($revoked);
        $cert->setRevokedAt($revokedAt);
        $cert->setExpiresAt($expiresAt);
        $cert->setIssuedAt($issuedAt);
        return $cert;
    }

    private function certMapperFor(Certificate $cert): CertificateMapper {
        $mapper = $this->createMock(CertificateMapper::class);
        $mapper->method('findByVerificationId')->willReturn($cert);
        return $mapper;
    }

    private function keyMapperFor(CertKey $key): CertKeyMapper {
        $mapper = $this->createMock(CertKeyMapper::class);
        $mapper->method('findByKeyId')->willReturn($key);
        return $mapper;
    }

    private function buildService(
        CertificateMapper $certMapper,
        CertKeyMapper $keyMapper,
        KeyService $keyService,
        ITimeFactory $time
    ): CertificateVerifyService {
        return new CertificateVerifyService(
            $certMapper,
            $keyMapper,
            new SigningService($keyService),
            $keyService,
            $time
        );
    }

    // ---- tests --------------------------------------------------------------

    public function testValid(): void {
        $keyService = $this->keyServiceWith();
        $jwt = $this->signJwt($this->payload(self::VID));
        $cert = $this->certificate(self::VID, $jwt);

        $service = $this->buildService(
            $this->certMapperFor($cert),
            $this->keyMapperFor($this->certKey(self::KEY_ID, 'active')),
            $keyService,
            $this->timeAt()
        );

        $result = $service->verifyByVerificationId(self::VID);

        $this->assertSame('valid', $result['status']);
        $this->assertSame(self::ISSUER_NAME, $result['issuer_name']);
        $this->assertSame(self::COURSE_TITLE, $result['course_title']);
        $this->assertSame(self::VID, $result['verification_id']);
        $this->assertArrayHasKey('issued_at', $result);
        $this->assertArrayHasKey('expires_at', $result);
    }

    public function testTamperedInvalid(): void {
        $keyService = $this->keyServiceWith();
        $jwt = $this->signJwt($this->payload(self::VID));

        // Flip one char of the payload segment → signing input changes → sodium verify fails.
        [$h, $p, $s] = explode('.', $jwt);
        $p[0] = $p[0] === 'A' ? 'B' : 'A';
        $tampered = $h . '.' . $p . '.' . $s;
        $this->assertNotSame($jwt, $tampered, 'tampering actually changed the JWT');

        $cert = $this->certificate(self::VID, $tampered);
        $service = $this->buildService(
            $this->certMapperFor($cert),
            $this->keyMapperFor($this->certKey(self::KEY_ID, 'active')),
            $keyService,
            $this->timeAt()
        );

        $this->assertSame('invalid', $service->verifyByVerificationId(self::VID)['status']);
    }

    /**
     * Codex #1 trust-anchor fix: the bytes verify, but the signing key's status is 'revoked'
     * (did.json disowns it) → the verdict MUST be 'invalid'. Uses a really-signed JWT so we
     * prove status overrides a GOOD signature (not a masked sig failure).
     */
    public function testRevokedSigningKeyInvalid(): void {
        $keyService = $this->keyServiceWith();
        $jwt = $this->signJwt($this->payload(self::VID));
        $cert = $this->certificate(self::VID, $jwt);

        $service = $this->buildService(
            $this->certMapperFor($cert),
            $this->keyMapperFor($this->certKey(self::KEY_ID, 'revoked')),
            $keyService,
            $this->timeAt()
        );

        $this->assertSame('invalid', $service->verifyByVerificationId(self::VID)['status']);
    }

    /**
     * Rotation correctness: a cert is verified against the key that SIGNED it
     * (findByKeyId(cert.key_id)), NOT the active key. A 'retired' key still verifies its own cert.
     * The discriminating lever is asserting findByKeyId is called WITH cert.key_id.
     */
    public function testVerifiesAgainstCertKeyNotActiveKey(): void {
        $keyService = $this->keyServiceWith();
        $jwt = $this->signJwt($this->payload(self::VID));
        $cert = $this->certificate(self::VID, $jwt, self::KEY_ID);

        $keyMapper = $this->createMock(CertKeyMapper::class);
        $keyMapper->expects($this->once())
            ->method('findByKeyId')
            ->with(self::KEY_ID)                          // the cert's OWN key, not the active one
            ->willReturn($this->certKey(self::KEY_ID, 'retired'));

        $service = $this->buildService(
            $this->certMapperFor($cert),
            $keyMapper,
            $keyService,
            $this->timeAt()
        );

        $this->assertSame('valid', $service->verifyByVerificationId(self::VID)['status']);
    }

    /**
     * Codex #2 claim-binding: a credential_json validly signed by the SAME key but whose payload
     * id != urn:uuid:<vid> must be 'invalid' (byte-verify alone is insufficient). Positive control:
     * the SAME JWT verifies as 'valid' when looked up under ITS OWN matching vid — proving it is the
     * id-mismatch rejecting, not a signature failure.
     */
    public function testClaimBindingRejectsSubstitution(): void {
        $keyService = $this->keyServiceWith();
        $otherVid = '11111111-2222-4333-8444-555555555555';

        // JWT whose payload id = urn:uuid:<otherVid>, validly signed.
        $jwt = $this->signJwt($this->payload($otherVid));

        // Stored in a cert row that is LOOKED UP under self::VID → id-mismatch → invalid.
        $substituted = $this->certificate(self::VID, $jwt);
        $service = $this->buildService(
            $this->certMapperFor($substituted),
            $this->keyMapperFor($this->certKey(self::KEY_ID, 'active')),
            $keyService,
            $this->timeAt()
        );
        $this->assertSame(
            'invalid',
            $service->verifyByVerificationId(self::VID)['status'],
            'a JWT bound to a DIFFERENT vid must not pass under self::VID'
        );

        // Positive control: SAME JWT under its OWN matching vid → valid (so it was the id binding).
        $matched = $this->certificate($otherVid, $jwt);
        $serviceOk = $this->buildService(
            $this->certMapperFor($matched),
            $this->keyMapperFor($this->certKey(self::KEY_ID, 'active')),
            $this->keyServiceWith(),
            $this->timeAt()
        );
        $this->assertSame(
            'valid',
            $serviceOk->verifyByVerificationId($otherVid)['status'],
            'the same JWT is valid under its own vid — proves the rejection was id-binding, not sig'
        );
    }

    /**
     * Issuer.id binding: a JWT whose payload issuer.id != hostDid() must be 'invalid' even though
     * the bytes verify and the credential id matches.
     */
    public function testClaimBindingRejectsForeignIssuer(): void {
        $keyService = $this->keyServiceWith();
        $jwt = $this->signJwt($this->payload(self::VID, 'did:web:evil.example:apps:learning'));
        $cert = $this->certificate(self::VID, $jwt);

        $service = $this->buildService(
            $this->certMapperFor($cert),
            $this->keyMapperFor($this->certKey(self::KEY_ID, 'active')),
            $keyService,
            $this->timeAt()
        );

        $this->assertSame('invalid', $service->verifyByVerificationId(self::VID)['status']);
    }

    /**
     * Expiry precedence with a fake clock: expires_at < now → 'expired'; expires_at NULL →
     * never expires (→ valid).
     */
    public function testExpiryPrecedence(): void {
        $keyService = $this->keyServiceWith();
        $jwt = $this->signJwt($this->payload(self::VID));

        // expired: expires_at strictly before now.
        $expiredCert = $this->certificate(self::VID, $jwt, self::KEY_ID, false, null, self::NOW - 1);
        $expiredService = $this->buildService(
            $this->certMapperFor($expiredCert),
            $this->keyMapperFor($this->certKey(self::KEY_ID, 'active')),
            $keyService,
            $this->timeAt(self::NOW)
        );
        $this->assertSame('expired', $expiredService->verifyByVerificationId(self::VID)['status']);

        // NULL expiry never expires.
        $foreverCert = $this->certificate(self::VID, $jwt, self::KEY_ID, false, null, null);
        $foreverService = $this->buildService(
            $this->certMapperFor($foreverCert),
            $this->keyMapperFor($this->certKey(self::KEY_ID, 'active')),
            $this->keyServiceWith(),
            $this->timeAt(self::NOW)
        );
        $this->assertSame('valid', $foreverService->verifyByVerificationId(self::VID)['status']);
    }

    /**
     * BLOCKER regression (pre-release grumpy-Codex gate): the SIGNED `validUntil` is the tamper-evident
     * expiry authority — a DB-only `expires_at` extension must NOT make a signed-expired credential read
     * 'valid'. Signed validUntil in the PAST + DB expires_at in the FUTURE → 'expired'.
     */
    public function testSignedValidUntilBeatsFutureDbExpiry(): void {
        $keyService = $this->keyServiceWith();
        $payload = $this->payload(self::VID);
        $payload['validUntil'] = '2023-01-01T00:00:00+00:00'; // signed expiry: before NOW
        $jwt = $this->signJwt($payload);

        // DB expires_at far in the FUTURE (the "extension" an attacker with DB write could set).
        $cert = $this->certificate(self::VID, $jwt, self::KEY_ID, false, null, self::NOW + 10_000_000);
        $service = $this->buildService(
            $this->certMapperFor($cert),
            $this->keyMapperFor($this->certKey(self::KEY_ID, 'active')),
            $keyService,
            $this->timeAt(self::NOW)
        );

        $this->assertSame('expired', $service->verifyByVerificationId(self::VID)['status']);
    }

    /**
     * Tombstone: revoked=true → 'withdrawn' + revoked_at present (HTTP-200 tombstone, NOT 404),
     * and it takes precedence over expiry.
     */
    public function testRevokedTombstone(): void {
        $keyService = $this->keyServiceWith();
        $jwt = $this->signJwt($this->payload(self::VID));
        // Also expired, to prove withdrawn wins the precedence.
        $cert = $this->certificate(self::VID, $jwt, self::KEY_ID, true, self::NOW - 100, self::NOW - 1);

        $service = $this->buildService(
            $this->certMapperFor($cert),
            $this->keyMapperFor($this->certKey(self::KEY_ID, 'active')),
            $keyService,
            $this->timeAt(self::NOW)
        );

        $result = $service->verifyByVerificationId(self::VID);
        $this->assertSame('withdrawn', $result['status'], 'revoked precedes expired');
        $this->assertArrayHasKey('revoked_at', $result);
        $this->assertSame(self::NOW - 100, $result['revoked_at']);
    }

    public function testUnknownWhenNotFound(): void {
        $keyService = $this->keyServiceWith();
        $certMapper = $this->createMock(CertificateMapper::class);
        $certMapper->method('findByVerificationId')
            ->willThrowException(new DoesNotExistException('no cert'));

        $service = $this->buildService(
            $certMapper,
            $this->createMock(CertKeyMapper::class),
            $keyService,
            $this->timeAt()
        );

        $this->assertSame('unknown', $service->verifyByVerificationId(self::VID)['status']);
    }

    // ---- RECERT-04 / SC2 RED locking test (Wave 2, 164-02) --------------------------------

    /**
     * SC2 lock: after AssignmentService::closePeriod(), the old cert URL must return 'expired'
     * NOT 'withdrawn'. Period-close MUST NOT set revoked=true on the certificate.
     *
     * CertificateVerifyService status precedence (LOCKED):
     *   not found → 'unknown' | key/sig failures → 'invalid'
     *   revoked=true  → 'withdrawn'    ← SC2: this must NOT happen after period-close
     *   expires_at<now → 'expired'     ← this IS the correct post-close state
     *   else → 'valid'
     *
     * The cert is seeded in the EXPECTED post-close state: revoked=false, expires_at<now.
     * closePeriod must null active_period_key/active_idem_key WITHOUT touching revoked/revoked_at,
     * so the cert falls through to the 'expired' branch — NOT the 'withdrawn' branch.
     *
     * RED at Wave 2: AssignmentService::closePeriod() throws LogicException (stub).
     * The test ERRORS at the closePeriod() call — the SC2 assertions below are unreachable.
     * Do NOT use $this->expectException() — that inverts it to GREEN-now / RED-later.
     * GREEN in 164-04: closePeriod() implemented; cert stays revoked=false → reads 'expired'.
     */
    public function testClosedPeriodReadsExpired(): void {
        $jwt = $this->signJwt($this->payload(self::VID));

        // PRE-CLOSE: cert with FUTURE expires_at — the old URL must read 'valid' while the
        // period is still open. (payload has no validUntil, so only $dbExp is checked.)
        $futureExpiry = self::NOW + 86_400;
        $certPreClose = $this->certificate(self::VID, $jwt, self::KEY_ID, false, null, $futureExpiry);

        $keyService = $this->keyServiceWith();
        $servicePre = $this->buildService(
            $this->certMapperFor($certPreClose),
            $this->keyMapperFor($this->certKey(self::KEY_ID, 'active')),
            $keyService,
            $this->timeAt(self::NOW)
        );
        $preCl = $servicePre->verifyByVerificationId(self::VID);
        $this->assertSame('valid', $preCl['status'],
            'PRE-close: cert with future expires_at must read "valid" before period is closed');

        // Instantiate the real AssignmentService — closePeriod is the code under test (164-04).
        // FakeDbConnection with 3 builders: one per DB write in closePeriod() —
        //   builder 0: UPDATE learning_certificates (expires_at + active_idem_key) — the CAS
        //              gate; executeStatementResult=1 means "this call closed the cert"
        //   builder 1: UPDATE learning_assignments (active_period_key → NULL)
        //   builder 2: INSERT learning_assignments (fresh period row)
        $assignmentService = new AssignmentService(
            new FakeDbConnection([new FakeQueryBuilder(null, 1), new FakeQueryBuilder(), new FakeQueryBuilder()]),
            $this->createMock(IGroupManager::class),
            $this->createMock(AuditService::class),
        );

        // GREEN in 164-04: closePeriod() executes the 3 writes without throwing.
        // certId=42 pins the close to the specific cert (post-impl-review CAS hardening).
        // The SC2 assertions below become reachable.
        $assignmentService->closePeriod('user', 'jmueller', 7, 42);

        // UNREACHABLE at Wave 2. After 164-04 implements closePeriod, these SC2 assertions run.
        // closePeriod must move expires_at into the past WITHOUT touching revoked/revoked_at —
        // so the old URL falls through to 'expired', not 'withdrawn'.
        $certPost = $this->certificate(self::VID, $jwt, self::KEY_ID, false, null, self::NOW - 1);
        $servicePost = $this->buildService(
            $this->certMapperFor($certPost),
            $this->keyMapperFor($this->certKey(self::KEY_ID, 'active')),
            $this->keyServiceWith(),
            $this->timeAt(self::NOW)
        );
        $result = $servicePost->verifyByVerificationId(self::VID);
        $this->assertSame('expired', $result['status'],
            'SC2: after period-close the old cert must read "expired" (revoked=false, expires_at<now)');
        $this->assertNotSame('withdrawn', $result['status'],
            'SC2: closePeriod MUST NOT set revoked=true — that would make the old URL read "withdrawn"');
    }

    /**
     * VERIFY-03 (DSGVO, load-bearing): the projected DTO, serialized, contains NONE of the recipient
     * name, user_id, key_id, credential_json, or active_idem_key. The fixture genuinely carries
     * "Jürgen Müller" in credentialSubject.name so the assertion is not vacuous.
     */
    public function testDtoNoLeak(): void {
        $keyService = $this->keyServiceWith();
        $jwt = $this->signJwt($this->payload(self::VID));
        $cert = $this->certificate(self::VID, $jwt);

        $service = $this->buildService(
            $this->certMapperFor($cert),
            $this->keyMapperFor($this->certKey(self::KEY_ID, 'active')),
            $keyService,
            $this->timeAt()
        );

        $result = $service->verifyByVerificationId(self::VID);
        $this->assertSame('valid', $result['status']);

        $serialized = json_encode($result);
        $this->assertIsString($serialized);
        $this->assertStringNotContainsString(self::RECIPIENT, $serialized, 'recipient name must not leak');
        $this->assertStringNotContainsString('jmueller', $serialized, 'user_id must not leak');
        $this->assertStringNotContainsString(self::KEY_ID, $serialized, 'key_id must not leak');
        $this->assertStringNotContainsString('credential_json', $serialized, 'no raw JWT field');
        $this->assertStringNotContainsString($jwt, $serialized, 'the compact JWT must not be embedded');
        $this->assertStringNotContainsString('active_idem_key', $serialized, 'no idem key');

        // And positively: only the allow-listed public keys are present.
        $this->assertEqualsCanonicalizing(
            ['status', 'issuer_name', 'course_title', 'issued_at', 'expires_at', 'verification_id'],
            array_keys($result),
            'valid DTO exposes ONLY the allow-listed public fields'
        );
    }
}
