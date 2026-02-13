<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

class PoolShare extends Entity implements JsonSerializable {
    protected $poolId;
    protected $sharedWith;
    protected $shareType;
    protected $permission;
    protected $createdAt;
    protected $createdBy;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('poolId', 'integer');
        $this->addType('sharedWith', 'string');
        $this->addType('shareType', 'string');
        $this->addType('permission', 'string');
        $this->addType('createdAt', 'string');
        $this->addType('createdBy', 'string');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'pool_id' => $this->getPoolId(),
            'shared_with' => $this->getSharedWith(),
            'share_type' => $this->getShareType(),
            'permission' => $this->getPermission(),
            'created_at' => $this->getCreatedAt(),
            'created_by' => $this->getCreatedBy(),
        ];
    }
}
