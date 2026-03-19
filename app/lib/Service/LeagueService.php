<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\DuelSession;
use OCA\Learning\Db\DuelSessionMapper;
use OCA\Learning\Db\LeagueChallenge;
use OCA\Learning\Db\LeagueChallengeMapper;
use OCA\Learning\Db\LeagueResult;
use OCA\Learning\Db\LeagueResultMapper;
use OCA\Learning\Db\LeagueSeason;
use OCA\Learning\Db\LeagueSeasonMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;

class LeagueService {
    private const SEASON_DURATION_DAYS = 14;
    private const CHALLENGE_TIMEOUT_DAYS = 5;
    private const MIN_PARTICIPANTS = 4;
    private const LEAGUE_NUM_QUESTIONS = 10;
    private const CL_NUM_QUESTIONS = 15;

    public function __construct(
        private LeagueSeasonMapper $seasonMapper,
        private LeagueChallengeMapper $challengeMapper,
        private LeagueResultMapper $resultMapper,
        private CourseService $courseService,
        private CourseMapper $courseMapper,
        private CourseMemberMapper $courseMemberMapper,
        private DuelSessionMapper $duelSessionMapper,
        private IDBConnection $db,
        private IUserManager $userManager
    ) {
    }

    public function getOverview(int $courseId, string $userId): array {
        $courseContext = $this->courseService->findById($courseId, $userId);
        $season = $this->getLatestSeason($courseId);

        $overview = [
            'season' => null,
            'standings' => [],
            'incoming_challenges' => [],
            'outgoing_challenges' => [],
            'cl' => null,
            'can_manage' => (bool)($courseContext['is_instructor'] ?? false),
            'can_create_season' => (bool)($courseContext['is_instructor'] ?? false),
            'config' => [
                'season_days' => self::SEASON_DURATION_DAYS,
                'challenge_days' => self::CHALLENGE_TIMEOUT_DAYS,
                'league_questions' => self::LEAGUE_NUM_QUESTIONS,
                'cl_questions' => self::CL_NUM_QUESTIONS,
                'min_participants' => self::MIN_PARTICIPANTS,
            ],
        ];

        if ($season === null) {
            return $overview;
        }

        $this->applyForfeits($season);
        $season = $this->seasonMapper->findById($season->getId());

        $overview['season'] = $this->buildSeasonData($season);
        $overview['standings'] = $this->buildStandings($season, $userId);
        $overview['incoming_challenges'] = $this->buildChallengeCards($season, $userId, true);
        $overview['outgoing_challenges'] = $this->buildChallengeCards($season, $userId, false);
        $overview['cl'] = $this->buildChampionsLeague($season, $userId);
        $overview['can_create_season'] = $overview['can_manage'] && !$this->hasOpenSeasonWork($courseId);

        return $overview;
    }

    public function createSeason(int $courseId, string $userId, int $poolId, int $clPoolId, ?string $name = null): array {
        $courseContext = $this->courseService->findById($courseId, $userId);
        $this->assertInstructor($courseContext);

        if ($this->hasOpenSeasonWork($courseId)) {
            throw new \RuntimeException('Finish the current league season before creating a new one');
        }

        $this->assertCoursePool($courseContext, $poolId);
        $this->assertCoursePool($courseContext, $clPoolId);

        $seasonName = trim((string)$name);
        if ($seasonName === '') {
            $seasonName = 'Saison ' . (count($this->seasonMapper->findByCourse($courseId)) + 1);
        }

        $leaguePool = $this->resolvePoolContext($courseContext, $poolId);
        $clPool = $this->resolvePoolContext($courseContext, $clPoolId);

        $season = new LeagueSeason();
        $season->setCourseId($courseId);
        $season->setName($seasonName);
        $season->setPoolId($poolId);
        $season->setClPoolId($clPoolId);
        $season->setNumQuestions(self::LEAGUE_NUM_QUESTIONS);
        $season->setClNumQuestions(self::CL_NUM_QUESTIONS);
        $season->setStatus('open');
        $season->setStartedAt(null);
        $season->setEndsAt(null);
        $season->setCreatedAt(time());
        $season->setCreatedBy($userId);
        $season->setHandbookKey($leaguePool['handbook_key']);
        $season->setHandbookTitle($leaguePool['handbook_title']);
        $season->setChapterKey($leaguePool['chapter_key']);
        $season->setChapterTitle($leaguePool['chapter_title']);
        $season->setChapterOrder($leaguePool['chapter_order']);
        $season->setClHandbookKey($clPool['handbook_key']);
        $season->setClHandbookTitle($clPool['handbook_title']);
        $season->setClChapterKey($clPool['chapter_key']);
        $season->setClChapterTitle($clPool['chapter_title']);
        $season->setClChapterOrder($clPool['chapter_order']);

        $this->seasonMapper->insert($season);

        return $this->getOverview($courseId, $userId);
    }

