<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\CertKey;

/**
 * SigningService — VC-JWT (VC-JOSE-COSE) EdDSA signer (Phase 155, CERT-06).
 *
 * Turns an OB3 credential object into a compact VC-JWT signed with Ed25519 via ext-sodium.
 * The signing contract is FROZEN in 155-ADR-ANCHOR.md and satisfied verbatim here:
 *
 *  - JWS header (exactly): {"alg":"EdDSA","typ":"vc+jwt","cty":"vc","kid":"<verificationMethod.id>"}
 *  - Payload = the credential object DIRECTLY — NO `vc`/`vp` wrapper, NO iss/sub/nbf/jti mirroring.
 *  - Byte fidelity: both header and payload are serialized with JSON_UNESCAPED_SLASHES so the SIGNED
 *    bytes are exactly the bytes that get base64url-encoded and emitted. There is no canonicalization
 *    path — signed bytes == emitted bytes by construction.
 *  - `kid` is derived from KeyService::hostDid() (the SAME source DidController uses for
 *    verificationMethod.id), so the JWT kid can never drift from did.json (Pitfall 4).
 *
 * NO Composer dependencies (ext-sodium is a PHP core extension). Verification is provided both by
 * verify() (sodium) and, independently, by the dev-only scripts/verify-credential.py (Python).
 */
class SigningService {
    private KeyService $keyService;

    public function __construct(KeyService $keyService) {
        $this->keyService = $keyService;
    }

    /**
     * Sign a credential object into a compact VC-JWT.
     *
     * @param array<string, mixed> $credential OB3 credential object — becomes the payload verbatim.
     * @param CertKey $key The signing key (its keyId forms the kid fragment).
     * @param string $secretKeyRaw The raw 64-byte Ed25519 secret key (from KeyService::getActiveSigningMaterial()).
     * @return string The compact JWT: base64url(header).base64url(payload).base64url(signature).
     * @throws \RuntimeException if JSON encoding fails.
     */
    public function sign(array $credential, CertKey $key, string $secretKeyRaw): string {
        $header = [
            'alg' => 'EdDSA',
            'typ' => 'vc+jwt',
            'cty' => 'vc',
            'kid' => $this->keyService->hostDid() . '#' . $key->getKeyId(),
        ];

        $headerJson = json_encode($header, JSON_UNESCAPED_SLASHES);
        $payloadJson = json_encode($credential, JSON_UNESCAPED_SLASHES);
        if ($headerJson === false || $payloadJson === false) {
            throw new \RuntimeException('Failed to JSON-encode credential for signing');
        }

        $signingInput = $this->b64u($headerJson) . '.' . $this->b64u($payloadJson);
        $signature = sodium_crypto_sign_detached($signingInput, $secretKeyRaw);

        return $signingInput . '.' . $this->b64u($signature);
    }

    /**
     * Verify a compact VC-JWT's Ed25519 signature against a raw 32-byte public key.
     * Operates on the `header.payload` signing input — does NOT validate VC claims.
     */
    public function verify(string $jwt, string $publicKeyRaw): bool {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return false;
        }
        $signingInput = $parts[0] . '.' . $parts[1];
        $signature = base64_decode(strtr($parts[2], '-_', '+/'), true);
        if ($signature === false) {
            return false;
        }
        try {
            return sodium_crypto_sign_verify_detached($signature, $signingInput, $publicKeyRaw);
        } catch (\SodiumException $e) {
            return false;
        }
    }

    /**
     * URL-safe, unpadded base64 (RFC 4648 §5) — the JWS segment encoding.
     */
    public function b64u(string $bin): string {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
