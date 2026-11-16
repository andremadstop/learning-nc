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
 * @method int|null getDocumentId()
 * @method void setDocumentId(?int $documentId)
 * @method int|null getConfirmAfter()
 * @method void setConfirmAfter(?int $confirmAfter)
 */
class CourseVideo extends Entity implements \JsonSerializable {
    protected $courseId;
    protected $sourceType;
    protected $videoRef;
    protected $title;
    protected $durationSeconds;
    protected $subtitleRef;
    protected $sortOrder;
    protected $createdAt;
    /** GitHub #75: course_documents id of a document row (UNIQUE per course), NULL for videos. */
    protected $documentId;
    /** GitHub #75: "Ask everyone to confirm again" — confirmations before it no longer count. */
    protected $confirmAfter;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('courseId', 'integer');
        $this->addType('durationSeconds', 'integer');
        $this->addType('sortOrder', 'integer');
        $this->addType('createdAt', 'integer');
        $this->addType('documentId', 'integer');
        $this->addType('confirmAfter', 'integer');
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
            'document_id' => $this->documentId,
            'confirm_after' => $this->confirmAfter,
        ];
    }
}
