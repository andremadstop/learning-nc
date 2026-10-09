<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<UserTelos>
 */
class UserTelosMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_user_telos', UserTelos::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function findByUserId(string $userId): UserTelos {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        return $this->findEntity($qb);
    }

    /**
     * Find or return null (no exception).
     */
    public function findByUserIdOrNull(string $userId): ?UserTelos {
        try {
            return $this->findByUserId($userId);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * Revoke the AI consent in one unconditional statement: clear every consent field and raise
     * the epoch together. Not read-modify-write through the entity, whose setters mark only
     * changed fields dirty: a revocation that read a row without consent would otherwise leave a
     * grant committed in between untouched. Returns the number of rows (0: user has no row).
     */
    public function revokeAiConsent(string $userId, int $now): int {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
           ->set('ai_consent_version', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
           ->set('ai_consent_fingerprint', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
           ->set('ai_consent_source', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
           ->set('ai_consent_at', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
           ->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
           ->set('consent_epoch', $qb->createFunction($qb->getColumnName('consent_epoch') . ' + 1'))
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        return $qb->executeStatement();
    }

    /**
     * Raise the consent epoch in one statement, so concurrent revocations never lose an increment.
     */
    public function incrementConsentEpoch(string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
           ->set('consent_epoch', $qb->createFunction($qb->getColumnName('consent_epoch') . ' + 1'))
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->executeStatement();
    }

    /**
     * Find by ICS token or return null.
     */
    public function findByIcsTokenOrNull(string $token): ?UserTelos {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
               ->from($this->getTableName())
               ->where($qb->expr()->eq('ics_token', $qb->createNamedParameter($token)))
               ->setMaxResults(1);

            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * Find all telos for users in a course (for dozent aggregation).
     *
     * @param string[] $userIds
     * @return UserTelos[]
     */
    public function findByUserIds(array $userIds): array {
        if (empty($userIds)) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->in('user_id', $qb->createNamedParameter(
               $userIds,
               \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY
           )));
        return $this->findEntities($qb);
    }

    /**
     * Find all telos with course-visible or public visibility (for peer discovery).
     *
     * @return UserTelos[]
     */
    public function findVisible(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->in('visibility', $qb->createNamedParameter(
               ['course', 'public'],
               \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY
           )))
           ->andWhere($qb->expr()->eq('onboarding_completed', $qb->createNamedParameter(true, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL)));
        return $this->findEntities($qb);
    }
}
