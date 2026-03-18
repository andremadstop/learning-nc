<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class DuelSessionMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_duel_sessions', DuelSession::class);
    }

    public function findByCode(string $code): DuelSession {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('code', $qb->createNamedParameter($code)));
        return $this->findEntity($qb);
    }

    /**
     * Find sessions that are waiting/ready/active but have stale poll timestamps.
     * A session is considered abandoned if either player hasn't polled since $cutoffTimestamp.
     */
    public function findExpiredActive(int $cutoffTimestamp): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->in(
               'status',
               $qb->createNamedParameter(['waiting', 'ready', 'active'], IQueryBuilder::PARAM_STR_ARRAY)
           ))
           ->andWhere(
               $qb->expr()->orX(
                   $qb->expr()->andX(
                       $qb->expr()->isNotNull('creator_last_poll'),
                       $qb->expr()->lt('creator_last_poll', $qb->createNamedParameter($cutoffTimestamp, IQueryBuilder::PARAM_INT))
                   ),
                   $qb->expr()->andX(
                       $qb->expr()->isNotNull('opponent_last_poll'),
                       $qb->expr()->lt('opponent_last_poll', $qb->createNamedParameter($cutoffTimestamp, IQueryBuilder::PARAM_INT))
                   )
               )
           );
        return $this->findEntities($qb);
    }
}
