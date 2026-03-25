<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class CoopPlayerMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_coop_players', CoopPlayer::class);
    }

    /**
     * @return CoopPlayer[]
     */
    public function findBySession(int $sessionId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('session_id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT)))
            ->orderBy('is_host', 'DESC')
            ->addOrderBy('joined_at', 'ASC');

        return $this->findEntities($qb);
    }

    /** @return CoopPlayer */
    public function findBySessionAndUser(int $sessionId, string $userId): CoopPlayer {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('session_id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        /** @var CoopPlayer */
        return $this->findEntity($qb);
    }
}
