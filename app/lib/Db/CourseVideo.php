<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A gated-content registry row (Phase 162, VIDEO-09). Belongs to a course; may be a native
 * NC-Files video, a third-party embed (vimeo / youtube-nocookie), or a document material.
 *
 * @method int getCourseId()
 * @method void setCourseId(int $courseId)
 * @method string getSourceType()
 * @method void setSourceType(string $sourceType)
 * @method string getVideoRef()
 * @method void setVideoRef(string $videoRef)
 * @method string|null getTitle()
 * @method void setTitle(?string $title)
 * @method int|null getDurationSeconds()
 * @method void setDurationSeconds(?int $durationSeconds)
 * @method string|null getSubtitleRef()
 * @method void setSubtitleRef(?string $subtitleRef)
 * @method int getSortOrder()
 * @method void setSortOrder(int $sortOrder)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class CourseVideo extends Entity {
    protected $courseId;
    protected $sourceType;
    protected $videoRef;
    protected $title;
    protected $durationSeconds;
    protected $subtitleRef;
    protected $sortOrder;
    protected $createdAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('courseId', 'integer');
        $this->addType('durationSeconds', 'integer');
        $this->addType('sortOrder', 'integer');
        $this->addType('createdAt', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'course_id' => $this->courseId,
            'source_type' => $this->sourceType,
            'video_ref' => $this->videoRef,
            'title' => $this->title,
            'duration_seconds' => $this->durationSeconds,
            'subtitle_ref' => $this->subtitleRef,
            'sort_order' => $this->sortOrder,
            'created_at' => $this->createdAt,
        ];
    }
}
