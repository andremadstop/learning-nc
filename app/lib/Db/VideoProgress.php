<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Per-(user, content) watch state (Phase 162, VIDEO-02/06).
 *
 * intervals_json + covered_pct are DSGVO-transient working state, nulled at the completed_at
 * write. last_ping_ts drives <5s heartbeat plausibility and doubles as the CAS token that
 * serializes one user's concurrent tabs. completed_at is the only permanent field.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getContentId()
 * @method void setContentId(int $contentId)
 * @method string|null getIntervalsJson()
 * @method void setIntervalsJson(?string $intervalsJson)
 * @method float|null getCoveredPct()
 * @method void setCoveredPct(?float $coveredPct)
 * @method int|null getLastPingTs()
 * @method void setLastPingTs(?int $lastPingTs)
 * @method int|null getCompletedAt()
 * @method void setCompletedAt(?int $completedAt)
 */
class VideoProgress extends Entity {
    protected $userId;
    protected $contentId;
    protected $intervalsJson;
    protected $coveredPct;
    protected $lastPingTs;
    protected $completedAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('contentId', 'integer');
        $this->addType('coveredPct', 'float');
        $this->addType('lastPingTs', 'integer');
        $this->addType('completedAt', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'content_id' => $this->contentId,
            'intervals_json' => $this->intervalsJson,
            'covered_pct' => $this->coveredPct,
            'last_ping_ts' => $this->lastPingTs,
            'completed_at' => $this->completedAt,
        ];
    }
}
