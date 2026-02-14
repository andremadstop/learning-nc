<?php
namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getCourseId()
 * @method void setCourseId(int $courseId)
 * @method int getPoolId()
 * @method void setPoolId(int $poolId)
 * @method int getSortOrder()
 * @method void setSortOrder(int $sortOrder)
 * @method bool getRequired()
 * @method void setRequired(bool $required)
 */
class CoursePool extends Entity {
    protected $courseId;
    protected $poolId;
    protected $sortOrder;
    protected $required;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('courseId', 'integer');
        $this->addType('poolId', 'integer');
        $this->addType('sortOrder', 'integer');
        $this->addType('required', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'course_id' => $this->courseId,
            'pool_id' => $this->poolId,
            'sort_order' => $this->sortOrder,
            'required' => $this->required,
        ];
    }
}