    public function startSeason(int $courseId, int $seasonId, string $userId): array {
        $courseContext = $this->courseService->findById($courseId, $userId);
        $this->assertInstructor($courseContext);

        $season = $this->getSeasonForCourse($courseId, $seasonId);
        if ($season->getStatus() !== 'open') {
            throw new \RuntimeException('Season is not open');
        }

        $participantCount = count($this->getCourseParticipants($courseId));
        if ($participantCount < self::MIN_PARTICIPANTS) {
            throw new \RuntimeException('At least 4 participants are required to start a season');
        }

        $now = time();
        $season->setStatus('active');
        $season->setStartedAt($now);
        $season->setEndsAt($now + (self::SEASON_DURATION_DAYS * 86400));
        $this->seasonMapper->update($season);

        return $this->getOverview($courseId, $userId);
    }

    public function finishSeason(int $courseId, int $seasonId, string $userId): array {
        $courseContext = $this->courseService->findById($courseId, $userId);
        $this->assertInstructor($courseContext);

        $season = $this->getSeasonForCourse($courseId, $seasonId);
        if ($season->getStatus() !== 'active') {
            throw new \RuntimeException('Season is not active');
        }

        $this->applyForfeits($season);
        foreach ($this->challengeMapper->findBySeason($season->getId()) as $challenge) {
            if ($challenge->getStage() !== 'league') {
                continue;
            }
            if (!in_array($challenge->getStatus(), ['open', 'accepted'], true)) {
                continue;
            }
            $this->recordResult(
                $season,
                $challenge,
                $challenge->getDuelSessionId(),
                0,
                0,
                time(),
                true
            );
        }

        $season->setStatus('finished');
        $this->seasonMapper->update($season);
        $this->createChampionsLeague($season);

        return $this->getOverview($courseId, $userId);
    }

    public function challengeOpponent(int $courseId, int $seasonId, string $opponentUid, string $userId): array {
        $this->courseService->findById($courseId, $userId);
        $season = $this->getSeasonForCourse($courseId, $seasonId);
        $this->applyForfeits($season);

        if ($season->getStatus() !== 'active') {
            throw new \RuntimeException('Season is not active');
        }
        if ($season->getEndsAt() !== null && time() > $season->getEndsAt()) {
            throw new \RuntimeException('Season has reached its end date');
        }
        if ($opponentUid === $userId) {
            throw new \RuntimeException('You cannot challenge yourself');
        }

        $participants = $this->getCourseParticipants($courseId);
        if (!isset($participants[$userId]) || !isset($participants[$opponentUid])) {
            throw new \RuntimeException('Challenge must stay within the course');
        }

        $this->assertPairingAvailable($season, $userId, $opponentUid, 'league');

        $challenge = new LeagueChallenge();
        $challenge->setSeasonId($season->getId());
        $challenge->setStage('league');
        $challenge->setRoundNumber(null);
        $challenge->setChallengerUid($userId);
        $challenge->setOpponentUid($opponentUid);
        $challenge->setDuelSessionId(null);
        $challenge->setStatus('open');
        $challenge->setCreatedAt(time());
        $challenge->setAcceptedAt(null);
        $challenge->setDeadlineAt(time() + (self::CHALLENGE_TIMEOUT_DAYS * 86400));
        $challenge->setWinnerUid(null);
        $challenge->setPlayedAt(null);
        $this->challengeMapper->insert($challenge);

        return $this->getOverview($courseId, $userId);
    }

    public function acceptChallenge(int $courseId, int $seasonId, int $challengeId, string $userId): array {
        $this->courseService->findById($courseId, $userId);
        $season = $this->getSeasonForCourse($courseId, $seasonId);
        $this->applyForfeits($season);

        $challenge = $this->challengeMapper->findById($challengeId);
        if ($challenge->getSeasonId() !== $season->getId()) {
            throw new \RuntimeException('Challenge does not belong to this season');
        }
        if ($challenge->getStatus() !== 'open') {
            throw new \RuntimeException('Challenge is no longer open');
        }
        if ($challenge->getOpponentUid() !== $userId) {
            throw new \RuntimeException('Only the challenged player can accept');
        }
        if ($challenge->getStage() === 'league' && $season->getStatus() !== 'active') {
            throw new \RuntimeException('League season is no longer active');
        }

        $duel = $this->createLeagueDuel($season, $challenge);

        return [
            'duel_code' => $duel->getCode(),
            'challenge_id' => $challenge->getId(),
            'season_id' => $season->getId(),
        ];
    }

