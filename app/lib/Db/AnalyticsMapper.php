<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class AnalyticsMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'learning_analytics', Analytics::class);
    }

    public function findByUser(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->orderBy('recorded_at', 'DESC');
        return $this->findEntities($qb);
    }

    public function findByUserAndPool(string $userId, int $poolId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT)))
           ->orderBy('recorded_at', 'DESC');
        return $this->findEntities($qb);
    }

    public function findByType(string $metricType, ?string $userId = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from($this->getTableName())
           ->where($qb->expr()->eq('metric_type', $qb->createNamedParameter($metricType)));
        if ($userId !== null) {
            $qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        }
        $qb->orderBy('recorded_at', 'DESC');
        return $this->findEntities($qb);
    }

    public function record(string $userId, ?int $poolId, string $metricType, string $metricValueJson): Analytics {
        $analytics = new Analytics();
        $analytics->setUserId($userId);
        $analytics->setPoolId($poolId);
        $analytics->setMetricType($metricType);
        $analytics->setMetricValue($metricValueJson);
        return $this->insert($analytics);
    }
}
