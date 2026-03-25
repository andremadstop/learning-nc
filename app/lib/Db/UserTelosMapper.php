<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
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