    public function getTable(int $courseId, int $seasonId, string $userId): array {
        $this->courseService->findById($courseId, $userId);
        $season = $this->getSeasonForCourse($courseId, $seasonId);
        $this->applyForfeits($season);
        return $this->buildStandings($season, $userId);
    }

    public function getChampionsLeague(int $courseId, int $seasonId, string $userId): array {
        $this->courseService->findById($courseId, $userId);
        $season = $this->getSeasonForCourse($courseId, $seasonId);
        $this->applyForfeits($season);
        return $this->buildChampionsLeague($season, $userId) ?? ['status' => 'not_available'];
    }

    public function recordFinishedDuel(DuelSession $session): void {
        if ($session->getLeagueChallengeId() === null) {
            return;
        }

        $challenge = $this->challengeMapper->findById((int)$session->getLeagueChallengeId());
        if (in_array($challenge->getStatus(), ['finished', 'forfeit'], true)) {
            return;
        }

        $season = $this->seasonMapper->findById((int)$session->getLeagueSeasonId());
        $this->recordResult(
            $season,
            $challenge,
            (int)$session->getId(),
            (int)$session->getCreatorScore(),
            (int)$session->getOpponentScore(),
            time(),
            false
        );
    }

    public function recordExpiredDuel(DuelSession $session): void {
        if ($session->getLeagueChallengeId() === null) {
            return;
        }

        $challenge = $this->challengeMapper->findById((int)$session->getLeagueChallengeId());
        if (in_array($challenge->getStatus(), ['finished', 'forfeit'], true)) {
            return;
        }

        $season = $this->seasonMapper->findById((int)$session->getLeagueSeasonId());
        $this->recordResult($season, $challenge, (int)$session->getId(), 0, 0, time(), true);
    }

    private function getLatestSeason(int $courseId): ?LeagueSeason {
        $seasons = $this->seasonMapper->findByCourse($courseId);
        return $seasons[0] ?? null;
    }

    private function hasOpenSeasonWork(int $courseId): bool {
        $season = $this->getLatestSeason($courseId);
        if ($season === null) {
            return false;
        }

        if (in_array($season->getStatus(), ['open', 'active'], true)) {
            return true;
        }

        foreach ($this->challengeMapper->findBySeason($season->getId()) as $challenge) {
            if (in_array($challenge->getStatus(), ['open', 'accepted'], true)) {
                return true;
            }
        }

        return false;
    }

    private function getSeasonForCourse(int $courseId, int $seasonId): LeagueSeason {
        $season = $this->seasonMapper->findById($seasonId);
        if ($season->getCourseId() !== $courseId) {
            throw new \RuntimeException('Season does not belong to this course');
        }
        return $season;
    }

    private function assertInstructor(array $courseContext): void {
        if (empty($courseContext['is_instructor'])) {
            throw new \RuntimeException('Only instructors can manage league seasons');
        }
    }

    private function assertCoursePool(array $courseContext, int $poolId): void {
        foreach ($courseContext['pools'] ?? [] as $pool) {
            if ((int)($pool['pool_id'] ?? 0) === $poolId) {
                return;
            }
        }

        throw new \RuntimeException('Selected pool is not assigned to this course');
    }

    private function buildSeasonData(LeagueSeason $season): array {
        $data = $season->jsonSerialize();
        $poolContext = $this->getPoolContext((int)$season->getPoolId());
        $clPoolContext = $this->getPoolContext((int)$season->getClPoolId());
        $data['pool_name'] = $poolContext['name'];
        $data['cl_pool_name'] = $clPoolContext['name'];
        $data['handbook_key'] = $data['handbook_key'] ?? $poolContext['handbook_key'];
        $data['handbook_title'] = $data['handbook_title'] ?? $poolContext['handbook_title'];
        $data['chapter_key'] = $data['chapter_key'] ?? $poolContext['chapter_key'];
        $data['chapter_title'] = $data['chapter_title'] ?? $poolContext['chapter_title'];
        $data['chapter_order'] = $data['chapter_order'] ?? $poolContext['chapter_order'];
        $data['cl_handbook_key'] = $data['cl_handbook_key'] ?? $clPoolContext['handbook_key'];
        $data['cl_handbook_title'] = $data['cl_handbook_title'] ?? $clPoolContext['handbook_title'];
        $data['cl_chapter_key'] = $data['cl_chapter_key'] ?? $clPoolContext['chapter_key'];
        $data['cl_chapter_title'] = $data['cl_chapter_title'] ?? $clPoolContext['chapter_title'];
        $data['cl_chapter_order'] = $data['cl_chapter_order'] ?? $clPoolContext['chapter_order'];
        return $data;
    }

