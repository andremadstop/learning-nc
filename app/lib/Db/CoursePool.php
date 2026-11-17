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
 * @method bool getRequiredEnforced()
 * @method void setRequiredEnforced(bool $requiredEnforced)
 * @method ?string getFilterExamKey()
 * @method void setFilterExamKey(?string $filterExamKey)
 * @method ?string getFilterChapterKey()
 * @method void setFilterChapterKey(?string $filterChapterKey)
 * @method ?string getFilterQuestionIds()
 * @method void setFilterQuestionIds(?string $filterQuestionIds)
 * @method bool getExamRelevant()
 * @method void setExamRelevant(bool $examRelevant)
 * @method int|null getExamQuestions()
 * @method void setExamQuestions(?int $examQuestions)
 * @method int|null getExamMinutes()
 * @method void setExamMinutes(?int $examMinutes)
 */
class CoursePool extends Entity implements \JsonSerializable {
    protected $courseId;
    protected $poolId;
    protected $sortOrder;
    protected $required;
    protected $requiredEnforced;
    protected $filterExamKey;
    protected $filterChapterKey;
    protected $filterQuestionIds;
    protected $examRelevant;
    /** GitHub #47: questions per counting exam in this pool; NULL = course default. */
    protected $examQuestions;
    /** GitHub #47: time limit of a counting exam in minutes; NULL = course default. */
    protected $examMinutes;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('courseId', 'integer');
        $this->addType('poolId', 'integer');
        $this->addType('sortOrder', 'integer');
        $this->addType('required', 'integer');
        $this->addType('requiredEnforced', 'integer');
        $this->addType('filterExamKey', 'string');
        $this->addType('filterChapterKey', 'string');
        $this->addType('filterQuestionIds', 'string');
        $this->addType('examRelevant', 'integer');
        $this->addType('examQuestions', 'integer');
        $this->addType('examMinutes', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'course_id' => $this->courseId,
            'pool_id' => $this->poolId,
            'sort_order' => $this->sortOrder,
            'required' => $this->required,
            'required_enforced' => (bool)($this->requiredEnforced ?? false),
            'filter_exam_key' => $this->filterExamKey,
            'filter_chapter_key' => $this->filterChapterKey,
            'filter_question_ids' => $this->filterQuestionIds ? (json_decode($this->filterQuestionIds, true) ?: []) : [],
            'exam_relevant' => (bool)($this->examRelevant ?? false),
            'exam_questions' => $this->examQuestions !== null ? (int)$this->examQuestions : null,
            'exam_minutes' => $this->examMinutes !== null ? (int)$this->examMinutes : null,
        ];
    }
}
