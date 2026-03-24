<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

class CampaignStateMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_campaign_state', CampaignState::class);
    }

    /**
     * Find state record for user + campaign. Returns null if not found.
     */
    public function findByUserAndCampaign(string $userId, string $campaignId): ?CampaignState {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('campaign_id', $qb->createNamedParameter($campaignId)))
           ->setMaxResults(1);
        try {
            /** @var CampaignState */
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Find all state records for a user (all campaigns).
     * @return CampaignState[]
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
     * Find in-progress state records for a user.
     * @return CampaignState[]
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
}
