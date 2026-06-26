<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Db;

use OCA\Learning\Db\CertKey;
use OCA\Learning\Db\Certificate;
use PHPUnit\Framework\TestCase;

/**
 * CERT-03 leakage primitive + entity hydration contract (155-01).
 *
 * The decisive assertion is structural: CertKey::jsonSerialize() must never expose
 * the encrypted secret key. This is re-audited in 155-07.
 */
class CertEntityTest extends TestCase {

    private function makeKey(): CertKey {
        $key = new CertKey();
        $key->setKeyId('abc123kid');
        $key->setPublicKeyB64u('cHVibGljLWtleS1iNjR1');
        $key->setSecretKeyEnc('ENCRYPTED-SECRET-DO-NOT-LEAK');
        $key->setStatus('active');
        $key->setCreatedAt(1750000000);
        return $key;
    }

    public function testJsonSerializeOmitsSecretKey(): void {
        $json = $this->makeKey()->jsonSerialize();

        // Structural absence of the secret column key.
        $this->assertArrayNotHasKey('secret_key_enc', $json);
        // And the raw secret must not appear under any key.
        $this->assertNotContains('ENCRYPTED-SECRET-DO-NOT-LEAK', $json, 'Raw secret leaked into jsonSerialize output');
    }

    public function testJsonSerializeContainsPublicFields(): void {
        $json = $this->makeKey()->jsonSerialize();

        $this->assertArrayHasKey('key_id', $json);
        $this->assertArrayHasKey('public_key_b64u', $json);
        $this->assertArrayHasKey('status', $json);
        $this->assertArrayHasKey('created_at', $json);

        $this->assertSame('abc123kid', $json['key_id']);
        $this->assertSame('cHVibGljLWtleS1iNjR1', $json['public_key_b64u']);
        $this->assertSame('active', $json['status']);
        $this->assertSame(1750000000, $json['created_at']);
    }

    public function testCertificateHydratesFields(): void {
        $cert = new Certificate();
        $cert->setVerificationId('11111111-2222-4333-8444-555555555555');
        $cert->setUserId('alice');
        $cert->setCourseId(7);
        $cert->setKeyId('abc123kid');
        $cert->setCredentialJson('eyJhbGciOiJFZERTQSJ9.payload.sig');
        $cert->setIssuedAt(1750000000);
        $cert->setExpiresAt(null);

        $this->assertSame('11111111-2222-4333-8444-555555555555', $cert->getVerificationId());
        $this->assertSame('abc123kid', $cert->getKeyId());
        $this->assertSame('eyJhbGciOiJFZERTQSJ9.payload.sig', $cert->getCredentialJson());
        $this->assertNull($cert->getExpiresAt());

        // Non-null expiry round-trips through the integer type cast.
        $cert->setExpiresAt(1760000000);
        $this->assertSame(1760000000, $cert->getExpiresAt());
    }
}
