<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

class LeagueChallenge extends Entity {
    protected $seasonId;
    protected $stage;
    protected $roundNumber;
    protected $challengerUid;
    protected $opponentUid;
    protected $duelSessionId;
    protected $status;
    protected $createdAt;
    protected $acceptedAt;
    protected $deadlineAt;
    protected $winnerUid;
    protected $playedAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('seasonId', 'integer');
        $this->addType('roundNumber', 'integer');
        $this->addType('duelSessionId', 'integer');
        $this->addType('createdAt', 'integer');
        $this->addType('acceptedAt', 'integer');
        $this->addType('deadlineAt', 'integer');
        $this->addType('playedAt', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'season_id' => $this->seasonId,
            'stage' => $this->stage,
            'round_number' => $this->roundNumber,
            'challenger_uid' => $this->challengerUid,
            'opponent_uid' => $this->opponentUid,
            'duel_session_id' => $this->duelSessionId,
            'status' => $this->status,
            'created_at' => $this->createdAt,
            'accepted_at' => $this->acceptedAt,
            'deadline_at' => $this->deadlineAt,
            'winner_uid' => $this->winnerUid,
            'played_at' => $this->playedAt,
        ];
    }
}
