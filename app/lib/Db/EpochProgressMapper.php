<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class EpochProgressMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_epoch_progress', EpochProgress::class);
    }

    /**
     * Find progress record for user + epoch. Returns null if not found.
     */
    public function findByUserAndEpoch(string $userId, string $epochId): ?EpochProgress {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('epoch_id', $qb->createNamedParameter($epochId)))
           ->setMaxResults(1);
        try {
            /** @var EpochProgress */
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Find all progress records for a user (all epochs).
     * @return EpochProgress[]
     */
    public function findAllByUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->orderBy('updated_at', 'DESC');
        return $this->findEntities($qb);
    }

    /**
     * Find completed epoch records for a user.
     * @return EpochProgress[]
     */
    public function findCompletedByUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('completed')))
           ->orderBy('updated_at', 'DESC');
        return $this->findEntities($qb);
    }
}
