<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getCourseId()
 * @method void setCourseId(int $courseId)
 * @method string getChapterRef()
 * @method void setChapterRef(string $chapterRef)
 * @method string|null getChapterTitle()
 * @method void setChapterTitle(?string $chapterTitle)
 * @method string|null getStartDate()
 * @method void setStartDate(?string $startDate)
 * @method string|null getTargetDate()
 * @method void setTargetDate(?string $targetDate)
 * @method int getSortOrder()
 * @method void setSortOrder(int $sortOrder)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string getUpdatedAt()
 * @method void setUpdatedAt(string $updatedAt)
 */
class CourseSchedule extends Entity {
    protected $courseId;
    protected $chapterRef;
    protected $chapterTitle;
    protected $startDate;
    protected $targetDate;
    protected $sortOrder;
    protected $createdAt;
    protected $updatedAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('courseId', 'integer');
        $this->addType('sortOrder', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'course_id' => $this->courseId,
            'chapter_ref' => $this->chapterRef,
            'chapter_title' => $this->chapterTitle,
            'start_date' => $this->startDate,
            'target_date' => $this->targetDate,
            'sort_order' => $this->sortOrder,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
