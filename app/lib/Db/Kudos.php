<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

class Kudos extends Entity {
    protected $courseId;
    protected $fromUser;
    protected $toUser;
    protected $message;
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
            'from_user' => $this->fromUser,
            'to_user' => $this->toUser,
            'message' => $this->message,
            'created_at' => $this->createdAt,
        ];
    }
}
