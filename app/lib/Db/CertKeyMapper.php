<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<CertKey>
 */
class CertKeyMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_cert_keys', CertKey::class);
    }

    /**
     * Find a signing key by its key_id (== JWT kid fragment).
     *
     * @throws DoesNotExistException if no key matches
     */
    public function findByKeyId(string $keyId): CertKey {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('key_id', $qb->createNamedParameter($keyId)))
           ->setMaxResults(1);
        /** @var CertKey */
        return $this->findEntity($qb);
    }

    /**
     * The single active signing key (the one new certificates are signed with).
     * Returns null when no active key exists yet.
     */
    public function findActive(): ?CertKey {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('status', $qb->createNamedParameter('active')))
           ->orderBy('created_at', 'DESC')
           ->setMaxResults(1);
        try {
            /** @var CertKey */
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * All keys still usable for verification: active + retired (status != 'revoked').
     * Retired keys must keep verifying past certificates after rotation.
     *
     * @return CertKey[]
     */
    public function findAllNonRevoked(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->neq('status', $qb->createNamedParameter('revoked')))
           ->orderBy('created_at', 'DESC');
        return $this->findEntities($qb);
    }
}
