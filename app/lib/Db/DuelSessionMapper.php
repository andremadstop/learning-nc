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

    public function findById(int $id): DuelSession {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->findEntity($qb);
    }

    public function findByCode(string $code): DuelSession {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('code', $qb->createNamedParameter($code)));
        return $this->findEntity($qb);
    }
}
