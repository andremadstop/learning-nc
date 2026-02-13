<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class PoolShareMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_pool_shares', PoolShare::class);
    }

    public function findByPool(int $poolId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT)));
        return $this->findEntities($qb);
    }

    public function findSharedWithUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('shared_with', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter('user')));
        return $this->findEntities($qb);
    }

    public function findByPoolAndUser(int $poolId, string $userId): ?PoolShare {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->eq('shared_with', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('share_type', $qb->createNamedParameter('user')));
        try {
            return $this->findEntity($qb);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return null;
        }
    }

    public function deleteByPoolAndUser(int $poolId, string $sharedWith): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
           ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->eq('shared_with', $qb->createNamedParameter($sharedWith)));
        $qb->executeStatement();
    }
}
