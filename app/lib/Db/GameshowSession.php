<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

class GameshowSession extends Entity {
    protected $code;
    protected $poolId;
    protected $courseId;
    protected $mode;
    protected $status;
    protected $maxPlayers;
    protected $minPlayers;
    protected $questionIds;
    protected $currentQuestionIndex;
    protected $numQuestions;
    protected $createdAt;
    protected $startedAt;
    protected $creatorUid;
    protected $questionStartedAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('code', 'string');
        $this->addType('poolId', 'integer');
        $this->addType('courseId', 'integer');
        $this->addType('mode', 'string');
        $this->addType('status', 'string');
        $this->addType('maxPlayers', 'integer');
        $this->addType('minPlayers', 'integer');
        $this->addType('questionIds', 'string');
        $this->addType('currentQuestionIndex', 'integer');
        $this->addType('numQuestions', 'integer');
        $this->addType('createdAt', 'integer');
        $this->addType('startedAt', 'integer');
        $this->addType('creatorUid', 'string');
        $this->addType('questionStartedAt', 'integer');
    }
}
