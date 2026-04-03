<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class KudosMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_kudos', Kudos::class);
    }

    public function countGivenSince(int $courseId, string $fromUser, int $since): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('from_user', $qb->createNamedParameter($fromUser)))
            ->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();

        return $count;
    }

    /**
     * @param string[] $userIds
     * @return array<string, int>
     */
    public function countReceivedByCourseAndUsers(int $courseId, array $userIds): array {
        if ($userIds === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('to_user', $qb->createFunction('COUNT(*) AS cnt'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->in('to_user', $qb->createNamedParameter($userIds, IQueryBuilder::PARAM_STR_ARRAY)))
            ->groupBy('to_user');

        $result = $qb->executeQuery();
        $counts = [];
        while ($row = $result->fetch()) {
            $counts[(string)$row['to_user']] = (int)$row['cnt'];
        }
        $result->closeCursor();

        return $counts;
    }
}
