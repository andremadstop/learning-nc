<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCA\Learning\Db\Analytics;
use OCA\Learning\Db\AnalyticsMapper;

class AnalyticsService {
    private AnalyticsMapper $mapper;

    public function __construct(AnalyticsMapper $mapper) {
        $this->mapper = $mapper;
    }

    public function record(string $userId, ?int $poolId, string $metricType, array $metricValue): Analytics {
        return $this->mapper->record($userId, $poolId, $metricType, json_encode($metricValue));
    }

    public function getUserMetrics(string $userId): array {
        return $this->mapper->findByUser($userId);
    }

    public function getPoolMetrics(string $userId, int $poolId): array {
        return $this->mapper->findByUserAndPool($userId, $poolId);
    }

    public function getByType(string $metricType, ?string $userId = null): array {
        return $this->mapper->findByType($metricType, $userId);
    }
}
