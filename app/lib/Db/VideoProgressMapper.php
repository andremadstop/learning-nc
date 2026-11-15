<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<VideoProgress>
 */
class VideoProgressMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_video_progress', VideoProgress::class);
    }

    /**
     * Return the single (user, content) progress row, or null if none exists yet.
     */
    public function findByUserAndContent(string $userId, int $contentId): ?VideoProgress {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('content_id', $qb->createNamedParameter($contentId)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * GitHub #35 (B4): how many of $contentIds each user has completed (completed_at set), for the
     * coverage report. One query per 999 users instead of one per (user, content).
     *
     * @param list<string> $userIds
     * @param list<int>    $contentIds
     * @return array<string, int> user id => number of completed contents (users without any absent)
     */
    public function countCompletedByUsers(array $userIds, array $contentIds): array {
        if ($userIds === [] || $contentIds === []) {
            return [];
        }
        $counts = [];
        foreach (array_chunk($userIds, 999) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('user_id', 'content_id')
                ->from($this->getTableName())
                ->where($qb->expr()->in('user_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)))
                ->andWhere($qb->expr()->in('content_id', $qb->createNamedParameter(array_values($contentIds), IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($qb->expr()->isNotNull('completed_at'));
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $uid = (string)$row['user_id'];
                $counts[$uid] = ($counts[$uid] ?? 0) + 1;
            }
            $result->closeCursor();
        }
        return $counts;
    }
}
