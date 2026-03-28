<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getCourseId()
 * @method void setCourseId(int $courseId)
 * @method string getType()
 * @method void setType(string $type)
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getBody()
 * @method void setBody(?string $body)
 * @method string|null getActorId()
 * @method void setActorId(?string $actorId)
 * @method string|null getMeta()
 * @method void setMeta(?string $meta)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class FeedItem extends Entity {
    protected $courseId;
    protected $type;
    protected $title;
    protected $body;
    protected $actorId;
    protected $meta;
    protected $createdAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('courseId', 'integer');
        $this->addType('createdAt', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'course_id' => $this->courseId,
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'actor_id' => $this->actorId,
            'meta' => $this->meta !== null ? json_decode($this->meta, true) : null,
            'created_at' => $this->createdAt,
        ];
    }
}
