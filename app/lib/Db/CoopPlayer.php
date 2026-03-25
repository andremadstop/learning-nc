<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

class CoopPlayer extends Entity {
    protected $sessionId;
    protected $userId;
    protected $displayName;
    protected $characterId;
    protected $isReady;
    protected $isHost;
    protected $joinedAt;
    protected $lastHeartbeat;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('sessionId', 'integer');
        $this->addType('userId', 'string');
        $this->addType('displayName', 'string');
        $this->addType('characterId', 'string');
        $this->addType('isReady', 'integer');
        $this->addType('isHost', 'integer');
        $this->addType('joinedAt', 'integer');
        $this->addType('lastHeartbeat', 'integer');
    }
}
