<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class PoolMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_pools', Pool::class);
    }

    public function findAll(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->orderBy('created_at', 'DESC');
        return $this->findEntities($qb);
    }

    public function find(int $id, string $userId): Pool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        return $this->findEntity($qb);
    }

    public function findById(int $id): Pool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
           ->setMaxResults(1);
        return $this->findEntity($qb);
    }

    public function createOrUpdate(Pool $pool): Pool {
        $now = time();
        if ($pool->getId() === null) {
            $pool->setCreatedAt($now);
            $pool->setUpdatedAt($now);
            return $this->insert($pool);
        } else {
            $pool->setUpdatedAt($now);
            return $this->update($pool);
        }
    }

    public function deleteById(int $id, string $userId): void {
        $pool = $this->find($id, $userId);
        $this->delete($pool);
    }
}
