<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

class Answer extends Entity implements JsonSerializable {
    protected $questionId;
    protected $text;
    protected $isCorrect;
    protected $position;

    public function __construct() {
        $this->addType('questionId', 'integer');
        $this->addType('text', 'string');
        $this->addType('isCorrect', 'boolean');
        $this->addType('position', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'question_id' => $this->questionId,
            'text' => $this->text,
            'is_correct' => $this->isCorrect,
            'position' => $this->position,
        ];
    }
}
