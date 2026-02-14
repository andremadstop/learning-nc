<?php
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string getInstructorId()
 * @method void setInstructorId(string $instructorId)
 * @method string|null getNcGroupId()
 * @method void setNcGroupId(?string $ncGroupId)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Course extends Entity {
    protected $title;
    protected $description;
    protected $instructorId;
    protected $ncGroupId;
    protected $status;
    protected $createdAt;
    protected $updatedAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'instructor_id' => $this->instructorId,
            'nc_group_id' => $this->ncGroupId,
            'status' => $this->status,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
