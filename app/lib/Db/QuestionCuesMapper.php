<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<QuestionCues> */
class QuestionCuesMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_qmedia_cues', QuestionCues::class);
    }

    public function findByQuestion(int $questionId): ?QuestionCues {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId, IQueryBuilder::PARAM_INT)));
        try {
            /** @var QuestionCues $cues */
            $cues = $this->findEntity($qb);
            return $cues;
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    public function deleteByQuestion(int $questionId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
           ->where($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    public function deleteByPool(int $poolId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
           ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
