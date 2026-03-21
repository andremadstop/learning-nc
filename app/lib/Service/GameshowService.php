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
        StreakService $streakService
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
    }

    // ---------- Public API ----------

    /**
     * SESS-01: Create a new gameshow session.
     */
    public function createSession(int $poolId, string $userId, string $mode, int $maxPlayers = 5, ?int $courseId = null): array {
        if (!in_array($mode, ['sprint', 'elimination'], true)) {
            throw new \RuntimeException('Invalid mode: must be sprint or elimination');
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
        $correctAnswerId = $this->getCorrectAnswerId($questionId);

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

        if ($allAnswered) {
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
            $currentQuestion = $this->loadQuestion($questionId, $contentLanguage);
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

    private function loadQuestion(int $questionId, ?string $lang = null): ?array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'text', 'image_path')
               ->from('learning_questions')
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($questionId, IQueryBuilder::PARAM_INT)));
            $result = $qb->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();

            if ($row === false) {
                return null;
            }

            // Load answers (shuffled, without is_correct to prevent cheating)
            $aqb = $this->db->getQueryBuilder();
            $aqb->select('id', 'text', 'position')
                ->from('learning_answers')
                ->where($aqb->expr()->eq('question_id', $aqb->createNamedParameter($questionId, IQueryBuilder::PARAM_INT)))
                ->orderBy('position', 'ASC');
            $aResult = $aqb->executeQuery();
            $answers = [];
            while ($aRow = $aResult->fetch()) {
                $answers[] = ['id' => (int)$aRow['id'], 'text' => $aRow['text']];
            }
            $aResult->closeCursor();

            $question = [
                'id' => (int)$row['id'],
                'text' => $row['text'],
                'image_path' => $row['image_path'] ?? null,
                'answers' => $answers,
            ];

            return $this->translationService->translateQuestion($question, (string)$lang);
        } catch (\Exception $e) {
            $this->logger->warning('GameshowService: Failed to load question ' . $questionId . ': ' . $e->getMessage());
            return null;
        }
    }

    private function getCorrectAnswerId(int $questionId): ?int {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id')->from('learning_answers')
               ->where($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId, IQueryBuilder::PARAM_INT)))
               ->andWhere($qb->expr()->eq('is_correct', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
            $result = $qb->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();
            return $row ? (int)$row['id'] : null;
        } catch (\Exception $e) {
            return null;
        }
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
