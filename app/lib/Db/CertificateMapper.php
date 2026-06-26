<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Certificate>
 */
class CertificateMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_certificates', Certificate::class);
    }

    /**
     * Resolve a certificate by its public verification_id (the verify-route lookup key).
     *
     * @throws DoesNotExistException if no certificate matches
     */
    public function findByVerificationId(string $verificationId): Certificate {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('verification_id', $qb->createNamedParameter($verificationId)))
           ->setMaxResults(1);
        /** @var Certificate */
        return $this->findEntity($qb);
    }

    /**
     * Idempotency guard for issuance (used by 155-04): the existing certificate for a
     * user+course, or null if none has been issued yet.
     */
    public function findByUserAndCourse(string $userId, int $courseId): ?Certificate {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
           ->orderBy('issued_at', 'DESC')
           ->setMaxResults(1);
        try {
            /** @var Certificate */
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * All certificates issued to a user, newest first.
     *
     * @return Certificate[]
     */
    public function findByUserId(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->orderBy('issued_at', 'DESC');
        return $this->findEntities($qb);
    }
}
