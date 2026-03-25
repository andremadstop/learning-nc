<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class CoopSessionMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_coop_sessions', CoopSession::class);
    }

    /** @return CoopSession */
    public function findById(int $id): CoopSession {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        /** @var CoopSession */
        return $this->findEntity($qb);
    }

    /** @return CoopSession */
    public function findByCode(string $sessionCode): CoopSession {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('session_code', $qb->createNamedParameter($sessionCode)));

        /** @var CoopSession */
        return $this->findEntity($qb);
    }
}
