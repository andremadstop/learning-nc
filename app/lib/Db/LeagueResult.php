<?php
declare(strict_types=1);

namespace OCA\Learning\Db;

use OCP\AppFramework\Db\Entity;

class LeagueResult extends Entity {
    protected $seasonId;
    protected $challengeId;
    protected $duelSessionId;
    protected $stage;
    protected $roundNumber;
    protected $playerAUid;
    protected $playerBUid;
    protected $scoreA;
    protected $scoreB;
    protected $leaguePointsA;
    protected $leaguePointsB;
    protected $winnerUid;
    protected $playedAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('seasonId', 'integer');
        $this->addType('challengeId', 'integer');
        $this->addType('duelSessionId', 'integer');
        $this->addType('roundNumber', 'integer');
        $this->addType('scoreA', 'integer');
        $this->addType('scoreB', 'integer');
        $this->addType('leaguePointsA', 'integer');
        $this->addType('leaguePointsB', 'integer');
        $this->addType('playedAt', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'season_id' => $this->seasonId,
            'challenge_id' => $this->challengeId,
            'duel_session_id' => $this->duelSessionId,
            'stage' => $this->stage,
            'round_number' => $this->roundNumber,
            'player_a_uid' => $this->playerAUid,
            'player_b_uid' => $this->playerBUid,
            'score_a' => $this->scoreA,
            'score_b' => $this->scoreB,
            'league_points_a' => $this->leaguePointsA,
            'league_points_b' => $this->leaguePointsB,
            'winner_uid' => $this->winnerUid,
            'played_at' => $this->playedAt,
        ];
    }
}
