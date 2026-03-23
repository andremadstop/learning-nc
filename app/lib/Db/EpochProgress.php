<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

class EpochProgress extends Entity {
    protected $userId;
    protected $epochId;
    protected $characterClass;
    protected $currentScene;
    protected $score;
    protected $totalQuestions;
    protected $correctAnswers;
    protected $status;
    /** @var string|null JSON array of viewed museum fact IDs */
    protected $museumViewed;
    protected $createdAt;
    protected $updatedAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('userId', 'string');
        $this->addType('epochId', 'string');
        $this->addType('characterClass', 'string');
        $this->addType('currentScene', 'string');
        $this->addType('score', 'integer');
        $this->addType('totalQuestions', 'integer');
        $this->addType('correctAnswers', 'integer');
        $this->addType('status', 'string');
        $this->addType('museumViewed', 'string');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
    }

    /**
     * Decode museum_viewed JSON into an array of fact IDs.
     * @return string[]
     */
    public function getMuseumViewedDecoded(): array {
        if ($this->museumViewed === null || $this->museumViewed === '') {
            return [];
        }
        return json_decode($this->museumViewed, true) ?? [];
    }
}
