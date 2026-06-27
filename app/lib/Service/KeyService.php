<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\CertKey;
use OCA\Learning\Db\CertKeyMapper;
use OCP\IURLGenerator;

/**
 * KeyService — issuer cryptographic identity (Phase 155, CERT-01/03/04).
 *
 * Generates Ed25519 signing keypairs via ext-sodium, stores ONLY an ICrypto ciphertext
 * of the secret key (never plaintext), and supports rotation that retires — never deletes —
 * old keys so past certificates keep verifying.
 *
 * NO signing happens here (that is 155-03). This is the key + did:web foundation.
 */
class KeyService {
    private CertKeyMapper $certKeyMapper;
    private EncryptionService $encryptionService;
    private IURLGenerator $urlGenerator;

    public function __construct(
        CertKeyMapper $certKeyMapper,
        EncryptionService $encryptionService,
        IURLGenerator $urlGenerator
    ) {
        $this->certKeyMapper = $certKeyMapper;
        $this->encryptionService = $encryptionService;
        $this->urlGenerator = $urlGenerator;
    }

    /**
     * Generate a fresh Ed25519 keypair and persist it as the active signing key.
     * The 64-byte secret is encrypted at rest (ICrypto) and zeroed from memory.
     *
     * @throws \RuntimeException if ext-sodium is missing, an active key already exists,
     *                           or the secret failed to encrypt.
     */
    public function init(): CertKey {
        if ($this->certKeyMapper->findActive() !== null) {
            throw new \RuntimeException('An active signing key already exists — use --rotate to rotate it');
        }
        return $this->generateActiveKey();
    }

    /**
     * Generate + persist a fresh Ed25519 keypair as an active signing key.
     *
     * Deliberately does NOT pre-check findActive() — that guard belongs to public init(). rotate()
     * relies on this so it can durably insert the NEW active key while the OLD one is still active,
     * eliminating any zero-active-key window (FIX 4 / R6-6).
     *
     * @throws \RuntimeException if ext-sodium is missing or the secret failed to encrypt.
     */
    private function generateActiveKey(): CertKey {
        if (!extension_loaded('sodium')) {
            throw new \RuntimeException('ext-sodium is required to generate issuer signing keys');
        }

        $keypair = sodium_crypto_sign_keypair();
        $public = sodium_crypto_sign_publickey($keypair); // 32 bytes
        $secret = sodium_crypto_sign_secretkey($keypair); // 64 bytes

        $publicKeyB64u = $this->base64url($public);
        $keyId = $publicKeyB64u; // kid == base64url(public) == did:web fragment

        $secretB64 = base64_encode($secret);
        $secretEnc = $this->encryptionService->encrypt($secretB64);

        // Defence in depth: wipe plaintext secret material as soon as it is encrypted.
        sodium_memzero($secret);
        sodium_memzero($keypair);

        // Never store plaintext: a null/empty/passthrough ciphertext means encryption failed.
        if ($secretEnc === null || $secretEnc === '' || $secretEnc === $secretB64) {
            sodium_memzero($secretB64);
            throw new \RuntimeException('Failed to encrypt issuer secret key at rest');
        }
        sodium_memzero($secretB64);

        $key = new CertKey();
        $key->setKeyId($keyId);
        $key->setPublicKeyB64u($publicKeyB64u);
        $key->setSecretKeyEnc($secretEnc);
        $key->setStatus('active');
        $key->setCreatedAt(time());

        return $this->certKeyMapper->insert($key);
    }

    /**
     * Rotate the issuer key. Ordering is safety-critical (FIX 4 / R6-6): the NEW active key is
     * generated and durably inserted FIRST, and only AFTER that succeeds is the previous active key
     * retired (update, never delete). If new-key creation throws, the old key stays active — there is
     * never a zero-active-key window. Retired keys stay published in did.json so past certificates
     * remain verifiable.
     *
     * @throws \RuntimeException if ext-sodium is missing or the new secret failed to encrypt.
     */
    public function rotate(): CertKey {
        $previous = $this->certKeyMapper->findActive();

        // Create + persist the replacement BEFORE touching the incumbent. A throw here leaves the
        // old active key untouched (still the sole active key).
        $new = $this->generateActiveKey();

        // New key is durably inserted — now safe to retire the previous one.
        if ($previous !== null) {
            $previous->setStatus('retired');
            $this->certKeyMapper->update($previous);
        }

        return $new;
    }

    /**
     * Decrypt the active key's secret for signing (used by 155-04).
     *
     * HARD-ERRORS if the decrypted bytes are not a valid 64-byte Ed25519 secret key —
     * this defeats EncryptionService::decrypt()'s silent plaintext fallback, which would
     * otherwise hand back the ciphertext and corrupt every signature.
     *
     * @return array{key: CertKey, secret: string}
     * @throws \RuntimeException if there is no active key or the decrypt is invalid.
     */
    public function getActiveSigningMaterial(): array {
        $active = $this->certKeyMapper->findActive();
        if ($active === null) {
            throw new \RuntimeException('No active signing key — run `occ learning:cert:init-issuer`');
        }

        $secretB64 = $this->encryptionService->decrypt($active->getSecretKeyEnc());
        if ($secretB64 === null || $secretB64 === '') {
            throw new \RuntimeException('Failed to decrypt issuer secret key');
        }

        $secret = base64_decode($secretB64, true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException(
                'Decrypted issuer secret key is invalid (plaintext fallback or corruption) — refusing to sign'
            );
        }

        return ['key' => $active, 'secret' => $secret];
    }

    /**
     * The instance's path-based did:web identifier: did:web:<host>:apps:learning.
     * Single source of truth so KeyService and DidController agree on the kid prefix.
     */
    public function hostDid(): string {
        $host = parse_url($this->urlGenerator->getBaseUrl(), PHP_URL_HOST);
        if (!is_string($host)) {
            $host = '';
        }
        return 'did:web:' . $host . ':apps:learning';
    }

    /**
     * URL-safe, unpadded base64 (RFC 4648 §5) — the JWK `x` / did:web fragment encoding.
     */
    private function base64url(string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
