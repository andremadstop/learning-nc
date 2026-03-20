<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

class LeagueSeasonMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_league_seasons', LeagueSeason::class);
    }

    public function findById(int $id): LeagueSeason {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
        return $this->findEntity($qb);
    }

    public function findByCourse(int $courseId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId)))
            ->orderBy('created_at', 'DESC');
        return $this->findEntities($qb);
    }
}
