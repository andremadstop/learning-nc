<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCA\Learning\Db\GameshowAnswer;
use OCA\Learning\Db\GameshowAnswerMapper;
use OCA\Learning\Db\GameshowPlayer;
use OCA\Learning\Db\GameshowPlayerMapper;
use OCA\Learning\Db\GameshowSession;
use OCA\Learning\Db\GameshowSessionMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCA\Learning\Service\QuestionService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class GameshowService {
    private GameshowSessionMapper $sessionMapper;
    private GameshowPlayerMapper $playerMapper;
    private GameshowAnswerMapper $answerMapper;
    private IDBConnection $db;
    private LoggerInterface $logger;
    private TranslationService $translationService;
    private IConfig $config;
    private CourseService $courseService;
    private IUserManager $userManager;
    private XpService $xpService;
    private StreakService $streakService;
    private QuestionService $questionService;

    /** Inactivity timeout in seconds */
    private const TIMEOUT_SECONDS = 30;

    /** Session with all players inactive for this long is considered abandoned */
    private const STALE_SESSION_TIMEOUT = 300; // 5 minutes

    public function __construct(
        GameshowSessionMapper $sessionMapper,
        GameshowPlayerMapper $playerMapper,
        GameshowAnswerMapper $answerMapper,
        IDBConnection $db,
        LoggerInterface $logger,
        TranslationService $translationService,
        IConfig $config,
        CourseService $courseService,
        IUserManager $userManager,
        XpService $xpService,
        StreakService $streakService,
        QuestionService $questionService
    ) {
        $this->sessionMapper = $sessionMapper;
        $this->playerMapper = $playerMapper;
        $this->answerMapper = $answerMapper;
        $this->db = $db;
        $this->logger = $logger;
        $this->translationService = $translationService;
        $this->config = $config;
        $this->courseService = $courseService;
        $this->userManager = $userManager;
        $this->xpService = $xpService;
        $this->streakService = $streakService;
        $this->questionService = $questionService;
    }

    /** Valid game modes, including board-game extensions */
    private const VALID_MODES = ['sprint', 'elimination', 'lernwuerfel', 'wissensturm'];

    /** Board positions for special fields in Lernwürfel (0-indexed field numbers, 1-30) */
    private const LERNWUERFEL_SPECIAL_FIELDS = [
        5  => 'bonus_roll',  // ★ Extra roll
        10 => 'shield',      // ★ Protection (cannot be sent back for 1 round)
        15 => 'trap',        // ★ Skip next turn
        20 => 'bonus_roll',
        25 => 'shield',
    ];

    /** Number of board fields for Lernwürfel */
    private const LERNWUERFEL_BOARD_SIZE = 30;

    /** Number of category blocks needed to win Wissensturm */
    private const WISSENSTURM_BLOCKS_TO_WIN = 5;

    // ---------- Public API ----------

    /**
     * SESS-01: Create a new gameshow session.
     */
    public function createSession(int $poolId, string $userId, string $mode, int $maxPlayers = 5, ?int $courseId = null): array {
        if (!in_array($mode, self::VALID_MODES, true)) {
            throw new \RuntimeException('Invalid mode: must be sprint, elimination, lernwuerfel, or wissensturm');
        }
        $maxPlayers = max(2, min(5, $maxPlayers));

        $questionIds = $this->selectQuestions($poolId, 15, $userId, $courseId);
        $code = $this->generateCode();
        $now = time();

        $session = new GameshowSession();
        $session->setCode($code);
        $session->setPoolId($poolId);
        $session->setCourseId($courseId);
        $session->setMode($mode);
        $session->setStatus('waiting');
        $session->setMaxPlayers($maxPlayers);
        $session->setMinPlayers(2);
        $session->setQuestionIds(json_encode($questionIds));
        $session->setCurrentQuestionIndex(0);
        $session->setNumQuestions(count($questionIds));
        $session->setCreatedAt($now);
        $session->setStartedAt(null);
        $session->setCreatorUid($userId);
        $session->setBoardState(null);

        $session = $this->sessionMapper->insert($session);

        // Create player row for the creator (slot 0)
        $player = new GameshowPlayer();
        $player->setSessionId($session->getId());
        $player->setUserId($userId);
        $player->setSlot(0);
        $player->setDisplayName($this->resolveDisplayName($userId));
        $player->setScore(0);
        $player->setLives(3);
        $player->setIsReady(false);
        $player->setIsRemoved(false);
        $player->setLastPoll($now);
        $player->setJoinedAt($now);
        $this->playerMapper->insert($player);

        return $this->buildState($session, $userId);
    }

    /**
     * SESS-02: Join an existing session via code.
     */
    public function joinSession(string $code, string $userId): array {
        $session = $this->sessionMapper->findByCode($code);

        if ($session->getStatus() !== 'waiting') {
            throw new \RuntimeException('Session is no longer open for joining');
        }

        // Check not already joined
        try {
            $this->playerMapper->findBySessionAndUser($session->getId(), $userId);
            throw new \RuntimeException('You have already joined this session');
        } catch (DoesNotExistException $e) {
            // Good -- player not yet in session
        }

        // Check not full (count active, non-removed players)
        $players = $this->playerMapper->findBySession($session->getId());
        $activeCount = 0;
        foreach ($players as $p) {
            if (!$p->getIsRemoved()) {
                $activeCount++;
            }
        }
        if ($activeCount >= $session->getMaxPlayers()) {
            throw new \RuntimeException('Session is full');
        }

        $now = time();
        $slot = count($players); // Next slot

        $player = new GameshowPlayer();
        $player->setSessionId($session->getId());
        $player->setUserId($userId);
        $player->setSlot($slot);
        $player->setDisplayName($this->resolveDisplayName($userId));
        $player->setScore(0);
        $player->setLives(3);
        $player->setIsReady(false);
        $player->setIsRemoved(false);
        $player->setLastPoll($now);
        $player->setJoinedAt($now);

        try {
            $this->playerMapper->insert($player);
        } catch (DbException $e) {
            // Race condition: unique constraint violation means they already joined
            throw new \RuntimeException('You have already joined this session');
        }

        return $this->buildState($session, $userId);
    }

    /**
     * SESS-04: Set player ready; auto-start when all ready.
     */
    public function setReady(string $code, string $userId, ?string $lang = null): array {
        $session = $this->sessionMapper->findByCode($code);

        if ($session->getStatus() !== 'waiting') {
            throw new \RuntimeException('Session is not in waiting state');
        }

        $player = $this->playerMapper->findBySessionAndUser($session->getId(), $userId);
        $player->setIsReady(true);
        $this->playerMapper->update($player);

        // Check if all non-removed players are ready AND count >= minPlayers
        $players = $this->playerMapper->findBySession($session->getId());
        $activePlayers = [];
        $allReady = true;
        foreach ($players as $p) {
            if (!$p->getIsRemoved()) {
                $activePlayers[] = $p;
                if (!$p->getIsReady()) {
                    $allReady = false;
                }
            }
        }

        if ($allReady && count($activePlayers) >= $session->getMinPlayers()) {
            if ($session->getMode() === 'elimination') {
                $this->applySuddenDeathIfNeeded($activePlayers);
            }
            $session->setStatus('active');
            $session->setStartedAt(time());
            $session->setQuestionStartedAt(intval(microtime(true) * 1000));

            // Initialise board state for board-game modes
            if (in_array($session->getMode(), ['lernwuerfel', 'wissensturm'], true)) {
                $session->setBoardState(json_encode($this->initBoardState($session->getMode(), $activePlayers)));
            }

            $session = $this->sessionMapper->update($session);
        }

        return $this->buildState($session, $userId, $lang);
    }

    /**
     * SESS-03: List open sessions for a course.
     */
    public function getCourseLobby(int $courseId, string $userId): array {
        // Verify user is member of course (throws if not)
        $this->courseService->findById($courseId, $userId);

        $sessions = $this->sessionMapper->findOpenByCourse($courseId);
        $result = [];
        foreach ($sessions as $session) {
            $players = $this->playerMapper->findBySession($session->getId());
            $activeCount = 0;
            $creatorName = '';
            foreach ($players as $p) {
                if (!$p->getIsRemoved()) {
                    $activeCount++;
                }
                if ($p->getSlot() === 0) {
                    $creatorName = $p->getDisplayName();
                }
            }
            $result[] = [
                'code' => $session->getCode(),
                'mode' => $session->getMode(),
                'max_players' => $session->getMaxPlayers(),
                'player_count' => $activeCount,
                'creator_display_name' => $creatorName,
            ];
        }

        return $result;
    }

    /**
     * SESS-05, SESS-06: Get full session state, update poll timestamp.
     */
    public function getState(string $code, string $userId, ?string $lang = null): array {
        $session = $this->sessionMapper->findByCode($code);
        $players = $this->playerMapper->findBySession($session->getId());

        // Check for completely abandoned session (all players inactive for 5+ minutes)
        $session = $this->checkStaleSession($session, $players);
        if ($session->getStatus() === 'expired') {
            // Re-fetch players for accurate state
            $players = $this->playerMapper->findBySession($session->getId());
            // Update current user's last_poll even for expired (graceful state build)
            foreach ($players as $p) {
                if ($p->getUserId() === $userId) {
                    $p->setLastPoll(time());
                    $this->playerMapper->update($p);
                    break;
                }
            }
            return $this->buildState($session, $userId, $lang);
        }

        // Verify current user is a participant
        $isParticipant = false;
        foreach ($players as $p) {
            if ($p->getUserId() === $userId) {
                $isParticipant = true;
                // Update lastPoll for current user
                $p->setLastPoll(time());
                $this->playerMapper->update($p);
                break;
            }
        }
        if (!$isParticipant) {
            throw new \RuntimeException('You are not a participant in this session');
        }

        // Check for timed-out players
        $this->checkTimeouts($session, $players, $userId);

        return $this->buildState($session, $userId, $lang);
    }

    /**
     * Submit an answer for the current question.
     */
    public function submitAnswer(string $code, string $userId, int $answerId, int $answeredAt, ?string $lang = null): array {
        $session = $this->sessionMapper->findByCode($code);

        if ($session->getStatus() !== 'active') {
            throw new \RuntimeException('Session is not active');
        }

        // Verify player is participant and not removed
        $player = $this->playerMapper->findBySessionAndUser($session->getId(), $userId);
        if ($player->getIsRemoved()) {
            throw new \RuntimeException('You have been removed from this session');
        }
        if ($session->getMode() === 'elimination' && $player->getLives() <= 0) {
            throw new \RuntimeException('You have been eliminated from this session');
        }

        // Board-game modes: enforce turn order — only active player may answer
        if (in_array($session->getMode(), ['lernwuerfel', 'wissensturm'], true)) {
            $boardState = json_decode($session->getBoardState() ?? '{}', true);
            $activePlayerIndex = $boardState['active_player_index'] ?? 0;
            $boardPhase = $boardState['phase'] ?? 'roll';

            if ($boardPhase !== 'question') {
                throw new \RuntimeException('It is not the question phase — please roll the dice first');
            }
            if ($player->getSlot() !== $activePlayerIndex) {
                throw new \RuntimeException('It is not your turn');
            }
        }

        $questionIndex = $session->getCurrentQuestionIndex();
        $questionIds = json_decode($session->getQuestionIds(), true) ?? [];

        if ($questionIndex >= count($questionIds)) {
            throw new \RuntimeException('No more questions');
        }

        $questionId = (int)$questionIds[$questionIndex];

        // Check if player already answered this question
        $existingAnswers = $this->answerMapper->findBySessionAndQuestion($session->getId(), $questionIndex);
        foreach ($existingAnswers as $existing) {
            if ($existing->getPlayerUid() === $userId) {
                throw new \RuntimeException('You already answered this question');
            }
        }

        // Validate answer server-side
        $qb = $this->db->getQueryBuilder();
        $qb->select('is_correct')
           ->from('learning_answers')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($answerId, IQueryBuilder::PARAM_INT)));
        $res = $qb->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();
        if ($row === false) {
            throw new \RuntimeException('Invalid answer');
        }
        $isCorrect = (bool)$row['is_correct'];

        // Insert answer record
        $answer = new GameshowAnswer();
        $answer->setSessionId($session->getId());
        $answer->setQuestionIndex($questionIndex);
        $answer->setPlayerUid($userId);
        $answer->setAnswerId($answerId);
        $answer->setIsCorrect($isCorrect);
        $answer->setAnsweredAt($answeredAt);
        $answer->setPointsEarned(0); // Scoring handled by mode-specific logic in Phase 9/10
        $this->answerMapper->insert($answer);

        // Determine correct answer ID for feedback
        $correctAnswerId = $this->questionService->getCorrectAnswerIdForGame($questionId);

        // Check if ALL active (non-removed) players have answered this question
        $players = $this->playerMapper->findBySession($session->getId());
        $activePlayers = $this->getActiveGameplayPlayers($session, $players);

        $answersForQuestion = $this->answerMapper->findBySessionAndQuestion($session->getId(), $questionIndex);
        $answeredUserIds = [];
        foreach ($answersForQuestion as $a) {
            $answeredUserIds[$a->getPlayerUid()] = true;
        }

        $allAnswered = true;
        foreach ($activePlayers as $ap) {
            if (!isset($answeredUserIds[$ap->getUserId()])) {
                $allAnswered = false;
                break;
            }
        }

        // Board-game modes handle scoring and turn rotation immediately (single-player-per-turn)
        if (in_array($session->getMode(), ['lernwuerfel', 'wissensturm'], true)) {
            $boardState = json_decode($session->getBoardState() ?? '{}', true);

            if ($session->getMode() === 'lernwuerfel') {
                $finished = $this->lernwuerfelScoring($session, $player, $isCorrect, $boardState);
            } else {
                $finished = $this->wissensturmScoring($session, $player, $isCorrect, $boardState);
            }

            if ($finished) {
                $session->setStatus('finished');
                $this->awardGameshowXp($session);
            } else {
                // Advance to next player's roll phase
                $this->advanceTurn($session, $boardState, $players);
                $session->setBoardState(json_encode($boardState));
                // Rotate question for next player (re-use question pool round-robin)
                $nextIndex = ($questionIndex + 1) % count($questionIds);
                $session->setCurrentQuestionIndex($nextIndex);
                $session->setQuestionStartedAt(intval(microtime(true) * 1000));
            }
            $session = $this->sessionMapper->update($session);
        } elseif ($allAnswered) {
            // Score this question before advancing (mode-specific)
            if ($session->getMode() === 'sprint') {
                $this->sprintScoring($session, $questionIndex);
            } elseif ($session->getMode() === 'elimination') {
                $activePlayers = $this->eliminationScoring($session, $questionIndex);
            }

            $nextIndex = $questionIndex + 1;
            if (($session->getMode() === 'elimination' && count($activePlayers) <= 1) || $nextIndex >= $session->getNumQuestions()) {
                $session->setStatus('finished');
                $this->awardGameshowXp($session);
            } else {
                $session->setCurrentQuestionIndex($nextIndex);
                $session->setQuestionStartedAt(intval(microtime(true) * 1000));
            }
            $session = $this->sessionMapper->update($session);
        }

        $state = $this->buildState($session, $userId, $lang);
        $state['correct_answer_id'] = $correctAnswerId;
        $state['my_answer_correct'] = $isCorrect;
        return $state;
    }

    /**
     * Check for timed-out players and expire session if needed.
     */
    private function checkTimeouts(GameshowSession $session, array &$players, string $currentUserId): void {
        // Only check timeouts during active games, not while waiting in lobby
        if ($session->getStatus() !== 'active') {
            return;
        }

        $cutoff = time() - self::TIMEOUT_SECONDS;
        $activeCount = 0;

        foreach ($players as $player) {
            if ($player->getIsRemoved()) {
                continue;
            }

            // Don't timeout the current user (they're actively polling)
            if ($player->getUserId() === $currentUserId) {
                $activeCount++;
                continue;
            }

            $lastPoll = $player->getLastPoll();
            // Only timeout if they polled at least once and then stopped
            if ($lastPoll !== null && $lastPoll < $cutoff) {
                $player->setIsRemoved(true);
                $this->playerMapper->update($player);
                $this->logger->info('GameshowService: Player ' . $player->getUserId() . ' timed out from session ' . $session->getCode());
            } else {
                $activeCount++;
            }
        }

        // Only expire if we drop below minimum during active game
        if ($activeCount < $session->getMinPlayers()) {
            $session->setStatus('expired');
            $this->sessionMapper->update($session);
        }
    }

    /**
     * Check if all active players have been inactive for STALE_SESSION_TIMEOUT and expire if so.
     *
     * @param GameshowPlayer[] $players
     */
    private function checkStaleSession(GameshowSession $session, array $players): GameshowSession {
        // Only check active sessions
        if ($session->getStatus() !== 'active') {
            return $session;
        }

        $cutoff = time() - self::STALE_SESSION_TIMEOUT;
        $hasRecentActivity = false;

        foreach ($players as $player) {
            if ($player->getIsRemoved()) {
                continue;
            }
            $lastPoll = $player->getLastPoll();
            // If any active player polled recently, session is not stale
            if ($lastPoll !== null && $lastPoll >= $cutoff) {
                $hasRecentActivity = true;
                break;
            }
        }

        if (!$hasRecentActivity) {
            // Check that at least one player has polled ever (avoid expiring brand-new sessions)
            $anyPolled = false;
            foreach ($players as $player) {
                if (!$player->getIsRemoved() && $player->getLastPoll() !== null) {
                    $anyPolled = true;
                    break;
                }
            }

            if ($anyPolled) {
                $session->setStatus('expired');
                $session = $this->sessionMapper->update($session);
                $this->logger->info('GameshowService: Session ' . $session->getCode() . ' expired due to all-player inactivity');
            }
        }

        return $session;
    }

    /**
     * Build full state response for a session.
     */
    private function buildState(GameshowSession $session, string $userId, ?string $lang = null): array {
        $players = $this->playerMapper->findBySession($session->getId());
        $questionIds = json_decode($session->getQuestionIds(), true) ?? [];
        $currentIndex = $session->getCurrentQuestionIndex();
        $status = $session->getStatus();
        $contentLanguage = $this->resolveContentLanguage($userId, $lang);
        $activeGameplayPlayers = $this->getActiveGameplayPlayers($session, $players);
        $activePlayerCount = count($activeGameplayPlayers);
        $winner = $session->getMode() === 'elimination' && $activePlayerCount === 1 ? reset($activeGameplayPlayers) : null;

        // Build players array
        $mySlot = null;
        $playerData = [];

        // Check which players have answered current question
        $answeredPlayers = [];
        if ($status === 'active' && $currentIndex < count($questionIds)) {
            $answersForQuestion = $this->answerMapper->findBySessionAndQuestion($session->getId(), $currentIndex);
            foreach ($answersForQuestion as $a) {
                $answeredPlayers[$a->getPlayerUid()] = true;
            }
        }

        foreach ($players as $p) {
            if ($p->getUserId() === $userId) {
                $mySlot = $p->getSlot();
            }
            $isEliminated = $session->getMode() === 'elimination' && !$p->getIsRemoved() && $p->getLives() <= 0;
            $playerData[] = [
                'user_id' => $p->getUserId(),
                'display_name' => $p->getDisplayName(),
                'slot' => $p->getSlot(),
                'score' => $p->getScore(),
                'lives' => $p->getLives(),
                'is_eliminated' => $isEliminated,
                'is_ready' => (bool)$p->getIsReady(),
                'is_removed' => (bool)$p->getIsRemoved(),
                'answered' => isset($answeredPlayers[$p->getUserId()]),
            ];
        }

        // Load current question (only when active)
        $currentQuestion = null;
        if ($status === 'active' && $currentIndex < count($questionIds)) {
            $questionId = (int)$questionIds[$currentIndex];
            $currentQuestion = $this->questionService->loadQuestionForGame($questionId, $contentLanguage);
        }

        // Decode board state for board-game modes
        $boardStateData = null;
        if (in_array($session->getMode(), ['lernwuerfel', 'wissensturm'], true) && $session->getBoardState() !== null) {
            $boardStateData = json_decode($session->getBoardState(), true);
        }

        return [
            'id' => $session->getId(),
            'code' => $session->getCode(),
            'status' => $status,
            'mode' => $session->getMode(),
            'max_players' => $session->getMaxPlayers(),
            'min_players' => $session->getMinPlayers(),
            'players' => $playerData,
            'active_player_count' => $activePlayerCount,
            'sudden_death' => $session->getMode() === 'elimination' && $activePlayerCount === 2,
            'current_question_index' => $currentIndex,
            'total_questions' => $session->getNumQuestions(),
            'current_question' => $currentQuestion,
            'my_slot' => $mySlot,
            'question_started_at' => $session->getQuestionStartedAt(),
            'winner_user_id' => $winner instanceof GameshowPlayer ? $winner->getUserId() : null,
            'board_state' => $boardStateData,
        ];
    }

    // ---------- Scoring ----------

    /**
     * Sprint scoring: 500 + time bonus for fastest correct, tiered 300/200/100 for subsequent.
     * Wrong answers earn 0 points.
     */
    private function sprintScoring(GameshowSession $session, int $questionIndex): void {
        $answers = $this->answerMapper->findBySessionAndQuestion($session->getId(), $questionIndex);
        $questionStartedAt = $session->getQuestionStartedAt();

        // Separate correct answers, sort by answeredAt ASC (fastest first)
        $correctAnswers = [];
        foreach ($answers as $answer) {
            if ($answer->getIsCorrect()) {
                $correctAnswers[] = $answer;
            }
        }
        usort($correctAnswers, function ($a, $b) {
            return ($a->getAnsweredAt() ?? 0) - ($b->getAnsweredAt() ?? 0);
        });

        // Tiered base points: 1st=500, 2nd=300, 3rd=200, 4th+=100
        $tierPoints = [500, 300, 200, 100];

        foreach ($correctAnswers as $rank => $answer) {
            $base = $tierPoints[min($rank, count($tierPoints) - 1)];

            // Time bonus for first place: max 500 bonus points, linear decay over 15s
            $bonus = 0;
            if ($rank === 0 && $questionStartedAt !== null) {
                $elapsed = ($answer->getAnsweredAt() ?? 0) - $questionStartedAt;
                $bonus = (int)max(0, (15000 - $elapsed) / 15000 * 500);
            }

            $points = $base + $bonus;
            $answer->setPointsEarned($points);
            $this->answerMapper->update($answer);
        }

        // Update player scores (sum of all their points across all questions)
        $allAnswers = $this->answerMapper->findBySession($session->getId());
        $playerPoints = [];
        foreach ($allAnswers as $a) {
            $uid = $a->getPlayerUid();
            $playerPoints[$uid] = ($playerPoints[$uid] ?? 0) + $a->getPointsEarned();
        }

        $players = $this->playerMapper->findBySession($session->getId());
        foreach ($players as $player) {
            if (isset($playerPoints[$player->getUserId()])) {
                $player->setScore($playerPoints[$player->getUserId()]);
                $this->playerMapper->update($player);
            }
        }
    }

    /**
     * Elimination scoring: wrong answers cost one life. When only one active player remains, the session ends.
     *
     * @return GameshowPlayer[] Remaining active players after scoring
     */
    private function eliminationScoring(GameshowSession $session, int $questionIndex): array {
        $answers = $this->answerMapper->findBySessionAndQuestion($session->getId(), $questionIndex);
        $players = $this->playerMapper->findBySession($session->getId());

        $this->applySuddenDeathIfNeeded($players);
        $players = $this->playerMapper->findBySession($session->getId());

        $answersByUser = [];
        foreach ($answers as $answer) {
            $answersByUser[$answer->getPlayerUid()] = $answer;
        }

        foreach ($this->getActiveGameplayPlayers($session, $players) as $player) {
            $answer = $answersByUser[$player->getUserId()] ?? null;
            if ($answer !== null && !$answer->getIsCorrect()) {
                $nextLives = max(0, $player->getLives() - 1);
                $player->setLives($nextLives);
                $this->playerMapper->update($player);
            }
        }

        $updatedPlayers = $this->playerMapper->findBySession($session->getId());
        $remainingPlayers = $this->getActiveGameplayPlayers($session, $updatedPlayers);

        if (count($remainingPlayers) === 2) {
            $this->applySuddenDeathIfNeeded($updatedPlayers);
            $updatedPlayers = $this->playerMapper->findBySession($session->getId());
            $remainingPlayers = $this->getActiveGameplayPlayers($session, $updatedPlayers);
        }

        return $remainingPlayers;
    }

    /**
     * INTG-04: Get finished gameshow sessions for a user.
     *
     * @return array[] List of past sessions with scores and winner info
     */
    public function getHistory(string $userId, int $limit = 20): array {
        $sessions = $this->sessionMapper->findFinishedByUser($userId, $limit);
        $result = [];

        foreach ($sessions as $session) {
            $players = $this->playerMapper->findBySession($session->getId());

            $myScore = 0;
            $highestScore = 0;
            $winner = null;
            $playerData = [];

            foreach ($players as $p) {
                $pData = [
                    'user_id' => $p->getUserId(),
                    'display_name' => $p->getDisplayName(),
                    'score' => $p->getScore(),
                    'is_removed' => (bool)$p->getIsRemoved(),
                ];
                $playerData[] = $pData;

                if ($p->getUserId() === $userId) {
                    $myScore = $p->getScore();
                }

                if (!$p->getIsRemoved() && $p->getScore() > $highestScore) {
                    $highestScore = $p->getScore();
                    $winner = $p->getDisplayName();
                }
            }

            $result[] = [
                'id' => $session->getId(),
                'code' => $session->getCode(),
                'mode' => $session->getMode(),
                'created_at' => $session->getCreatedAt(),
                'total_questions' => $session->getNumQuestions(),
                'players' => $playerData,
                'my_score' => $myScore,
                'winner' => $winner,
            ];
        }

        return $result;
    }

    // ---------- Board-game public API ----------

    /**
     * BACK-03: Roll the dice for the active player in a board-game session.
     *
     * - Validates the caller is the active player and phase === 'roll'.
     * - Generates a random dice result (1-6).
     * - Handles 'trap' skip: if active player is trapped, advances turn automatically.
     * - Stores result in board_state, transitions phase to 'question'.
     * - Handles Lernwürfel rule: rolling a 6 grants an extra roll next time.
     *
     * @return array Full session state including updated board_state
     */
    public function rollDice(string $code, string $userId, ?string $lang = null): array {
        $session = $this->sessionMapper->findByCode($code);

        if ($session->getStatus() !== 'active') {
            throw new \RuntimeException('Session is not active');
        }
        if (!in_array($session->getMode(), ['lernwuerfel', 'wissensturm'], true)) {
            throw new \RuntimeException('rollDice is only available in board-game modes');
        }

        $player = $this->playerMapper->findBySessionAndUser($session->getId(), $userId);
        if ($player->getIsRemoved()) {
            throw new \RuntimeException('You have been removed from this session');
        }

        $boardState = json_decode($session->getBoardState() ?? '{}', true);
        $activePlayerIndex = $boardState['active_player_index'] ?? 0;

        if ($player->getSlot() !== $activePlayerIndex) {
            throw new \RuntimeException('It is not your turn');
        }

        $phase = $boardState['phase'] ?? 'roll';
        if ($phase !== 'roll') {
            throw new \RuntimeException('Dice already rolled — answer the question first');
        }

        // Handle trap (skip turn)
        $skipTurns = $boardState['skip_turns'] ?? [];
        $slotKey = (string)$activePlayerIndex;
        if (($skipTurns[$slotKey] ?? 0) > 0) {
            // Skip this turn — decrement trap counter and advance to next player
            $skipTurns[$slotKey]--;
            $boardState['skip_turns'] = $skipTurns;
            $boardState['dice_result'] = null;
            $boardState['special_effect'] = null;
            $players = $this->playerMapper->findBySession($session->getId());
            $this->advanceTurn($session, $boardState, $players);
            $session->setBoardState(json_encode($boardState));
            $session = $this->sessionMapper->update($session);

            $state = $this->buildState($session, $userId, $lang);
            $state['turn_skipped'] = true;
            return $state;
        }

        // Roll the dice
        $diceResult = random_int(1, 6);
        $boardState['dice_result'] = $diceResult;
        $boardState['phase'] = 'question';
        $boardState['special_effect'] = null;

        // For Wissensturm: category is chosen by player at answer time via selected_category
        if ($session->getMode() === 'wissensturm') {
            $boardState['steal_pending'] = false;
            $boardState['steal_from_slot'] = null;
        }

        // Select a question relevant to the active player (advance question pointer)
        $session->setBoardState(json_encode($boardState));
        $session->setQuestionStartedAt(intval(microtime(true) * 1000));
        $session = $this->sessionMapper->update($session);

        return $this->buildState($session, $userId, $lang);
    }

    /**
     * BACK-01, BACK-03: Select a Wissensturm category before answering.
     *
     * The active player may choose which category (pool_id) they want to answer
     * from. Only valid in the 'question' phase.
     *
     * @return array Full session state
     */
    public function selectCategory(string $code, string $userId, int $categoryPoolId, ?string $lang = null): array {
        $session = $this->sessionMapper->findByCode($code);

        if ($session->getStatus() !== 'active') {
            throw new \RuntimeException('Session is not active');
        }
        if ($session->getMode() !== 'wissensturm') {
            throw new \RuntimeException('selectCategory is only available in wissensturm mode');
        }

        $player = $this->playerMapper->findBySessionAndUser($session->getId(), $userId);
        $boardState = json_decode($session->getBoardState() ?? '{}', true);
        $activePlayerIndex = $boardState['active_player_index'] ?? 0;

        if ($player->getSlot() !== $activePlayerIndex) {
            throw new \RuntimeException('It is not your turn');
        }
        if (($boardState['phase'] ?? 'roll') !== 'question') {
            throw new \RuntimeException('Not in question phase');
        }

        // Load a question from the chosen pool/category
        $questionIds = $this->selectQuestions($categoryPoolId, 1, $userId);
        if (empty($questionIds)) {
            throw new \RuntimeException('No questions available for this category');
        }

        $boardState['selected_category'] = $categoryPoolId;
        // Override question for this turn — store the selected question id
        $boardState['current_question_override'] = $questionIds[0];
        $session->setBoardState(json_encode($boardState));
        $session->setQuestionStartedAt(intval(microtime(true) * 1000));
        $session = $this->sessionMapper->update($session);

        return $this->buildState($session, $userId, $lang);
    }

    // ---------- Board-game scoring (private) ----------

    /**
     * BACK-01, BACK-02: Lernwürfel scoring after an answer.
     *
     * - Correct: move figure forward by dice_result fields.
     *   - Landing on an occupied field: send opponent to start (field 0).
     *   - Landing on a special field: apply bonus_roll / shield / trap effect.
     *   - Rolling a 6 → extra roll (phase returns to 'roll' for same player).
     *   - Reaching field LERNWUERFEL_BOARD_SIZE → game finished.
     * - Incorrect: figure stays on current field.
     *
     * @param array &$boardState Mutable board-state array
     * @return bool true if session should finish (winner reached end)
     */
    private function lernwuerfelScoring(GameshowSession $session, GameshowPlayer $player, bool $isCorrect, array &$boardState): bool {
        $slot = $player->getSlot();
        $slotKey = (string)$slot;
        $positions = $boardState['positions'] ?? [];
        $shieldedSlots = $boardState['shielded_slots'] ?? [];
        $skipTurns = $boardState['skip_turns'] ?? [];
        $diceResult = $boardState['dice_result'] ?? 1;

        // Award score point for correct answer
        if ($isCorrect) {
            $player->setScore($player->getScore() + 1);
            $this->playerMapper->update($player);
        }

        // Move figure on correct answer
        if ($isCorrect) {
            $currentPos = $positions[$slotKey] ?? 0;
            $newPos = $currentPos + $diceResult;

            if ($newPos >= self::LERNWUERFEL_BOARD_SIZE) {
                // Player reached the end — win!
                $positions[$slotKey] = self::LERNWUERFEL_BOARD_SIZE;
                $boardState['positions'] = $positions;
                $session->setBoardState(json_encode($boardState));
                $this->sessionMapper->update($session);
                return true;
            }

            // Collision: send unshielded opponent on the same destination field to start
            foreach ($positions as $otherSlotKey => $otherPos) {
                if ($otherSlotKey === $slotKey) {
                    continue;
                }
                if ($otherPos === $newPos) {
                    // Check shield
                    if (!in_array((int)$otherSlotKey, $shieldedSlots, true)) {
                        $positions[$otherSlotKey] = 0;
                    }
                }
            }

            // Apply special field effect
            $specialEffect = self::LERNWUERFEL_SPECIAL_FIELDS[$newPos] ?? null;
            if ($specialEffect === 'shield') {
                // Player is protected for 1 landing
                if (!in_array($slot, $shieldedSlots, true)) {
                    $shieldedSlots[] = $slot;
                }
            } elseif ($specialEffect === 'trap') {
                // Player skips next turn
                $skipTurns[$slotKey] = ($skipTurns[$slotKey] ?? 0) + 1;
            }
            // bonus_roll: dice_result already applied, next turn stays with same player
            // (handled in advanceTurn — skip advance if bonus_roll)

            $positions[$slotKey] = $newPos;
            $boardState['special_effect'] = $specialEffect;
        } else {
            $boardState['special_effect'] = null;
        }

        // Remove shield once used (player landed here so opponent tried to collide — shield consumed)
        $shieldedSlots = array_values(array_filter($shieldedSlots, fn($s) => $s !== $slot || !$isCorrect));

        $boardState['positions'] = $positions;
        $boardState['shielded_slots'] = $shieldedSlots;
        $boardState['skip_turns'] = $skipTurns;

        return false;
    }

    /**
     * BACK-01, BACK-02: Wissensturm scoring after an answer.
     *
     * - Correct: add a block of selected_category colour to player's tower.
     *   - Opponent steal: if steal_pending was true, block moves from opponent's tower.
     *   - Winning condition: player has all WISSENSTURM_BLOCKS_TO_WIN unique colours.
     * - Incorrect: remove topmost block from player's tower (if any).
     *   - Set steal_pending=true so that if next player answers correctly they can steal.
     *
     * @param array &$boardState Mutable board-state array
     * @return bool true if session should finish (player collected all categories)
     */
    private function wissensturmScoring(GameshowSession $session, GameshowPlayer $player, bool $isCorrect, array &$boardState): bool {
        $slot = $player->getSlot();
        $slotKey = (string)$slot;
        $towers = $boardState['towers'] ?? [];
        $selectedCategory = $boardState['selected_category'] ?? null;
        $stealPending = $boardState['steal_pending'] ?? false;
        $stealFromSlot = $boardState['steal_from_slot'] ?? null;

        if (!isset($towers[$slotKey])) {
            $towers[$slotKey] = [];
        }

        if ($isCorrect) {
            if ($stealPending && $stealFromSlot !== null && (string)$stealFromSlot !== $slotKey) {
                // Steal: take topmost block from the player who gave steal opportunity
                $fromKey = (string)$stealFromSlot;
                if (!empty($towers[$fromKey])) {
                    $stolenBlock = array_pop($towers[$fromKey]);
                    $towers[$slotKey][] = $stolenBlock;
                }
                $boardState['steal_pending'] = false;
                $boardState['steal_from_slot'] = null;
            } elseif ($selectedCategory !== null) {
                // Add block for selected category
                $towers[$slotKey][] = (string)$selectedCategory;
            }

            // Award score point
            $player->setScore($player->getScore() + 1);
            $this->playerMapper->update($player);

            // Check win: player needs WISSENSTURM_BLOCKS_TO_WIN unique colours
            $uniqueColours = array_unique($towers[$slotKey]);
            if (count($uniqueColours) >= self::WISSENSTURM_BLOCKS_TO_WIN) {
                $boardState['towers'] = $towers;
                $session->setBoardState(json_encode($boardState));
                $this->sessionMapper->update($session);
                return true;
            }
        } else {
            // Incorrect: remove topmost block
            if (!empty($towers[$slotKey])) {
                array_pop($towers[$slotKey]);
            }
            // Enable steal for the next active player
            $boardState['steal_pending'] = true;
            $boardState['steal_from_slot'] = $slot;
        }

        $boardState['towers'] = $towers;
        $boardState['selected_category'] = null;
        return false;
    }

    /**
     * Advance turn to next active (non-removed) player.
     *
     * Handles Lernwürfel "bonus_roll" special effect — same player goes again.
     * Updates active_player_index in board_state and resets phase to 'roll'.
     *
     * @param GameshowPlayer[] $players All session players
     * @param array &$boardState Mutable board-state array
     */
    private function advanceTurn(GameshowSession $session, array &$boardState, array $players): void {
        $specialEffect = $boardState['special_effect'] ?? null;
        $currentIndex = $boardState['active_player_index'] ?? 0;

        // Lernwürfel bonus_roll: same player rolls again
        if ($session->getMode() === 'lernwuerfel' && $specialEffect === 'bonus_roll') {
            $boardState['phase'] = 'roll';
            $boardState['dice_result'] = null;
            $boardState['special_effect'] = null;
            return;
        }

        // Find next active slot (skip removed players)
        $activePlayers = array_values(array_filter($players, fn(GameshowPlayer $p) => !$p->getIsRemoved()));
        if (empty($activePlayers)) {
            return;
        }

        $slots = array_map(fn(GameshowPlayer $p) => $p->getSlot(), $activePlayers);
        sort($slots);

        // Find position of current slot in sorted list, advance to next
        $pos = array_search($currentIndex, $slots, true);
        if ($pos === false) {
            $nextSlot = $slots[0];
        } else {
            $nextSlot = $slots[($pos + 1) % count($slots)];
        }

        $boardState['active_player_index'] = $nextSlot;
        $boardState['phase'] = 'roll';
        $boardState['dice_result'] = null;
        $boardState['special_effect'] = null;
    }

    // ---------- Board-game board initialisation ----------

    /**
     * Build the initial board state for a board-game session.
     *
     * @param string $mode 'lernwuerfel' | 'wissensturm'
     * @param GameshowPlayer[] $activePlayers
     * @return array Board state array (will be JSON-encoded)
     */
    private function initBoardState(string $mode, array $activePlayers): array {
        $base = [
            'active_player_index' => 0, // Slot 0 (creator) goes first
            'phase' => 'roll',
            'dice_result' => null,
            'special_effect' => null,
            'skip_turns' => [],
        ];

        if ($mode === 'lernwuerfel') {
            $positions = [];
            $skipTurns = [];
            foreach ($activePlayers as $p) {
                $positions[(string)$p->getSlot()] = 0;
                $skipTurns[(string)$p->getSlot()] = 0;
            }
            return array_merge($base, [
                'positions' => $positions,
                'skip_turns' => $skipTurns,
                'shielded_slots' => [],
            ]);
        }

        // wissensturm
        $towers = [];
        foreach ($activePlayers as $p) {
            $towers[(string)$p->getSlot()] = [];
        }
        return array_merge($base, [
            'towers' => $towers,
            'selected_category' => null,
            'steal_pending' => false,
            'steal_from_slot' => null,
        ]);
    }

    // ---------- XP Integration ----------

    /**
     * Award XP to all active players when a gameshow session finishes.
     * Each player receives XP proportional to their correct answers.
     */
    private function awardGameshowXp(GameshowSession $session): void {
        $players = $this->playerMapper->findBySession($session->getId());
        $allAnswers = $this->answerMapper->findBySession($session->getId());

        // Count correct answers per player
        $correctCounts = [];
        foreach ($allAnswers as $answer) {
            if ($answer->getIsCorrect()) {
                $uid = $answer->getPlayerUid();
                $correctCounts[$uid] = ($correctCounts[$uid] ?? 0) + 1;
            }
        }

        foreach ($players as $player) {
            if ($player->getIsRemoved()) {
                continue;
            }

            try {
                $correctCount = $correctCounts[$player->getUserId()] ?? 0;
                $sessionData = [
                    'total_questions' => $session->getNumQuestions(),
                    'mode' => 'training',
                    'correct_answers' => $correctCount,
                ];

                $streak = $this->streakService->getStreak($player->getUserId(), true);
                $xp = $this->xpService->calculateSessionXp($sessionData, $streak['current_streak']);
                $this->xpService->incrementSessionXp($player->getUserId(), $xp, $streak['current_streak']);

                $this->logger->info('GameshowService: Awarded ' . $xp . ' XP to ' . $player->getUserId() . ' for session ' . $session->getCode());
            } catch (\Exception $e) {
                $this->logger->warning('GameshowService: Failed to award XP to ' . $player->getUserId() . ': ' . $e->getMessage());
            }
        }
    }

    // ---------- Private helpers ----------

    /**
     * @param GameshowPlayer[] $players
     * @return GameshowPlayer[]
     */
    private function getActiveGameplayPlayers(GameshowSession $session, array $players): array {
        return array_values(array_filter($players, function (GameshowPlayer $player) use ($session): bool {
            if ($player->getIsRemoved()) {
                return false;
            }

            if ($session->getMode() === 'elimination') {
                return $player->getLives() > 0;
            }

            return true;
        }));
    }

    /**
     * @param GameshowPlayer[] $players
     */
    private function applySuddenDeathIfNeeded(array $players): void {
        $activePlayers = array_values(array_filter($players, function (GameshowPlayer $player): bool {
            return !$player->getIsRemoved() && $player->getLives() > 0;
        }));

        if (count($activePlayers) !== 2) {
            return;
        }

        foreach ($activePlayers as $player) {
            if ($player->getLives() !== 1) {
                $player->setLives(1);
                $this->playerMapper->update($player);
            }
        }
    }

    private function resolveDisplayName(string $userId): string {
        $user = $this->userManager->get($userId);
        return $user !== null ? $user->getDisplayName() : $userId;
    }

    private function resolveContentLanguage(string $userId, ?string $lang): ?string {
        $requestedLang = $this->translationService->normalizeLang($lang);
        if ($requestedLang !== null) {
            return $requestedLang;
        }

        return $this->translationService->normalizeLang(
            $this->config->getUserValue($userId, 'learning', 'content_language', '')
        );
    }

    private function generateCode(): string {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = bin2hex(random_bytes(3));
            try {
                $this->sessionMapper->findByCode($code);
                // Code already exists, retry
            } catch (DoesNotExistException $e) {
                return $code;
            }
        }
        throw new \RuntimeException('Could not generate unique gameshow code after 10 attempts');
    }

    private function selectQuestions(int $poolId, int $numQuestions, string $userId, ?int $courseId = null): array {
        $allowedQuestionIds = null;
        if ($courseId !== null) {
            $allowedQuestionIds = $this->courseService->resolveCoursePoolContext($courseId, $poolId, $userId)['question_ids'];
            if ($allowedQuestionIds === []) {
                throw new \RuntimeException('No questions available for this course pool filter');
            }
        }

        // Only select questions that have answer options (excludes PBQ/CLI/dropdown questions)
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('q.id')
           ->from('learning_questions', 'q')
           ->innerJoin('q', 'learning_answers', 'a', $qb->expr()->eq('a.question_id', 'q.id'))
           ->where($qb->expr()->eq('q.pool_id', $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT)));

        if (is_array($allowedQuestionIds)) {
            $qb->andWhere($qb->expr()->in('q.id', $qb->createNamedParameter($allowedQuestionIds, IQueryBuilder::PARAM_INT_ARRAY)));
        }

        $result = $qb->executeQuery();
        $allIds = [];
        while ($row = $result->fetch()) {
            $allIds[] = (int)$row['id'];
        }
        $result->closeCursor();

        if (empty($allIds)) {
            throw new \RuntimeException('No questions available in this pool');
        }

        shuffle($allIds);
        return array_slice($allIds, 0, $numQuestions);
    }
}
