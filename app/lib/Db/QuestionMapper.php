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

    private function applyBookQuestionOrder(IQueryBuilder $qb): void {
        // Keep handbook-tagged questions in chapter order and push untagged items to the end.
        $qb->orderBy($qb->createFunction('COALESCE(chapter_order, 999999)'), 'ASC')
           ->addOrderBy('created_at', 'ASC')
           ->addOrderBy('id', 'ASC');
    }

    public function findByPool(int $poolId, string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $this->applyBookQuestionOrder($qb);
        return $this->findEntities($qb);
    }

    public function findByPoolId(int $poolId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT)));
        $this->applyBookQuestionOrder($qb);
        return $this->findEntities($qb);
    }

    public function findByPoolIdPaged(int $poolId, int $limit = 50, int $offset = 0): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT)))
           ->setMaxResults($limit)
           ->setFirstResult($offset);
        $this->applyBookQuestionOrder($qb);
        return $this->findEntities($qb);
    }

    public function findByIds(array $questionIds): array {
        $questionIds = array_values(array_unique(array_filter(array_map('intval', $questionIds), static fn(int $id): bool => $id > 0)));
        if ($questionIds === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->in('id', $qb->createNamedParameter($questionIds, IQueryBuilder::PARAM_INT_ARRAY)));
        $entities = $this->findEntities($qb);

        $byId = [];
        foreach ($entities as $entity) {
            $byId[$entity->getId()] = $entity;
        }

        $ordered = [];
        foreach ($questionIds as $questionId) {
            if (isset($byId[$questionId])) {
                $ordered[] = $byId[$questionId];
            }
        }

        return $ordered;
    }

    public function countByPoolId(int $poolId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*) as cnt'))
           ->from($this->getTableName())
           ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
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
        $expr = $qb->expr();
        $qb->selectDistinct("q.*", "p.name as pool_name")
           ->from($this->getTableName(), "q")
           ->innerJoin("q", "learning_pools", "p", $qb->expr()->eq("q.pool_id", "p.id"))
           ->leftJoin("p", "learning_pool_shares", "s", $expr->andX(
               $expr->eq("s.pool_id", "p.id"),
               $expr->eq("s.shared_with", $qb->createNamedParameter($userId)),
               $expr->eq("s.share_type", $qb->createNamedParameter("user"))
           ))
           ->leftJoin("p", "learning_course_pools", "cp", $expr->eq("cp.pool_id", "p.id"))
           ->leftJoin("cp", "learning_courses", "c", $expr->eq("cp.course_id", "c.id"))
           ->leftJoin("c", "learning_course_members", "cm", $expr->andX(
               $expr->eq("cm.course_id", "c.id"),
               $expr->eq("cm.user_id", $qb->createNamedParameter($userId))
           ))
           ->where($expr->orX(
               $expr->eq("q.user_id", $qb->createNamedParameter($userId)),
               $expr->isNotNull("s.id"),
               $expr->eq("c.instructor_id", $qb->createNamedParameter($userId)),
               $expr->isNotNull("cm.id")
           ))
           ->andWhere($qb->expr()->iLike("q.text", $qb->createNamedParameter("%" . $this->db->escapeLikeParameter($query) . "%")))
           ->orderBy("q.created_at", "DESC")
           ->setMaxResults($limit);
        return $qb->executeQuery()->fetchAll();
    }
}
