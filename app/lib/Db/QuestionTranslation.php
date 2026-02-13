<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

class QuestionTranslation extends Entity implements JsonSerializable {
    protected $questionId;
    protected $lang;
    protected $text;
    protected $explanation;
    protected $createdAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('questionId', 'integer');
        $this->addType('lang', 'string');
        $this->addType('text', 'string');
        $this->addType('explanation', 'string');
        $this->addType('createdAt', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'question_id' => $this->getQuestionId(),
            'lang' => $this->getLang(),
            'text' => $this->getText(),
            'explanation' => $this->getExplanation(),
            'created_at' => $this->getCreatedAt(),
        ];
    }
}
