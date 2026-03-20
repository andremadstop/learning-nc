<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

class LeagueChallengeMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_league_challenges', LeagueChallenge::class);
    }

    public function findById(int $id): LeagueChallenge {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
        return $this->findEntity($qb);
    }

    public function findBySeason(int $seasonId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('season_id', $qb->createNamedParameter($seasonId)))
            ->orderBy('created_at', 'ASC');
        return $this->findEntities($qb);
    }
}