    private function getPoolContext(int $poolId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select(
            'name',
            'handbook_key',
            'handbook_title',
            'chapter_key',
            'chapter_title',
            'chapter_order'
        )
            ->from('learning_pools')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        if ($row === false) {
            return [
                'name' => '(deleted)',
                'handbook_key' => null,
                'handbook_title' => null,
                'chapter_key' => null,
                'chapter_title' => null,
                'chapter_order' => null,
            ];
        }
        return [
            'name' => $row['name'] ?: '(deleted)',
            'handbook_key' => $row['handbook_key'] ?? null,
            'handbook_title' => $row['handbook_title'] ?? null,
            'chapter_key' => $row['chapter_key'] ?? null,
            'chapter_title' => $row['chapter_title'] ?? null,
            'chapter_order' => isset($row['chapter_order']) ? (int)$row['chapter_order'] : null,
        ];
    }

    private function resolvePoolContext(array $courseContext, int $poolId): array {
        foreach ($courseContext['pools'] ?? [] as $pool) {
            if ((int)($pool['pool_id'] ?? 0) !== $poolId) {
                continue;
            }

            return [
                'handbook_key' => $pool['handbook_key'] ?? null,
                'handbook_title' => $pool['handbook_title'] ?? null,
                'chapter_key' => $pool['chapter_key'] ?? null,
                'chapter_title' => $pool['chapter_title'] ?? null,
                'chapter_order' => isset($pool['chapter_order']) ? (int)$pool['chapter_order'] : null,
            ];
        }

        return [
            'handbook_key' => null,
            'handbook_title' => null,
            'chapter_key' => null,
            'chapter_title' => null,
            'chapter_order' => null,
        ];
    }

    private function getCourseParticipants(int $courseId): array {
        $course = $this->courseMapper->findById($courseId);
        $participants = [
            $course->getInstructorId() => $this->buildParticipantRow($course->getInstructorId()),
        ];

        foreach ($this->courseMemberMapper->findByCourse($courseId) as $member) {
            $participants[$member->getUserId()] = $this->buildParticipantRow($member->getUserId());
        }

        return $participants;
    }

    private function buildParticipantRow(string $userId): array {
        $user = $this->userManager->get($userId);
        return [
            'user_id' => $userId,
            'display_name' => $user ? $user->getDisplayName() : $userId,
        ];
    }

    private function buildStandings(LeagueSeason $season, string $viewerUid): array {
        $participants = $this->getCourseParticipants((int)$season->getCourseId());
        $rows = [];
        foreach ($participants as $userId => $participant) {
            $rows[$userId] = [
                'user_id' => $userId,
                'display_name' => $participant['display_name'],
                'played' => 0,
                'wins' => 0,
                'draws' => 0,
                'losses' => 0,
                'points' => 0,
                'point_difference' => 0,
                'goals_for' => 0,
                'goals_against' => 0,
                'pairing_state' => $userId === $viewerUid ? 'self' : 'available',
                'pairing_challenge_id' => null,
                'duel_code' => null,
                'can_challenge' => false,
            ];
        }

        foreach ($this->getSeasonResults($season, 'league') as $result) {
            $aUid = $result->getPlayerAUid();
            $bUid = $result->getPlayerBUid();
            if (!isset($rows[$aUid]) || !isset($rows[$bUid])) {
                continue;
            }

            $rows[$aUid]['played']++;
            $rows[$bUid]['played']++;
            $rows[$aUid]['points'] += (int)$result->getLeaguePointsA();
            $rows[$bUid]['points'] += (int)$result->getLeaguePointsB();
            $rows[$aUid]['goals_for'] += (int)$result->getScoreA();
            $rows[$aUid]['goals_against'] += (int)$result->getScoreB();
            $rows[$bUid]['goals_for'] += (int)$result->getScoreB();
            $rows[$bUid]['goals_against'] += (int)$result->getScoreA();
            $rows[$aUid]['point_difference'] += (int)$result->getScoreA() - (int)$result->getScoreB();
            $rows[$bUid]['point_difference'] += (int)$result->getScoreB() - (int)$result->getScoreA();

            if ((int)$result->getLeaguePointsA() === 3) {
                $rows[$aUid]['wins']++;
                $rows[$bUid]['losses']++;
            } elseif ((int)$result->getLeaguePointsB() === 3) {
                $rows[$bUid]['wins']++;
                $rows[$aUid]['losses']++;
            } else {
                $rows[$aUid]['draws']++;
                $rows[$bUid]['draws']++;
            }
        }

        $pairings = $this->buildViewerPairings($season, $viewerUid);
        foreach ($rows as $userId => &$row) {
            if ($userId === $viewerUid) {
                $row['pairing_state'] = 'self';
                continue;
            }

            $pairing = $pairings[$userId] ?? null;
            if ($pairing !== null) {
                $row['pairing_state'] = $pairing['state'];
                $row['pairing_challenge_id'] = $pairing['challenge_id'];
                $row['duel_code'] = $pairing['duel_code'];
            } elseif ($season->getStatus() !== 'active') {
                $row['pairing_state'] = 'locked';
            }

            $row['can_challenge'] = $row['pairing_state'] === 'available' && $season->getStatus() === 'active';
        }
        unset($row);

        $rows = array_values($rows);
        usort($rows, static function (array $left, array $right): int {
            if ($left['points'] !== $right['points']) {
                return $right['points'] <=> $left['points'];
            }
            if ($left['point_difference'] !== $right['point_difference']) {
                return $right['point_difference'] <=> $left['point_difference'];
            }
            if ($left['wins'] !== $right['wins']) {
                return $right['wins'] <=> $left['wins'];
            }
            return strcasecmp((string)$left['display_name'], (string)$right['display_name']);
        });

        foreach ($rows as $index => &$row) {
            $row['rank'] = $index + 1;
        }
        unset($row);

        return $rows;
    }

