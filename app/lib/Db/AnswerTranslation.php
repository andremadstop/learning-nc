<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

class AnswerTranslation extends Entity implements JsonSerializable {
    protected $answerId;
    protected $lang;
    protected $text;
    protected $createdAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('answerId', 'integer');
        $this->addType('lang', 'string');
        $this->addType('text', 'string');
        $this->addType('createdAt', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'answer_id' => $this->getAnswerId(),
            'lang' => $this->getLang(),
            'text' => $this->getText(),
            'created_at' => $this->getCreatedAt(),
        ];
    }
}
