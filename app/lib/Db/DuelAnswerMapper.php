<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class DuelAnswerMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_duel_answers', DuelAnswer::class);
    }

    /**
     * Returns both answers for a specific question in a duel (0, 1, or 2 rows).
     */
    public function findByDuelAndQuestion(int $duelId, int $questionIndex): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('duel_id', $qb->createNamedParameter($duelId, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->eq('question_index', $qb->createNamedParameter($questionIndex, IQueryBuilder::PARAM_INT)));
        return $this->findEntities($qb);
    }

    /**
     * Returns all answers for a duel, ordered by question_index.
     */
    public function findByDuel(int $duelId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('duel_id', $qb->createNamedParameter($duelId, IQueryBuilder::PARAM_INT)))
           ->orderBy('question_index', 'ASC')
           ->addOrderBy('answered_at', 'ASC');
        return $this->findEntities($qb);
    }
}
