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
    protected $questionType;
    protected $reviewStatus;
    protected $reviewerId;
    protected $reviewedAt;
    protected $pbqSubtype;
    protected $pbqConfig;

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
        $this->addType('questionType', 'string');
        $this->addType('reviewStatus', 'string');
        $this->addType('reviewerId', 'string');
        $this->addType('reviewedAt', 'integer');
        $this->addType('pbqSubtype', 'string');
        $this->addType('pbqConfig', 'string');
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
            'question_type' => $this->questionType ?? 'single',
            'review_status' => $this->reviewStatus ?? 'published',
            'reviewer_id' => $this->reviewerId,
            'reviewed_at' => $this->reviewedAt,
            'pbq_subtype' => $this->pbqSubtype,
            'pbq_config' => $this->pbqConfig ? json_decode($this->pbqConfig, true) : null,
        ];
    }

    public function getPbqSubtype(): ?string {
        return $this->pbqSubtype;
    }

    public function setPbqSubtype(?string $pbqSubtype): void {
        $this->pbqSubtype = $pbqSubtype;
        $this->markFieldUpdated('pbqSubtype');
    }

    public function getPbqConfig(): ?string {
        return $this->pbqConfig;
    }

    public function setPbqConfig(?string $pbqConfig): void {
        $this->pbqConfig = $pbqConfig;
        $this->markFieldUpdated('pbqConfig');
    }
}
