<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

class CampaignState extends Entity {
    protected $userId;
    protected $campaignId;
    protected $graphPosition;
    protected $stateBag;
    protected $actNumber;
    protected $status;
    protected $characterClass;
    /** @var string|null JSON array of choice records */
    protected $choicesJson;
    protected $score;
    protected $createdAt;
    protected $updatedAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('userId', 'string');
        $this->addType('campaignId', 'string');
        $this->addType('graphPosition', 'string');
        $this->addType('stateBag', 'string');
        $this->addType('actNumber', 'integer');
        $this->addType('status', 'string');
        $this->addType('characterClass', 'string');
        $this->addType('choicesJson', 'string');
        $this->addType('score', 'integer');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
    }

    /**
     * Decode state_bag JSON into an associative array.
     *
     * @return array{flags: array<string, bool>, items: list<string>, reputation: array<string, int|float>}
     */
    public function getStateBagDecoded(): array {
        if ($this->stateBag === null || $this->stateBag === '' || $this->stateBag === '{}') {
            return ['flags' => [], 'items' => [], 'reputation' => []];
        }
        $decoded = json_decode($this->stateBag, true);
        if (!is_array($decoded)) {
            return ['flags' => [], 'items' => [], 'reputation' => []];
        }
        return [
            'flags' => $decoded['flags'] ?? [],
            'items' => $decoded['items'] ?? [],
            'reputation' => $decoded['reputation'] ?? [],
        ];
    }

    /**
     * Encode an array into state_bag JSON.
     *
     * @param array<string, mixed> $bag
     */
    public function setStateBagFromArray(array $bag): void {
        $this->setStateBag(json_encode($bag, JSON_THROW_ON_ERROR));
    }

    /**
     * Decode choices_json into an array.
     *
     * @return list<array<string, mixed>>
     */
    public function getChoicesDecoded(): array {
        if ($this->choicesJson === null || $this->choicesJson === '') {
            return [];
        }
        return json_decode($this->choicesJson, true) ?? [];
    }
}
