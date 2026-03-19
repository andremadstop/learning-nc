<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

class LeagueSeason extends Entity {
    protected $courseId;
    protected $name;
    protected $poolId;
    protected $clPoolId;
    protected $numQuestions;
    protected $clNumQuestions;
    protected $status;
    protected $startedAt;
    protected $endsAt;
    protected $createdAt;
    protected $createdBy;
    protected $handbookKey;
    protected $handbookTitle;
    protected $chapterKey;
    protected $chapterTitle;
    protected $chapterOrder;
    protected $clHandbookKey;
    protected $clHandbookTitle;
    protected $clChapterKey;
    protected $clChapterTitle;
    protected $clChapterOrder;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('courseId', 'integer');
        $this->addType('poolId', 'integer');
        $this->addType('clPoolId', 'integer');
        $this->addType('numQuestions', 'integer');
        $this->addType('clNumQuestions', 'integer');
        $this->addType('startedAt', 'integer');
        $this->addType('endsAt', 'integer');
        $this->addType('createdAt', 'integer');
        $this->addType('chapterOrder', 'integer');
        $this->addType('clChapterOrder', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'course_id' => $this->courseId,
            'name' => $this->name,
            'pool_id' => $this->poolId,
            'cl_pool_id' => $this->clPoolId,
            'num_questions' => $this->numQuestions,
            'cl_num_questions' => $this->clNumQuestions,
            'status' => $this->status,
            'started_at' => $this->startedAt,
            'ends_at' => $this->endsAt,
            'created_at' => $this->createdAt,
            'created_by' => $this->createdBy,
            'handbook_key' => $this->handbookKey,
            'handbook_title' => $this->handbookTitle,
            'chapter_key' => $this->chapterKey,
            'chapter_title' => $this->chapterTitle,
            'chapter_order' => $this->chapterOrder,
            'cl_handbook_key' => $this->clHandbookKey,
            'cl_handbook_title' => $this->clHandbookTitle,
            'cl_chapter_key' => $this->clChapterKey,
            'cl_chapter_title' => $this->clChapterTitle,
            'cl_chapter_order' => $this->clChapterOrder,
        ];
    }
}
