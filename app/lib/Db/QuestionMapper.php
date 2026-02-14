<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class QuestionMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_questions', Question::class);
    }

    public function findByPool(int $poolId, string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->orderBy('created_at', 'DESC');
        return $this->findEntities($qb);
    }

    public function findByPoolId(int $poolId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT)))
           ->orderBy('created_at', 'DESC');
        return $this->findEntities($qb);
    }

    // FIX #10: Paginated query for large pools
    public function findByPoolIdPaged(int $poolId, int $limit = 50, int $offset = 0): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT)))
           ->orderBy('created_at', 'DESC')
           ->setMaxResults($limit)
           ->setFirstResult($offset);
        return $this->findEntities($qb);
    }

    public function countByPoolId(int $poolId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*) as cnt'))
           ->from($this->getTableName())
           ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT)));
        $result = $qb->execute();
        $row = $result->fetch();
        $result->closeCursor();
        return (int)($row['cnt'] ?? 0);
    }

    public function find(int $id, string $userId): Question {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        return $this->findEntity($qb);
    }

    public function findById(int $id): Question {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    public function createOrUpdate(Question $question): Question {
        $now = time();
        if ($question->getId()) {
            $question->setUpdatedAt($now);
            return $this->update($question);
        } else {
            $question->setCreatedAt($now);
            $question->setUpdatedAt($now);
            return $this->insert($question);
        }
    }

    public function deleteById(int $id, string $userId): void {
        try {
            $question = $this->find($id, $userId);
            $this->delete($question);
        } catch (DoesNotExistException $e) {
            // Already deleted or doesn't exist
        }
    }

    public function searchByText(string $query, string $userId, int $limit = 50): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select("q.*", "p.name as pool_name")
           ->from($this->getTableName(), "q")
           ->innerJoin("q", "learning_pools", "p", $qb->expr()->eq("q.pool_id", "p.id"))
           ->where($qb->expr()->eq("q.user_id", $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->iLike("q.text", $qb->createNamedParameter("%" . $this->db->escapeLikeParameter($query) . "%")))
           ->orderBy("q.created_at", "DESC")
           ->setMaxResults($limit);
        return $qb->executeQuery()->fetchAll();
    }
}
