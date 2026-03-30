<?php
declare(strict_types=1);

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
 * @method string|null getModeConfig()
 * @method void setModeConfig(?string $modeConfig)
 * @method int|null getExamAvailableFrom()
 * @method void setExamAvailableFrom(?int $examAvailableFrom)
 * @method string|null getExamDate()
 * @method void setExamDate(?string $examDate)
 * @method int|null getExamAttemptsPerDay()
 * @method void setExamAttemptsPerDay(?int $examAttemptsPerDay)
 * @method bool getExamRequiresTraining()
 * @method void setExamRequiresTraining(bool $examRequiresTraining)
 * @method string|null getMaterialFolder()
 * @method void setMaterialFolder(?string $materialFolder)
 * @method string|null getEnabledTools()
 * @method void setEnabledTools(?string $enabledTools)
 * @method string|null getTalkRoomToken()
 * @method void setTalkRoomToken(?string $talkRoomToken)
 * @method bool getLeitnerSprint()
 * @method void setLeitnerSprint(bool $leitnerSprint)
 */
class Course extends Entity {
    protected $title;
    protected $description;
    protected $instructorId;
    protected $ncGroupId;
    protected $status;
    protected $createdAt;
    protected $updatedAt;
    protected $modeConfig;
    protected $examAvailableFrom;
    protected $examDate;
    protected $examAttemptsPerDay;
    protected $examRequiresTraining;
    protected $materialFolder;
    protected $enabledTools;
    protected $talkRoomToken;
    protected $leitnerSprint;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
        $this->addType('examAvailableFrom', 'integer');
        $this->addType('examAttemptsPerDay', 'integer');
        $this->addType('examRequiresTraining', 'boolean');
        $this->addType('leitnerSprint', 'boolean');
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
            'mode_config' => $this->modeConfig ? json_decode($this->modeConfig, true) : null,
            'exam_available_from' => $this->examAvailableFrom,
            'exam_date' => $this->examDate,
            'exam_attempts_per_day' => $this->examAttemptsPerDay,
            'exam_requires_training' => $this->examRequiresTraining ?? false,
            'material_folder' => $this->materialFolder,
            'enabled_tools' => $this->enabledTools !== null ? (json_decode($this->enabledTools, true) ?: []) : null,
            'talk_room_token' => $this->talkRoomToken,
            'leitner_sprint' => $this->leitnerSprint ?? false,
        ];
    }
}