    private function buildViewerPairings(LeagueSeason $season, string $viewerUid): array {
        $pairings = [];

        foreach ($this->getSeasonResults($season, 'league') as $result) {
            $otherUid = null;
            if ($result->getPlayerAUid() === $viewerUid) {
                $otherUid = $result->getPlayerBUid();
            } elseif ($result->getPlayerBUid() === $viewerUid) {
                $otherUid = $result->getPlayerAUid();
            }

            if ($otherUid !== null) {
                $pairings[$otherUid] = [
                    'state' => 'played',
                    'challenge_id' => $result->getChallengeId(),
                    'duel_code' => null,
                ];
            }
        }

        foreach ($this->challengeMapper->findBySeason($season->getId()) as $challenge) {
            if ($challenge->getStage() !== 'league') {
                continue;
            }
            if (!in_array($challenge->getStatus(), ['open', 'accepted'], true)) {
                continue;
            }

            $otherUid = null;
            $state = null;
            if ($challenge->getChallengerUid() === $viewerUid) {
                $otherUid = $challenge->getOpponentUid();
                $state = $challenge->getStatus() === 'accepted' ? 'accepted' : 'open_outgoing';
            } elseif ($challenge->getOpponentUid() === $viewerUid) {
                $otherUid = $challenge->getChallengerUid();
                $state = $challenge->getStatus() === 'accepted' ? 'accepted' : 'open_incoming';
            }

            if ($otherUid !== null && !isset($pairings[$otherUid])) {
                $pairings[$otherUid] = [
                    'state' => $state,
                    'challenge_id' => $challenge->getId(),
                    'duel_code' => $this->getDuelCode($challenge->getDuelSessionId()),
                ];
            }
        }

        return $pairings;
    }

    private function buildChallengeCards(LeagueSeason $season, string $viewerUid, bool $incoming): array {
        $cards = [];
        foreach ($this->challengeMapper->findBySeason($season->getId()) as $challenge) {
            if (!in_array($challenge->getStatus(), ['open', 'accepted'], true)) {
                continue;
            }

            $isIncoming = $challenge->getOpponentUid() === $viewerUid;
            $isOutgoing = $challenge->getChallengerUid() === $viewerUid;
            if (($incoming && !$isIncoming) || (!$incoming && !$isOutgoing)) {
                continue;
            }

            $cards[] = [
                'id' => $challenge->getId(),
                'stage' => $challenge->getStage(),
                'round_number' => $challenge->getRoundNumber(),
                'status' => $challenge->getStatus(),
                'challenger_uid' => $challenge->getChallengerUid(),
                'opponent_uid' => $challenge->getOpponentUid(),
                'deadline_at' => $challenge->getDeadlineAt(),
                'duel_code' => $this->getDuelCode($challenge->getDuelSessionId()),
                'can_accept' => $isIncoming && $challenge->getStatus() === 'open',
                'can_open_duel' => $challenge->getStatus() === 'accepted' && $challenge->getDuelSessionId() !== null,
                'title' => $challenge->getStage() === 'cl'
                    ? 'Champions League ' . ($challenge->getRoundNumber() === 2 ? 'Finale' : 'Halbfinale')
                    : 'Liga-Duell',
            ];
        }

        usort($cards, static function (array $left, array $right): int {
            return [$left['status'], $left['deadline_at']] <=> [$right['status'], $right['deadline_at']];
        });

        return $cards;
    }

