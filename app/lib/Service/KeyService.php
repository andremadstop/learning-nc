<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\CertKey;
use OCA\Learning\Db\CertKeyMapper;
use OCP\IDBConnection;
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
    private IDBConnection $db;

    public function __construct(
        CertKeyMapper $certKeyMapper,
        EncryptionService $encryptionService,
        IURLGenerator $urlGenerator,
        IDBConnection $db
    ) {
        $this->certKeyMapper = $certKeyMapper;
        $this->encryptionService = $encryptionService;
        $this->urlGenerator = $urlGenerator;
        $this->db = $db;
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
        $secretB64 = base64_encode($secret);

        // try/finally guarantees every plaintext secret copy ($secret, $keypair, $secretB64) is wiped
        // even if encrypt() OR the DB insert throws (R8-8): the finally always runs before the
        // exception propagates. Declared before the try so the finally never touches an undefined var.
        try {
            $publicKeyB64u = $this->base64url($public);
            $keyId = $publicKeyB64u; // kid == base64url(public) == did:web fragment

            $secretEnc = $this->encryptionService->encrypt($secretB64);

            // Never store plaintext: a null/empty/passthrough ciphertext means encryption failed.
            if ($secretEnc === null || $secretEnc === '' || $secretEnc === $secretB64) {
                throw new \RuntimeException('Failed to encrypt issuer secret key at rest');
            }

            $key = new CertKey();
            $key->setKeyId($keyId);
            $key->setPublicKeyB64u($publicKeyB64u);
            $key->setSecretKeyEnc($secretEnc);
            $key->setStatus('active');
            $key->setCreatedAt(time());

            return $this->certKeyMapper->insert($key);
        } finally {
            sodium_memzero($secret);
            sodium_memzero($keypair);
            sodium_memzero($secretB64);
        }
    }

    /**
     * Rotate the issuer key. Ordering is safety-critical (FIX 4 / R6-6): the NEW active key is
     * generated and durably inserted FIRST, and only AFTER that succeeds is the previous active key
     * retired (update, never delete). Retired keys stay published in did.json so past certificates
     * remain verifiable.
     *
     * ATOMICITY (R6-6): both writes — insert-new + retire-old — run in a single DB transaction. If the
     * retire UPDATE fails, the whole rotation rolls back, so the NEW insert is undone too and exactly
     * one active key survives: the ORIGINAL. This closes the "two active keys" window a failed retire
     * would otherwise leave. A throw during new-key creation still leaves the incumbent the sole
     * active key (the transaction simply never commits).
     *
     * @throws \RuntimeException if ext-sodium is missing or the new secret failed to encrypt.
     */
    public function rotate(): CertKey {
        $previous = $this->certKeyMapper->findActive();

        $this->db->beginTransaction();
        try {
            // Create + persist the replacement inside the transaction.
            $new = $this->generateActiveKey();

            // Retire the incumbent in the SAME transaction — if this fails, the new insert rolls back
            // with it, never leaving two active keys.
            if ($previous !== null) {
                $previous->setStatus('retired');
                $this->certKeyMapper->update($previous);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            // The DB rollback undoes the new-key insert; the incumbent's in-memory status may have been
            // flipped to 'retired' before the failure, so restore it to match the rolled-back DB row.
            if ($previous !== null) {
                $previous->setStatus('active');
            }
            throw $e;
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
     * The instance's canonical path-based did:web identifier (FIX 6 / R5-5).
     *
     * Derived from the FULL IURLGenerator::getBaseUrl() — host, port AND any webroot/subpath — per
     * the did:web spec:
     *   - a non-default port is appended to the host as `%3A<port>` (the colon is percent-encoded),
     *   - an EXPLICIT default port (:443 on https, :80 on http) is treated as absent and OMITTED, so
     *     the canonical form is byte-identical to the no-port URL (no kid drift),
     *   - the URL path is split into colon-separated segments, with the app route `apps:learning`
     *     (where did.json is served) appended last.
     *
     * Examples:
     *   https://example.com                       → did:web:example.com:apps:learning
     *   https://example.com:443                    → did:web:example.com:apps:learning  (default port dropped)
     *   https://example.com:8443/nextcloud        → did:web:example.com%3A8443:nextcloud:apps:learning
     *
     * The plain root-domain / default-port / no-subpath case is UNCHANGED, so existing devcloud
     * expectations and the 155-02/03 kid derivation never drift.
     *
     * Single source of truth so KeyService and DidController agree on the kid prefix.
     */
    public function hostDid(): string {
        $base = $this->urlGenerator->getBaseUrl();

        $host = parse_url($base, PHP_URL_HOST);
        if (!is_string($host)) {
            $host = '';
        }

        $scheme = parse_url($base, PHP_URL_SCHEME);
        $port = parse_url($base, PHP_URL_PORT);
        if (is_int($port)) {
            // An explicit default port (https:443 / http:80) is canonically equivalent to no port —
            // dropping it keeps did:web byte-identical to the plain-host form (no kid drift).
            $isDefaultPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
            if (!$isDefaultPort) {
                $host .= '%3A' . $port;
            }
        }

        $segments = [];
        $path = parse_url($base, PHP_URL_PATH);
        if (is_string($path)) {
            foreach (explode('/', trim($path, '/')) as $segment) {
                if ($segment !== '') {
                    $segments[] = rawurlencode($segment);
                }
            }
        }
        $segments[] = 'apps';
        $segments[] = 'learning';

        return 'did:web:' . $host . ':' . implode(':', $segments);
    }

    /**
     * URL-safe, unpadded base64 (RFC 4648 §5) — the JWK `x` / did:web fragment encoding.
     */
    private function base64url(string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
