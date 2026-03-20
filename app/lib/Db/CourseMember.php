<?php
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getCourseId()
 * @method void setCourseId(int $courseId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getRole()
 * @method void setRole(string $role)
 * @method int getEnrolledAt()
 * @method void setEnrolledAt(int $enrolledAt)
 */
class CourseMember extends Entity {
    protected $courseId;
    protected $userId;
    protected $role;
    protected $enrolledAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('courseId', 'integer');
        $this->addType('enrolledAt', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'course_id' => $this->courseId,
            'user_id' => $this->userId,
            'role' => $this->role,
            'enrolled_at' => $this->enrolledAt,
        ];
    }
}