    private function buildChampionsLeague(LeagueSeason $season, string $viewerUid): ?array {
        $clChallenges = array_values(array_filter(
            $this->challengeMapper->findBySeason($season->getId()),
            static fn (LeagueChallenge $challenge): bool => $challenge->getStage() === 'cl'
        ));

        if (count($clChallenges) === 0) {
            $qualifiedPlayers = $this->getQualifiedPlayers($season);
            if (count($qualifiedPlayers) < 4) {
                return [
                    'status' => 'unavailable',
                    'message' => 'Weniger als 4 aktive Spieler haben Liga-Duelle gespielt.',
                    'rounds' => [],
                    'qualified_players' => $qualifiedPlayers,
                ];
            }

            return [
                'status' => $season->getStatus() === 'finished' ? 'pending' : 'locked',
                'rounds' => [],
                'qualified_players' => $qualifiedPlayers,
            ];
        }

        $rounds = [];
        foreach ([1 => 'Halbfinale', 2 => 'Finale'] as $roundNumber => $title) {
            $roundMatches = [];
            foreach ($clChallenges as $challenge) {
                if ((int)$challenge->getRoundNumber() !== $roundNumber) {
                    continue;
                }
                $roundMatches[] = [
                    'id' => $challenge->getId(),
                    'title' => $title,
                    'status' => $challenge->getStatus(),
                    'challenger_uid' => $challenge->getChallengerUid(),
                    'opponent_uid' => $challenge->getOpponentUid(),
                    'winner_uid' => $challenge->getWinnerUid(),
                    'duel_code' => $this->getDuelCode($challenge->getDuelSessionId()),
                    'can_accept' => $challenge->getOpponentUid() === $viewerUid && $challenge->getStatus() === 'open',
                    'can_open_duel' => $challenge->getStatus() === 'accepted' && $challenge->getDuelSessionId() !== null,
                    'deadline_at' => $challenge->getDeadlineAt(),
                ];
            }
            $rounds[] = [
                'round_number' => $roundNumber,
                'title' => $title,
                'matches' => $roundMatches,
            ];
        }

        $finalChallenge = null;
        foreach ($clChallenges as $challenge) {
            if ((int)$challenge->getRoundNumber() === 2) {
                $finalChallenge = $challenge;
                break;
            }
        }

        return [
            'status' => $finalChallenge && in_array($finalChallenge->getStatus(), ['finished', 'forfeit'], true)
                ? 'finished'
                : 'active',
            'champion_uid' => $finalChallenge ? $finalChallenge->getWinnerUid() : null,
            'rounds' => $rounds,
            'qualified_players' => $this->getQualifiedPlayers($season),
        ];
    }

    private function getQualifiedPlayers(LeagueSeason $season): array {
        $rows = $this->buildStandings($season, '');
        $qualified = array_values(array_filter($rows, static fn (array $row): bool => $row['played'] > 0));
        return array_slice($qualified, 0, 4);
    }

    private function createChampionsLeague(LeagueSeason $season): void {
        $existingCl = array_filter(
            $this->challengeMapper->findBySeason($season->getId()),
            static fn (LeagueChallenge $challenge): bool => $challenge->getStage() === 'cl'
        );
        if (!empty($existingCl)) {
            return;
        }

        $qualified = $this->getQualifiedPlayers($season);
        if (count($qualified) < 4) {
            return;
        }

        $pairs = [
            [$qualified[0]['user_id'], $qualified[3]['user_id']],
            [$qualified[1]['user_id'], $qualified[2]['user_id']],
        ];

        foreach ($pairs as [$challengerUid, $opponentUid]) {
            $challenge = new LeagueChallenge();
            $challenge->setSeasonId($season->getId());
            $challenge->setStage('cl');
            $challenge->setRoundNumber(1);
            $challenge->setChallengerUid($challengerUid);
            $challenge->setOpponentUid($opponentUid);
            $challenge->setDuelSessionId(null);
            $challenge->setStatus('open');
            $challenge->setCreatedAt(time());
            $challenge->setAcceptedAt(null);
            $challenge->setDeadlineAt(time() + (self::CHALLENGE_TIMEOUT_DAYS * 86400));
            $challenge->setWinnerUid(null);
            $challenge->setPlayedAt(null);
            $this->challengeMapper->insert($challenge);
        }
    }

