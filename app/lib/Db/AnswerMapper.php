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
        $qb->execute();
    }

    public function createOrUpdate(Answer $answer): Answer {
        if ($answer->getId()) {
            return $this->update($answer);
        } else {
            return $this->insert($answer);
        }
    }
}
