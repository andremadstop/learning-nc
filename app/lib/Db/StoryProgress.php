<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

class StoryProgress extends Entity {
    protected $userId;
    protected $campaignId;
    protected $currentSceneId;
    protected $characterClass;
    /** @var string|null JSON array of choice records */
    protected $choicesJson;
    protected $score;
    protected $status;
    /** @var string|null JSON array of additional coop user IDs */
    protected $coopUserIds;
    protected $createdAt;
    protected $updatedAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('userId', 'string');
        $this->addType('campaignId', 'string');
        $this->addType('currentSceneId', 'string');
        $this->addType('characterClass', 'string');
        $this->addType('choicesJson', 'string');
        $this->addType('score', 'integer');
        $this->addType('status', 'string');
        $this->addType('coopUserIds', 'string');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
    }

    /**
     * Decode choices_json into an array.
     * @return array<array{scene_id: string, choice_id: string, skill_result: string|null, correct_count: int, total_count: int}>
     */
    public function getChoicesDecoded(): array {
        if ($this->choicesJson === null || $this->choicesJson === '') {
            return [];
        }
        return json_decode($this->choicesJson, true) ?? [];
    }

    /**
     * Decode coop_user_ids JSON into an array of user ID strings.
     * @return string[]
     */
    public function getCoopUserIdsDecoded(): array {
        if ($this->coopUserIds === null || $this->coopUserIds === '') {
            return [];
        }
        return json_decode($this->coopUserIds, true) ?? [];
    }
}