    private function ensureFinalRound(LeagueSeason $season): void {
        $semifinals = [];
        $finalExists = false;
        foreach ($this->challengeMapper->findBySeason($season->getId()) as $challenge) {
            if ($challenge->getStage() !== 'cl') {
                continue;
            }
            if ((int)$challenge->getRoundNumber() === 1 && in_array($challenge->getStatus(), ['finished', 'forfeit'], true)) {
                $semifinals[] = $challenge;
            }
            if ((int)$challenge->getRoundNumber() === 2) {
                $finalExists = true;
            }
        }

        if ($finalExists || count($semifinals) < 2) {
            return;
        }

        $standings = $this->buildStandings($season, '');
        $rankMap = [];
        foreach ($standings as $row) {
            $rankMap[$row['user_id']] = $row['rank'];
        }

        $winners = array_map(static fn (LeagueChallenge $challenge): string => $challenge->getWinnerUid(), $semifinals);
        usort($winners, static fn (string $left, string $right): int => ($rankMap[$left] ?? 999) <=> ($rankMap[$right] ?? 999));

        $challenge = new LeagueChallenge();
        $challenge->setSeasonId($season->getId());
        $challenge->setStage('cl');
        $challenge->setRoundNumber(2);
        $challenge->setChallengerUid($winners[0]);
        $challenge->setOpponentUid($winners[1]);
        $challenge->setDuelSessionId(null);
        $challenge->setStatus('open');
        $challenge->setCreatedAt(time());
        $challenge->setAcceptedAt(null);
        $challenge->setDeadlineAt(time() + (self::CHALLENGE_TIMEOUT_DAYS * 86400));
        $challenge->setWinnerUid(null);
        $challenge->setPlayedAt(null);
        $this->challengeMapper->insert($challenge);
    }

    private function assertPairingAvailable(LeagueSeason $season, string $userA, string $userB, string $stage): void {
        $pair = [$userA, $userB];
        sort($pair);

        foreach ($this->getSeasonResults($season, $stage) as $result) {
            $resultPair = [$result->getPlayerAUid(), $result->getPlayerBUid()];
            sort($resultPair);
            if ($resultPair === $pair) {
                throw new \RuntimeException('This matchup has already been played in the current season');
            }
        }

        foreach ($this->challengeMapper->findBySeason($season->getId()) as $challenge) {
            if ($challenge->getStage() !== $stage) {
                continue;
            }
            if (!in_array($challenge->getStatus(), ['open', 'accepted'], true)) {
                continue;
            }
            $challengePair = [$challenge->getChallengerUid(), $challenge->getOpponentUid()];
            sort($challengePair);
            if ($challengePair === $pair) {
                throw new \RuntimeException('There is already an open challenge for this matchup');
            }
        }
    }

    private function createLeagueDuel(LeagueSeason $season, LeagueChallenge $challenge): DuelSession {
        $poolId = $challenge->getStage() === 'cl' ? (int)$season->getClPoolId() : (int)$season->getPoolId();
        $numQuestions = $challenge->getStage() === 'cl' ? (int)$season->getClNumQuestions() : (int)$season->getNumQuestions();
        $questionIds = $this->selectQuestions($poolId, $numQuestions);
        if (count($questionIds) === 0) {
            throw new \RuntimeException('Selected league pool has no eligible duel questions');
        }

        $duel = new DuelSession();
        $duel->setCode($this->generateCode());
        $duel->setCreatorUid($challenge->getChallengerUid());
        $duel->setOpponentUid($challenge->getOpponentUid());
        $duel->setPoolId($poolId);
        $duel->setQuestionIds(json_encode($questionIds));
        $duel->setStatus('ready');
        $duel->setCurrentQuestionIndex(0);
        $duel->setCreatorScore(0);
        $duel->setOpponentScore(0);
        $duel->setCreatorReady(false);
        $duel->setOpponentReady(false);
        $duel->setCreatorLastPoll(null);
        $duel->setOpponentLastPoll(null);
        $duel->setCreatedAt(time());
        $duel->setLeagueSeasonId($season->getId());
        $duel->setLeagueChallengeId($challenge->getId());
        $duel = $this->duelSessionMapper->insert($duel);

        $challenge->setDuelSessionId($duel->getId());
        $challenge->setAcceptedAt(time());
        $challenge->setDeadlineAt(time() + (self::CHALLENGE_TIMEOUT_DAYS * 86400));
        $challenge->setStatus('accepted');
        $this->challengeMapper->update($challenge);

        return $duel;
    }

