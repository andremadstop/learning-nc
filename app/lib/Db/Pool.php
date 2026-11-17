<?php
declare(strict_types=1);
namespace OCA\Learning\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

class Pool extends Entity implements JsonSerializable {
    protected $userId;
    protected $name;
    protected $description;
    protected $createdAt;
    protected $updatedAt;
    protected $reviewStatus;
    protected $reviewerId;
    protected $reviewedAt;
    protected $handbookKey;
    protected $handbookTitle;
    protected $chapterKey;
    protected $chapterTitle;
    protected $chapterOrder;
    /**
     * Legacy column, read and ignored. Installs that started on 1.x/2.x still have
     * learning_pools.visibility (fresh installs never did). PoolMapper selects '*', and
     * Entity::fromRow() throws on any column without a property, so removing this in 5.11.0
     * broke every full pool read on those installs. Never serialized, never written.
     */
    protected $visibility;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('userId', 'string');
        $this->addType('name', 'string');
        $this->addType('description', 'string');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
        $this->addType('reviewStatus', 'string');
        $this->addType('reviewerId', 'string');
        $this->addType('reviewedAt', 'integer');
        $this->addType('handbookKey', 'string');
        $this->addType('handbookTitle', 'string');
        $this->addType('chapterKey', 'string');
        $this->addType('chapterTitle', 'string');
        $this->addType('chapterOrder', 'integer');
        $this->addType('visibility', 'string');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'user_id' => $this->getUserId(),
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'created_at' => $this->getCreatedAt(),
            'updated_at' => $this->getUpdatedAt(),
            'review_status' => $this->getReviewStatus() ?? 'published',
            'reviewer_id' => $this->getReviewerId(),
            'reviewed_at' => $this->getReviewedAt(),
            'handbook_key' => $this->getHandbookKey(),
            'handbook_title' => $this->getHandbookTitle(),
            'chapter_key' => $this->getChapterKey(),
            'chapter_title' => $this->getChapterTitle(),
            'chapter_order' => $this->getChapterOrder(),
        ];
    }
}
