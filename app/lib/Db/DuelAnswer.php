<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

class DuelAnswer extends Entity {
    protected $duelId;
    protected $questionIndex;
    protected $playerUid;
    protected $answerCorrect;
    protected $answeredAt;
    protected $pointsEarned;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('duelId', 'integer');
        $this->addType('questionIndex', 'integer');
        $this->addType('playerUid', 'string');
        $this->addType('answerCorrect', 'boolean');
        $this->addType('answeredAt', 'integer');
        $this->addType('pointsEarned', 'integer');
    }
}
