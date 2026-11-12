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
        return array_values(array_filter(
            $this->decodeChoicesColumn(),
            static fn ($entry) => !(is_array($entry) && array_key_exists(self::PENDING_SKILL_KEY, $entry))
        ));
    }

    /**
     * Key of the one marker entry in choices_json that holds the skill-check question IDs the
     * current scene offered: {"pending_skill": {"scene_id": "...", "choices": {"<choice>": [ids]}}}.
     * Only those questions are accepted on submit (audit 2026-11). getChoicesDecoded() never
     * returns the marker, so writing the history back (advanceProgress) drops it.
     */
    public const PENDING_SKILL_KEY = 'pending_skill';

    /**
     * @return array{scene_id: string, choices: array<string, list<int>>}|null
     */
    public function getPendingSkill(): ?array {
        foreach ($this->decodeChoicesColumn() as $entry) {
            if (is_array($entry) && isset($entry[self::PENDING_SKILL_KEY]) && is_array($entry[self::PENDING_SKILL_KEY])) {
                return $entry[self::PENDING_SKILL_KEY];
            }
        }
        return null;
    }

    /**
     * Replace the remembered skill-check questions (null removes them). The history is kept.
     *
     * @param array{scene_id: string, choices: array<string, list<int>>}|null $pending
     */
    public function setPendingSkill(?array $pending): void {
        $entries = $this->getChoicesDecoded();
        if ($pending !== null) {
            $entries[] = [self::PENDING_SKILL_KEY => $pending];
        }
        $this->setChoicesJson(json_encode($entries));
    }

    /** @return array<mixed> */
    private function decodeChoicesColumn(): array {
        if ($this->choicesJson === null || $this->choicesJson === '') {
            return [];
        }
        $decoded = json_decode($this->choicesJson, true);
        return is_array($decoded) ? $decoded : [];
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
