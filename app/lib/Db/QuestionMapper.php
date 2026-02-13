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
}
