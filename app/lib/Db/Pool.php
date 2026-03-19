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
    protected $visibility;
    protected $reviewStatus;
    protected $reviewerId;
    protected $reviewedAt;
    protected $handbookKey;
    protected $handbookTitle;
    protected $chapterKey;
    protected $chapterTitle;
    protected $chapterOrder;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('userId', 'string');
        $this->addType('name', 'string');
        $this->addType('description', 'string');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
        $this->addType('visibility', 'string');
        $this->addType('reviewStatus', 'string');
        $this->addType('reviewerId', 'string');
        $this->addType('reviewedAt', 'integer');
        $this->addType('handbookKey', 'string');
        $this->addType('handbookTitle', 'string');
        $this->addType('chapterKey', 'string');
        $this->addType('chapterTitle', 'string');
        $this->addType('chapterOrder', 'integer');
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
