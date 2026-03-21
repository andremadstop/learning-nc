<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class AnswerMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_answers', Answer::class);
    }

    public function findByQuestion(int $questionId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId, IQueryBuilder::PARAM_INT)))
           ->orderBy('position', 'ASC');
        return $this->findEntities($qb);
    }

    public function deleteByQuestion(int $questionId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
           ->where($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * @param int[] $questionIds
     * @return array<int, Answer[]> Grouped by question_id
     */
    public function findByQuestions(array $questionIds): array {
        if (empty($questionIds)) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->in('question_id', $qb->createNamedParameter($questionIds, IQueryBuilder::PARAM_INT_ARRAY)))
           ->orderBy('question_id', 'ASC')
           ->addOrderBy('position', 'ASC');
        $answers = $this->findEntities($qb);

        $grouped = [];
        foreach ($answers as $answer) {
            $grouped[$answer->getQuestionId()][] = $answer;
        }
        return $grouped;
    }

    public function createOrUpdate(Answer $answer): Answer {
        if ($answer->getId()) {
            return $this->update($answer);
        } else {
            return $this->insert($answer);
        }
    }
}