    private function selectQuestions(int $poolId, int $numQuestions): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('q.id')
            ->from('learning_questions', 'q')
            ->innerJoin('q', 'learning_answers', 'a', $qb->expr()->eq('a.question_id', 'q.id'))
            ->where($qb->expr()->eq('q.pool_id', $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $questionIds = [];
        while ($row = $result->fetch()) {
            $questionIds[] = (int)$row['id'];
        }
        $result->closeCursor();

        shuffle($questionIds);
        return array_slice($questionIds, 0, $numQuestions);
    }

    private function generateCode(): string {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = bin2hex(random_bytes(3));
            try {
                $this->duelSessionMapper->findByCode($code);
            } catch (DoesNotExistException) {
                return $code;
            }
        }

        throw new \RuntimeException('Could not generate unique duel code');
    }

    private function applyForfeits(LeagueSeason $season): void {
        foreach ($this->challengeMapper->findBySeason($season->getId()) as $challenge) {
            if (!in_array($challenge->getStatus(), ['open', 'accepted'], true)) {
                continue;
            }
            if ((int)$challenge->getDeadlineAt() > time()) {
                continue;
            }

            $this->recordResult(
                $season,
                $challenge,
                $challenge->getDuelSessionId(),
                0,
                0,
                time(),
                true
            );
        }
    }

    private function recordResult(
        LeagueSeason $season,
        LeagueChallenge $challenge,
        ?int $duelSessionId,
        int $scoreA,
        int $scoreB,
        int $playedAt,
        bool $isForfeit
    ): void {
        if ($this->hasResultForChallenge($challenge->getId())) {
            return;
        }

        $result = new LeagueResult();
        $result->setSeasonId($season->getId());
        $result->setChallengeId($challenge->getId());
        $result->setDuelSessionId($duelSessionId);
        $result->setStage($challenge->getStage());
        $result->setRoundNumber($challenge->getRoundNumber());
        $result->setPlayerAUid($challenge->getChallengerUid());
        $result->setPlayerBUid($challenge->getOpponentUid());
        $result->setScoreA($scoreA);
        $result->setScoreB($scoreB);
        $result->setPlayedAt($playedAt);

        if ($challenge->getStage() === 'league') {
            if ($isForfeit || $scoreA > $scoreB) {
                $result->setLeaguePointsA(3);
                $result->setLeaguePointsB(0);
                $result->setWinnerUid($challenge->getChallengerUid());
            } elseif ($scoreB > $scoreA) {
                $result->setLeaguePointsA(0);
                $result->setLeaguePointsB(3);
                $result->setWinnerUid($challenge->getOpponentUid());
            } else {
                $result->setLeaguePointsA(1);
                $result->setLeaguePointsB(1);
                $result->setWinnerUid(null);
            }
        } else {
            $result->setLeaguePointsA(0);
            $result->setLeaguePointsB(0);
            $result->setWinnerUid(
                $scoreA >= $scoreB || $isForfeit
                    ? $challenge->getChallengerUid()
                    : $challenge->getOpponentUid()
            );
        }

        $this->resultMapper->insert($result);

        $challenge->setWinnerUid($result->getWinnerUid());
        $challenge->setPlayedAt($playedAt);
        $challenge->setStatus($isForfeit ? 'forfeit' : 'finished');
        $this->challengeMapper->update($challenge);

        if ($isForfeit && $duelSessionId !== null) {
            try {
                $duel = $this->duelSessionMapper->findById($duelSessionId);
                if (!in_array($duel->getStatus(), ['finished', 'expired'], true)) {
                    $duel->setStatus('expired');
                    $this->duelSessionMapper->update($duel);
                }
            } catch (\Throwable) {
            }
        }

        if ($challenge->getStage() === 'cl' && (int)$challenge->getRoundNumber() === 1) {
            $this->ensureFinalRound($season);
        }
    }

    private function hasResultForChallenge(int $challengeId): bool {
        foreach ($this->resultMapper->findBySeason($this->challengeMapper->findById($challengeId)->getSeasonId()) as $result) {
            if ((int)$result->getChallengeId() === $challengeId) {
                return true;
            }
        }
        return false;
    }

    private function getSeasonResults(LeagueSeason $season, string $stage): array {
        return array_values(array_filter(
            $this->resultMapper->findBySeason($season->getId()),
            static fn (LeagueResult $result): bool => $result->getStage() === $stage
        ));
    }

    private function getDuelCode(?int $duelSessionId): ?string {
        if ($duelSessionId === null) {
            return null;
        }

        try {
            return $this->duelSessionMapper->findById($duelSessionId)->getCode();
        } catch (\Throwable) {
            return null;
        }
    }
}
