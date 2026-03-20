<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

class GameshowPlayer extends Entity {
    protected $sessionId;
    protected $userId;
    protected $slot;
    protected $displayName;
    protected $score;
    protected $lives;
    protected $isReady;
    protected $isRemoved;
    protected $lastPoll;
    protected $joinedAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('sessionId', 'integer');
        $this->addType('userId', 'string');
        $this->addType('slot', 'integer');
        $this->addType('displayName', 'string');
        $this->addType('score', 'integer');
        $this->addType('lives', 'integer');
        $this->addType('isReady', 'boolean');
        $this->addType('isRemoved', 'boolean');
        $this->addType('lastPoll', 'integer');
        $this->addType('joinedAt', 'integer');
    }
}
