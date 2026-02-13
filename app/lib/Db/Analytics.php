<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

class Analytics extends Entity implements JsonSerializable {
    protected $userId;
    protected $poolId;
    protected $metricType;
    protected $metricValue;
    protected $recordedAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('userId', 'string');
        $this->addType('poolId', 'integer');
        $this->addType('metricType', 'string');
        $this->addType('metricValue', 'string');
        $this->addType('recordedAt', 'string');
    }

    public function jsonSerialize(): array {
        $value = $this->getMetricValue();
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if ($decoded !== null) {
                $value = $decoded;
            }
        }
        return [
            'id' => $this->getId(),
            'user_id' => $this->getUserId(),
            'pool_id' => $this->getPoolId(),
            'metric_type' => $this->getMetricType(),
            'metric_value' => $value,
            'recorded_at' => $this->getRecordedAt(),
        ];
    }
}
