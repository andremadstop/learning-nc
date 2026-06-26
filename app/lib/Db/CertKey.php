<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Issuer signing key (table learning_cert_keys) — rotation foundation (Phase 155).
 *
 * secret_key_enc holds an ICrypto ciphertext of the base64(64-byte) Ed25519 secret key —
 * NEVER plaintext, and NEVER emitted by jsonSerialize(). The omission of secret_key_enc
 * is a CERT-03 leakage primitive (re-audited in 155-07): the secret is structurally absent
 * from any serialized form by construction.
 *
 * @method string getKeyId()
 * @method void setKeyId(string $keyId)
 * @method string getPublicKeyB64u()
 * @method void setPublicKeyB64u(string $publicKeyB64u)
 * @method string getSecretKeyEnc()
 * @method void setSecretKeyEnc(string $secretKeyEnc)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class CertKey extends Entity implements \JsonSerializable {
    protected $keyId;
    protected $publicKeyB64u;
    protected $secretKeyEnc;
    protected $status;
    protected $createdAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('keyId', 'string');
        $this->addType('publicKeyB64u', 'string');
        $this->addType('secretKeyEnc', 'string');
        $this->addType('status', 'string');
        $this->addType('createdAt', 'integer');
    }

    /**
     * Leakage-safe serialization: secret_key_enc is intentionally absent.
     * Only the non-sensitive columns are returned.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'key_id' => $this->keyId,
            'public_key_b64u' => $this->publicKeyB64u,
            'status' => $this->status,
            'created_at' => $this->createdAt,
        ];
    }
}
