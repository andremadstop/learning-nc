<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\Certificate;
use OCA\Learning\Db\CertKeyMapper;
use OCA\Learning\Db\CertificateMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * CertificateVerifyService — the cryptographic verification core of the public verify route
 * (Phase 157, VERIFY-02..05). Given only a public verification_id it resolves the certificate,
 * verifies the Ed25519 signature against the key that SIGNED it, BINDS the claim (not just the
 * bytes), applies the locked status precedence, and projects a strict DSGVO DTO.
 *
 * A verdict of 'valid' is the AND of ALL of:
 *   1. the cert resolves, and its signing key resolves and is NOT revoked
 *      (CertKeyMapper::findByKeyId returns revoked rows too — did.json disowns them; Codex #1);
 *   2. the public key base64url-decodes to exactly 32 bytes;
 *   3. SigningService::verify() (header-contract gate + sodium detached verify) passes — reused
 *      verbatim, NOT re-implemented;
 *   4. claim binding (Codex #2 — lives HERE, not in SigningService): header kid ==
 *      hostDid().'#'.cert.key_id AND payload issuer.id == hostDid() AND credential id ==
 *      'urn:uuid:'.verificationId. A credential_json lifted from a DIFFERENT cert signed by the
 *      same key would pass byte-verify but fails this binding.
 * A valid signature alone is NEVER sufficient.
 *
 * The returned array carries a 'status' (unknown|invalid|withdrawn|expired|valid) and, on the
 * found+sig-OK branches, a server-side-projected public DTO that NEVER contains the recipient name,
 * user_id, key_id, credential_json, or active_idem_key. Certificate::jsonSerialize() is never called.
 *
 * Carry-forward: 156's CertificateReportService decodes an UNVERIFIED JWT payload — this service is
 * the reusable verify-before-decode primitive a future 156 hardening pass can adopt (do NOT reopen
 * 156 here).
 */
class CertificateVerifyService {

    /** Raw 32-byte Ed25519 public key length (== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES). */
    private const PUBLIC_KEY_BYTES = SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES;

    private CertificateMapper $certificateMapper;
    private CertKeyMapper $certKeyMapper;
    private SigningService $signingService;
    private KeyService $keyService;
    private ITimeFactory $timeFactory;

    public function __construct(
        CertificateMapper $certificateMapper,
        CertKeyMapper $certKeyMapper,
        SigningService $signingService,
        KeyService $keyService,
        ITimeFactory $timeFactory
    ) {
        $this->certificateMapper = $certificateMapper;
        $this->certKeyMapper = $certKeyMapper;
        $this->signingService = $signingService;
        $this->keyService = $keyService;
        $this->timeFactory = $timeFactory;
    }

    /**
     * Verify a certificate by its public verification_id and project a DSGVO-safe result.
     *
     * Status precedence (LOCKED):
     *   not found                                              → 'unknown'
     *   key unresolved | key revoked | wrong key length | sig fail | claim mismatch | bad payload
     *                                                          → 'invalid'
     *   revoked                                                → 'withdrawn' (+ revoked_at)
     *   expires_at !== null && expires_at < now                → 'expired'
     *   else                                                   → 'valid'
     *
     * @return array<string, mixed> always has a 'status' key; found+sig-OK branches add the public DTO.
     */
    public function verifyByVerificationId(string $vid): array {
        try {
            $cert = $this->certificateMapper->findByVerificationId($vid);
        } catch (DoesNotExistException $e) {
            return ['status' => 'unknown'];
        }

        // Resolve the key that SIGNED this cert (rotation correctness: NOT the active key).
        try {
            $key = $this->certKeyMapper->findByKeyId($cert->getKeyId());
        } catch (DoesNotExistException $e) {
            return ['status' => 'invalid'];
        }
        // Codex #1: did.json only publishes non-revoked keys — a revoked signing key is disowned.
        if ($key->getStatus() === 'revoked') {
            return ['status' => 'invalid'];
        }

        // public_key_b64u is the raw 32-byte Ed25519 key, base64url; decode + length-assert.
        $publicRaw = base64_decode(strtr($key->getPublicKeyB64u(), '-_', '+/'), true);
        if ($publicRaw === false || strlen($publicRaw) !== self::PUBLIC_KEY_BYTES) {
            return ['status' => 'invalid'];
        }

        // Reuse the proven Ed25519 detached verify (header-contract gate + sodium). Do NOT re-implement.
        if (!$this->signingService->verify($cert->getCredentialJson(), $publicRaw)) {
            return ['status' => 'invalid'];
        }

        // Claim binding (Codex #2): the bytes verified — now bind the CLAIM to THIS cert + issuer.
        $payload = $this->decodeAndBind($cert->getCredentialJson(), $cert->getKeyId(), $vid);
        if ($payload === null) {
            return ['status' => 'invalid'];
        }

        $dto = $this->projectDto($cert, $payload, $vid);

        if ($cert->getRevoked()) {
            return ['status' => 'withdrawn', 'revoked_at' => $cert->getRevokedAt()] + $dto;
        }

        // Expiry. The SIGNED `validUntil` (covered by the EdDSA signature, already verified above) is the
        // tamper-evident authority — NOT the mutable DB `expires_at` column. A DB-only `expires_at`
        // extension must NOT make a signed-expired credential read 'valid' (an independent verifier reads
        // the signed validUntil and would expire it). We expire if EITHER source is past: the signed time
        // is the authority; the DB column stays as defence-in-depth (and the only source when a cert has
        // no validUntil — non-expiring certs carry neither). Found by the pre-release grumpy-Codex gate.
        $now = $this->timeFactory->getTime();
        $signedExp = $this->parseIso8601($payload['validUntil'] ?? null);
        $dbExp = $cert->getExpiresAt();
        if (($signedExp !== null && $signedExp < $now) || ($dbExp !== null && $dbExp < $now)) {
            return ['status' => 'expired'] + $dto;
        }

        return ['status' => 'valid'] + $dto;
    }

    /**
     * Derived lifecycle state seam (RECERT-01/03) — frozen in Wave 2 (164-02), implemented in Wave 4 (164-04).
     *
     * Computes a richer lifecycle label layered on top of verifyByVerificationId's base status:
     *   'valid'     — cert not yet in its recert window
     *   'expiring'  — within RECERT_GRACE_DAYS_DEFAULT of expiry (reminder window)
     *   'overdue'   — past due but still in grace period (obligation period overdue)
     *   'expired'   — past expiry without renewal
     *
     * This method is the presentation-layer bridge between the base cryptographic status (verifyByVerificationId)
     * and the recert-workflow UI states (RECERT-01: "approaching expiry" dashboard banner).
     *
     * Do NOT change verifyByVerificationId() to call this — it is a separate concern (immutable verify
     * status precedence must stay locked).
     *
     * @return string always 'valid' at Wave 2 (stub); returns computed state in 164-04
     */
    public function deriveLifecycleState(Certificate $cert, int $now): string {
        return 'valid'; // stub — real lifecycle computation wired in 164-04
    }

    /**
     * Parse an ISO-8601 UTC timestamp (e.g. "2027-06-28T13:22:33Z") from the signed payload to a unix
     * time. Any non-string / malformed value → null (never throws — a hostile payload must not crash the
     * public route). Mirrors IssuanceService::iso8601() on the write side.
     */
    private function parseIso8601(mixed $value): ?int {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $ts = strtotime($value);
        return $ts === false ? null : $ts;
    }

    /**
     * Decode header + payload and enforce claim binding. Returns the decoded payload array when ALL
     * of header.kid == hostDid()#keyId, payload.issuer.id == hostDid(), payload.id == urn:uuid:vid
     * hold; otherwise null. Any decode failure or non-string/missing field is a mismatch → null
     * (never throws to the caller — a hostile payload, e.g. issuer-as-string, must not crash the
     * public route).
     *
     * @return array<string, mixed>|null
     */
    private function decodeAndBind(string $jwt, string $keyId, string $vid): ?array {
        try {
            $parts = explode('.', $jwt);
            if (count($parts) !== 3) {
                return null;
            }

            $header = $this->decodeSegment($parts[0]);
            $payload = $this->decodeSegment($parts[1]);
            if ($header === null || $payload === null) {
                return null;
            }

            $kid = $header['kid'] ?? null;
            $issuerId = is_array($payload['issuer'] ?? null) ? ($payload['issuer']['id'] ?? null) : null;
            $id = $payload['id'] ?? null;

            $expectedKid = $this->keyService->hostDid() . '#' . $keyId;
            $expectedId = 'urn:uuid:' . $vid;

            if (!is_string($kid) || !hash_equals($expectedKid, $kid)) {
                return null;
            }
            if (!is_string($issuerId) || !hash_equals($this->keyService->hostDid(), $issuerId)) {
                return null;
            }
            if (!is_string($id) || !hash_equals($expectedId, $id)) {
                return null;
            }

            return $payload;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Strict base64url JSON decode of one JWT segment (mirrors CertificateReportService::decodePayload):
     * strtr('-_'→'+/') then base64_decode strict, json_decode to an associative array. Any failure → null.
     *
     * @return array<string, mixed>|null
     */
    private function decodeSegment(string $segment): ?array {
        $decoded = base64_decode(strtr($segment, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }
        $parsed = json_decode($decoded, true);
        return is_array($parsed) ? $parsed : null;
    }

    /**
     * Project the strict DSGVO public DTO from the (already verified + bound) payload. Reads ONLY
     * the issuer name + the achievement (course) title from the payload, and the timestamps + vid
     * from the cert row. NEVER reads credentialSubject.name (recipient PII), and never exposes
     * user_id / key_id / credential_json / active_idem_key. Certificate::jsonSerialize() is not called.
     *
     * @param array<string, mixed> $payload
     * @return array{issuer_name: string, course_title: string, issued_at: int, expires_at: int|null, verification_id: string}
     */
    private function projectDto(Certificate $cert, array $payload, string $vid): array {
        $issuerName = is_array($payload['issuer'] ?? null) && is_string($payload['issuer']['name'] ?? null)
            ? $payload['issuer']['name']
            : '';

        $courseTitle = '';
        $subject = $payload['credentialSubject'] ?? null;
        if (is_array($subject) && is_array($subject['achievement'] ?? null)
            && is_string($subject['achievement']['name'] ?? null)) {
            $courseTitle = $subject['achievement']['name'];
        }

        return [
            'issuer_name' => $issuerName,
            'course_title' => $courseTitle,
            'issued_at' => $cert->getIssuedAt(),
            'expires_at' => $cert->getExpiresAt(),
            'verification_id' => $vid,
        ];
    }
}
