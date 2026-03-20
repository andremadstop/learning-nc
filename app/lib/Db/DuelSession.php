<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

class DuelSession extends Entity {
    protected $code;
    protected $creatorUid;
    protected $opponentUid;
    protected $poolId;
    protected $courseId;
    protected $questionIds;
    protected $status;
    protected $currentQuestionIndex;
    protected $creatorScore;
    protected $opponentScore;
    protected $creatorReady;
    protected $opponentReady;
    protected $creatorLastPoll;
    protected $opponentLastPoll;
    protected $createdAt;
    protected $leagueSeasonId;
    protected $leagueChallengeId;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('code', 'string');
        $this->addType('creatorUid', 'string');
        $this->addType('opponentUid', 'string');
        $this->addType('poolId', 'integer');
        $this->addType('courseId', 'integer');
        $this->addType('questionIds', 'string');
        $this->addType('status', 'string');
        $this->addType('currentQuestionIndex', 'integer');
        $this->addType('creatorScore', 'integer');
        $this->addType('opponentScore', 'integer');
        $this->addType('creatorReady', 'boolean');
        $this->addType('opponentReady', 'boolean');
        $this->addType('creatorLastPoll', 'integer');
        $this->addType('opponentLastPoll', 'integer');
        $this->addType('createdAt', 'integer');
        $this->addType('leagueSeasonId', 'integer');
        $this->addType('leagueChallengeId', 'integer');
    }
}
