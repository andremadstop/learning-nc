<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

class DuelInvite extends Entity {
    protected $duelSessionId;
    protected $courseId;
    protected $poolId;
    protected $inviterUid;
    protected $inviteeUid;
    protected $status;
    protected $createdAt;
    protected $updatedAt;
    protected $acceptedAt;
    protected $respondedAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('duelSessionId', 'integer');
        $this->addType('courseId', 'integer');
        $this->addType('poolId', 'integer');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
        $this->addType('acceptedAt', 'integer');
        $this->addType('respondedAt', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'duel_session_id' => $this->duelSessionId,
            'course_id' => $this->courseId,
            'pool_id' => $this->poolId,
            'inviter_uid' => $this->inviterUid,
            'invitee_uid' => $this->inviteeUid,
            'status' => $this->status,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'accepted_at' => $this->acceptedAt,
            'responded_at' => $this->respondedAt,
        ];
    }
}
