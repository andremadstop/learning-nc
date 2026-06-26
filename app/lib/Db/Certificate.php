<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Issued verifiable credential (table learning_certificates) — Phase 155.
 *
 * credential_json holds the signed compact JWT (public + shareable). key_id references
 * CertKey.keyId so key rotation never invalidates already-issued certificates.
 * expires_at is nullable (null = no expiry).
 *
 * @method string getVerificationId()
 * @method void setVerificationId(string $verificationId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getCourseId()
 * @method void setCourseId(int $courseId)
 * @method string getKeyId()
 * @method void setKeyId(string $keyId)
 * @method string getCredentialJson()
 * @method void setCredentialJson(string $credentialJson)
 * @method bool getRevoked()
 * @method void setRevoked(bool $revoked)
 * @method int getIssuedAt()
 * @method void setIssuedAt(int $issuedAt)
 * @method int|null getExpiresAt()
 * @method void setExpiresAt(?int $expiresAt)
 */
class Certificate extends Entity implements \JsonSerializable {
    protected $verificationId;
    protected $userId;
    protected $courseId;
    protected $keyId;
    protected $credentialJson;
    protected $revoked;
    protected $issuedAt;
    protected $expiresAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('verificationId', 'string');
        $this->addType('userId', 'string');
        $this->addType('courseId', 'integer');
        $this->addType('keyId', 'string');
        $this->addType('credentialJson', 'string');
        $this->addType('revoked', 'boolean');
        $this->addType('issuedAt', 'integer');
        $this->addType('expiresAt', 'integer');
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'verification_id' => $this->verificationId,
            'user_id' => $this->userId,
            'course_id' => $this->courseId,
            'key_id' => $this->keyId,
            'credential_json' => $this->credentialJson,
            'revoked' => (bool)$this->revoked,
            'issued_at' => $this->issuedAt,
            'expires_at' => $this->expiresAt,
        ];
    }
}
