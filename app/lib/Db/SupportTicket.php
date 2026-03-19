<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

class SupportTicket extends Entity {
    protected $userId;
    protected $status;
    protected $subject;
    protected $message;
    protected $contextJson;
    protected $courseId;
    protected $poolId;
    protected $questionId;
    protected $duelCode;
    protected $leagueSeasonId;
    protected $createdAt;
    protected $updatedAt;
    protected $answeredBy;
    protected $answeredAt;
    protected $answerText;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('courseId', 'integer');
        $this->addType('poolId', 'integer');
        $this->addType('questionId', 'integer');
        $this->addType('leagueSeasonId', 'integer');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
        $this->addType('answeredAt', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'status' => $this->status,
            'subject' => $this->subject,
            'message' => $this->message,
            'context_json' => $this->contextJson,
            'course_id' => $this->courseId,
            'pool_id' => $this->poolId,
            'question_id' => $this->questionId,
            'duel_code' => $this->duelCode,
            'league_season_id' => $this->leagueSeasonId,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'answered_by' => $this->answeredBy,
            'answered_at' => $this->answeredAt,
            'answer_text' => $this->answerText,
        ];
    }
}
