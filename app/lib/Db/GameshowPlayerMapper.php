<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class GameshowPlayerMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_gameshow_players', GameshowPlayer::class);
    }

    /**
     * All players for a session, ordered by slot.
     * @return GameshowPlayer[]
     */
    public function findBySession(int $sessionId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('session_id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT)))
           ->orderBy('slot', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Single player row for a user in a session.
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     */
    public function findBySessionAndUser(int $sessionId, string $userId): GameshowPlayer {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('session_id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        return $this->findEntity($qb);
    }

    /**
     * Count players currently in a session.
     */
    public function countBySession(int $sessionId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
           ->from($this->getTableName())
           ->where($qb->expr()->eq('session_id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT)));
        return (int) $qb->executeQuery()->fetchOne();
    }
}
