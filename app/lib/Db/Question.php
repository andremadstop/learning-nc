<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

class Question extends Entity implements JsonSerializable {
    protected $poolId;
    protected $userId;
    protected $text;
    protected $explanation;
    protected $difficulty;
    protected $createdAt;
    protected $updatedAt;
    protected $imagePath;
    protected $lang;

    public function __construct() {
        $this->addType('poolId', 'integer');
        $this->addType('userId', 'string');
        $this->addType('text', 'string');
        $this->addType('explanation', 'string');
        $this->addType('difficulty', 'string');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
        $this->addType('imagePath', 'string');
        $this->addType('lang', 'string');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'pool_id' => $this->poolId,
            'user_id' => $this->userId,
            'text' => $this->text,
            'explanation' => $this->explanation,
            'difficulty' => $this->difficulty,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'image_path' => $this->imagePath,
            'lang' => $this->lang ?? 'de',
        ];
    }
}
