<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class GameshowAnswerMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_gameshow_answers', GameshowAnswer::class);
    }

    /**
     * All answers for a specific question in a session.
     * @return GameshowAnswer[]
     */
    public function findBySessionAndQuestion(int $sessionId, int $questionIndex): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('session_id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->eq('question_index', $qb->createNamedParameter($questionIndex, IQueryBuilder::PARAM_INT)));
        return $this->findEntities($qb);
    }

    /**
     * All answers for a session, ordered by question then time.
     * @return GameshowAnswer[]
     */
    public function findBySession(int $sessionId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('session_id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT)))
           ->orderBy('question_index', 'ASC')
           ->addOrderBy('answered_at', 'ASC');
        return $this->findEntities($qb);
    }
}
