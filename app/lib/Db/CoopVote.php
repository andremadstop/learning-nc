<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

class CoopVote extends Entity {
    protected $sessionId;
    protected $userId;
    protected $nodeId;
    protected $edgeId;
    protected $votedAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('sessionId', 'integer');
        $this->addType('userId', 'string');
        $this->addType('nodeId', 'string');
        $this->addType('edgeId', 'string');
        $this->addType('votedAt', 'integer');
    }
}
