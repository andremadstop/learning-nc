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
 * @method string|null getAllowedCampaigns()
 * @method void setAllowedCampaigns(?string $allowedCampaigns)
 * @method bool getMaintenanceMode()
 * @method void setMaintenanceMode(bool $maintenanceMode)
 * @method bool|null getCertEnabled()
 * @method void setCertEnabled(bool $certEnabled)
 * @method int|null getCertPassPercent()
 * @method void setCertPassPercent(int $certPassPercent)
 * @method string|null getCertRequiredPoolIds()
 * @method void setCertRequiredPoolIds(?string $certRequiredPoolIds)
 * @method int|null getCertValidityDays()
 * @method void setCertValidityDays(int $certValidityDays)
 * @method int|null getCertValidityMonths()
 * @method void setCertValidityMonths(?int $certValidityMonths)
 * @method bool|null getVideoGateEnabled()
 * @method void setVideoGateEnabled(bool $videoGateEnabled)
 * @method bool|null getPracticeEnabled()
 * @method void setPracticeEnabled(bool $practiceEnabled)
 * @method int|null getPracticeQuestions()
 * @method void setPracticeQuestions(int $practiceQuestions)
 * @method int|null getPracticeMinutes()
 * @method void setPracticeMinutes(int $practiceMinutes)
 * @method int|null getPracticePassPercent()
 * @method void setPracticePassPercent(int $practicePassPercent)
 * @method bool|null getPracticeRequiredOnly()
 * @method void setPracticeRequiredOnly(bool $practiceRequiredOnly)
 * @method int|null getExamQuestions()
 * @method void setExamQuestions(?int $examQuestions)
 * @method int|null getExamMinutes()
 * @method void setExamMinutes(?int $examMinutes)
 * @method string|null getCertLayout()
 * @method void setCertLayout(?string $certLayout)
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
    protected $allowedCampaigns;
    protected $maintenanceMode;
    protected $certEnabled;
    protected $certPassPercent;
    protected $certRequiredPoolIds;
    protected $certValidityDays;
    protected $certValidityMonths;
    protected $videoGateEnabled;
    protected $practiceEnabled;
    protected $practiceQuestions;
    protected $practiceMinutes;
    protected $practicePassPercent;
    protected $practiceRequiredOnly;
    /** GitHub #47: course default for the question count of a counting exam; NULL = none. */
    protected $examQuestions;
    /** GitHub #47: course default for the time limit of a counting exam in minutes; NULL = none. */
    protected $examMinutes;
    /** GitHub #43: how the course's certificates look (CertLayout JSON); NULL = the standard card. */
    protected $certLayout;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
        $this->addType('examAvailableFrom', 'integer');
        $this->addType('examAttemptsPerDay', 'integer');
        $this->addType('examRequiresTraining', 'boolean');
        $this->addType('leitnerSprint', 'boolean');
        $this->addType('maintenanceMode', 'boolean');
        $this->addType('certEnabled', 'boolean');
        $this->addType('certPassPercent', 'integer');
        $this->addType('certRequiredPoolIds', 'text');
        $this->addType('certValidityDays', 'integer');
        $this->addType('certValidityMonths', 'integer');
        $this->addType('videoGateEnabled', 'boolean');
        $this->addType('practiceEnabled', 'boolean');
        $this->addType('practiceQuestions', 'integer');
        $this->addType('practiceMinutes', 'integer');
        $this->addType('practicePassPercent', 'integer');
        $this->addType('practiceRequiredOnly', 'boolean');
        $this->addType('examQuestions', 'integer');
        $this->addType('examMinutes', 'integer');
        $this->addType('certLayout', 'string');
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
            'allowed_campaigns' => $this->allowedCampaigns !== null ? (json_decode($this->allowedCampaigns, true) ?: []) : null,
            'maintenance_mode' => $this->maintenanceMode ?? false,
            'cert_enabled' => $this->getCertEnabled() ?? false,
            'cert_pass_percent' => $this->getCertPassPercent() ?? 80,
            'cert_required_pool_ids' => $this->getCertRequiredPoolIds() !== null
                ? (json_decode($this->getCertRequiredPoolIds(), true) ?: [])
                : [],
            'cert_validity_days' => $this->getCertValidityDays() ?? 0,
            // NULL = unset → legacy days fallback governs expiry; 0 = explicit no-expiry; >0 = months
            'cert_validity_months' => $this->getCertValidityMonths(),
            'video_gate_enabled' => $this->getVideoGateEnabled() ?? false,
            // Codeberg #9 — practice exam; 0 minutes = untimed. Visible to learners: they need it to start one.
            'practice_enabled' => $this->getPracticeEnabled() ?? false,
            'practice_questions' => $this->getPracticeQuestions() ?? 20,
            'practice_minutes' => $this->getPracticeMinutes() ?? 0,
            'practice_pass_percent' => $this->getPracticePassPercent() ?? 75,
            'practice_required_only' => $this->getPracticeRequiredOnly() ?? false,
            // GitHub #47: course default of the exam scope; NULL = each pool decides (or legacy).
            'exam_questions' => $this->examQuestions !== null ? (int)$this->examQuestions : null,
            'exam_minutes' => $this->examMinutes !== null ? (int)$this->examMinutes : null,
            // GitHub #43: the certificate layout without image bytes (has_stamp / has_signature only).
            'cert_layout' => \OCA\Learning\Service\CertLayout::forSettings($this->certLayout),
        ];
    }
}
