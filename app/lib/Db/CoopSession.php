<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

class CoopSession extends Entity {
    protected $campaignId;
    protected $hostUserId;
    protected $sessionCode;
    protected $status;
    protected $currentNodeId;
    protected $stateBag;
    protected $createdAt;
    protected $lastHeartbeat;
    protected $maxPlayers;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('campaignId', 'string');
        $this->addType('hostUserId', 'string');
        $this->addType('sessionCode', 'string');
        $this->addType('status', 'string');
        $this->addType('currentNodeId', 'string');
        $this->addType('stateBag', 'string');
        $this->addType('createdAt', 'integer');
        $this->addType('lastHeartbeat', 'integer');
        $this->addType('maxPlayers', 'integer');
    }

    /**
     * @return array<string, mixed>
     */
    public function getStateBagDecoded(): array {
        if ($this->stateBag === null || $this->stateBag === '' || $this->stateBag === '{}') {
            return [];
        }

        $decoded = json_decode($this->stateBag, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $stateBag
     */
    public function setStateBagFromArray(array $stateBag): void {
        $this->setStateBag(json_encode($stateBag, JSON_THROW_ON_ERROR));
    }
}
