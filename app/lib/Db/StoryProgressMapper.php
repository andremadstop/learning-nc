<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class StoryProgressMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_story_progress', StoryProgress::class);
    }

    /**
     * Find progress record for user + campaign. Returns null if not found.
     */
    public function findByUserAndCampaign(string $userId, string $campaignId): ?StoryProgress {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('campaign_id', $qb->createNamedParameter($campaignId)))
           ->setMaxResults(1);
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Find all progress records for a user (all campaigns).
     * @return StoryProgress[]
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
     * Find in-progress records for a user (active campaigns only).
     * @return StoryProgress[]
     */
    public function findInProgressByUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('in_progress')))
           ->orderBy('updated_at', 'DESC');
        return $this->findEntities($qb);
    }

    /**
     * Find all progress records for a campaign across all users (for coop lookup).
     * @return StoryProgress[]
     */
    public function findByCampaignAndStatus(string $campaignId, string $status): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('campaign_id', $qb->createNamedParameter($campaignId)))
           ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)))
           ->orderBy('updated_at', 'DESC');
        return $this->findEntities($qb);
    }
}
