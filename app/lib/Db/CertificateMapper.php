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
     * Owner-scoped lookup for the student controller: the certificate with this verification_id that
     * belongs to $userId. Throws DoesNotExistException for BOTH a missing id AND a foreign-owned one,
     * so the controller can return a uniform 404 (no 403-vs-404 existence oracle / IDOR side-channel).
     *
     * @throws DoesNotExistException if no certificate with this verification_id is owned by $userId
     */
    public function findByVerificationIdAndUserId(string $verificationId, string $userId): Certificate {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('verification_id', $qb->createNamedParameter($verificationId)))
           ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
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
     * Retention sweep query (DSGVO-03, 164-07): certs eligible for crypto-erasure — issued
     * before the retention cutoff, not yet anonymized, and NOT the subject's current active
     * cert (active_idem_key IS NULL = superseded by a later period, closed, or revoked).
     * An ACTIVE cert is still serving its purpose (Art. 6 basis persists) — it ages out only
     * after a period-close/revoke frees its idem slot.
     *
     * @return Certificate[]
     */
    public function findAnonymizableBefore(int $issuedBefore): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->isNull('anonymized_at'))
           ->andWhere($qb->expr()->isNull('active_idem_key'))
           ->andWhere($qb->expr()->lt('issued_at', $qb->createNamedParameter($issuedBefore, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
        return $this->findEntities($qb);
    }

    /**
     * Reminder-window query (RECERT-06, 164-06): the ACTIVE certs (still own their idem slot,
     * not revoked, not anonymized) whose expiry falls inside (after, before] — i.e. certs that
     * are approaching expiry but not yet expired. user-facing reminders only ever target the
     * CURRENT period's cert; closed/superseded certs (active_idem_key NULL) never remind.
     *
     * @return Certificate[]
     */
    public function findActiveExpiringBetween(int $after, int $before): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('revoked', $qb->createNamedParameter(false, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL)))
           ->andWhere($qb->expr()->isNotNull('active_idem_key'))
           ->andWhere($qb->expr()->isNull('anonymized_at'))
           ->andWhere($qb->expr()->isNotNull('expires_at'))
           ->andWhere($qb->expr()->gt('expires_at', $qb->createNamedParameter($after, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->lte('expires_at', $qb->createNamedParameter($before, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
        return $this->findEntities($qb);
    }

    /**
     * Owner-scoped compliance-report query (156-01): the non-revoked certificates of one course,
     * newest first, with optional server-side filtering. TIME-FREE by design — the caller (service)
     * owns the clock and passes an ABSOLUTE unix cutoff for the expiry window, so this mapper stays
     * deterministic and unit-testable without an injected ITimeFactory.
     *
     * @param int      $courseId      the course whose certificates to list
     * @param int|null $from          issued_at >= from (inclusive), when not null
     * @param int|null $to            issued_at <= to (inclusive), when not null
     * @param int|null $expiresBefore absolute unix cutoff: expires_at IS NOT NULL AND <= cutoff
     *                                (already-expired INCLUDED; never-expiring EXCLUDED), when not null
     * @return Certificate[]
     */
    public function findByCourseId(int $courseId, ?int $from, ?int $to, ?int $expiresBefore): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->eq('revoked', $qb->createNamedParameter(false, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL)))
           // DSGVO-03 (164-07 review HIGH 2): erased tombstones never enter reports — their
           // credential_json is scrubbed (undecodable) and user_id is NULL (safeDisplayName
           // expects a string). An anonymized row has no reportable subject by definition.
           ->andWhere($qb->expr()->isNull('anonymized_at'));

        if ($from !== null) {
            $qb->andWhere($qb->expr()->gte('issued_at', $qb->createNamedParameter($from, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
        }
        if ($to !== null) {
            $qb->andWhere($qb->expr()->lte('issued_at', $qb->createNamedParameter($to, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
        }
        if ($expiresBefore !== null) {
            $qb->andWhere($qb->expr()->isNotNull('expires_at'))
               ->andWhere($qb->expr()->lte('expires_at', $qb->createNamedParameter($expiresBefore, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
        }

        $qb->orderBy('issued_at', 'DESC');
        return $this->findEntities($qb);
    }

    /**
     * Certificates for a specific course restricted to the given user IDs, with optional expiry filter.
     *
     * Empty $userIds returns [] immediately — no IN () malformed query.
     * Chunks to 999 per NC QueryBuilder PARAM_STR_ARRAY limits.
     *
     * @param list<string> $userIds
     * @param int|null     $expiresBefore absolute unix cutoff (same semantics as findByCourseId)
     * @return Certificate[]
     */
    public function findByCourseIdForUsers(int $courseId, array $userIds, ?int $expiresBefore): array {
        if ($userIds === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($userIds, 999) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from($this->getTableName())
               ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
               ->andWhere($qb->expr()->eq('revoked', $qb->createNamedParameter(false, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL)))
               ->andWhere($qb->expr()->in('user_id', $qb->createNamedParameter($chunk, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
            if ($expiresBefore !== null) {
                $qb->andWhere($qb->expr()->isNotNull('expires_at'))
                   ->andWhere($qb->expr()->lte('expires_at', $qb->createNamedParameter($expiresBefore, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
            }
            $out = array_merge($out, $this->findEntities($qb));
        }
        return $out;
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
