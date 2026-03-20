<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

class GameshowAnswer extends Entity {
    protected $sessionId;
    protected $questionIndex;
    protected $playerUid;
    protected $answerId;
    protected $isCorrect;
    protected $answeredAt;
    protected $pointsEarned;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('sessionId', 'integer');
        $this->addType('questionIndex', 'integer');
        $this->addType('playerUid', 'string');
        $this->addType('answerId', 'integer');
        $this->addType('isCorrect', 'boolean');
        $this->addType('answeredAt', 'integer');
        $this->addType('pointsEarned', 'integer');
    }
}
