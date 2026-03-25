<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class CoopVoteMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_coop_votes', CoopVote::class);
    }

    /**
     * @return CoopVote[]
     */
    public function findBySessionAndNode(int $sessionId, string $nodeId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('session_id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('node_id', $qb->createNamedParameter($nodeId)))
            ->orderBy('voted_at', 'ASC');

        return $this->findEntities($qb);
    }

    /** @return CoopVote */
    public function findBySessionNodeAndUser(int $sessionId, string $nodeId, string $userId): CoopVote {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('session_id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('node_id', $qb->createNamedParameter($nodeId)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        /** @var CoopVote */
        return $this->findEntity($qb);
    }

    public function deleteBySessionAndNode(int $sessionId, string $nodeId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('session_id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('node_id', $qb->createNamedParameter($nodeId)));

        return $qb->executeStatement();
    }
}
