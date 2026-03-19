<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCA\Learning\Db\DuelAnswer;
use OCA\Learning\Db\DuelAnswerMapper;
use OCA\Learning\Db\DuelSession;
use OCA\Learning\Db\DuelSessionMapper;
use OCA\Learning\Service\LeagueService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class DuelService {
    private DuelSessionMapper $sessionMapper;
    private DuelAnswerMapper $answerMapper;
    private IDBConnection $db;
    private LoggerInterface $logger;
    private LeagueService $leagueService;
    private TranslationService $translationService;
    private IConfig $config;
    private CourseService $courseService;

    /** Tie-break threshold in milliseconds */
    private const TIE_THRESHOLD_MS = 50;

    /** Inactivity timeout in seconds */
    private const TIMEOUT_SECONDS = 30;

    public function __construct(
        DuelSessionMapper $sessionMapper,
        DuelAnswerMapper $answerMapper,
        IDBConnection $db,
        LoggerInterface $logger,
        LeagueService $leagueService,
        TranslationService $translationService,
        IConfig $config,
        CourseService $courseService
    ) {
        $this->sessionMapper = $sessionMapper;
        $this->answerMapper = $answerMapper;
        $this->db = $db;
        $this->logger = $logger;
        $this->leagueService = $leagueService;
        $this->translationService = $translationService;
        $this->config = $config;
        $this->courseService = $courseService;
    }

    // ---------- Public API ----------

    public function createDuel(int $poolId, string $userId, int $numQuestions = 10, ?int $courseId = null): array {
        $questionIds = $this->selectQuestions($poolId, $numQuestions, $userId, $courseId);
        $code = $this->generateCode();

        $session = new DuelSession();
        $session->setCode($code);
        $session->setCreatorUid($userId);
        $session->setOpponentUid(null);
        $session->setPoolId($poolId);
        $session->setCourseId($courseId);
        $session->setQuestionIds(json_encode($questionIds));
        $session->setStatus('waiting');
        $session->setCurrentQuestionIndex(0);
        $session->setCreatorScore(0);
        $session->setOpponentScore(0);
        $session->setCreatorReady(false);
        $session->setOpponentReady(false);
        $session->setCreatorLastPoll(null);
        $session->setOpponentLastPoll(null);
        $session->setCreatedAt(time());

        $session = $this->sessionMapper->insert($session);
        return $this->buildState($session, $userId);
    }

    public function joinDuel(string $code, string $userId): array {
        $session = $this->sessionMapper->findByCode($code);

        if ($session->getStatus() !== 'waiting') {
            throw new \RuntimeException('Duel is no longer open for joining');
        }
        if ($session->getCreatorUid() === $userId) {
            throw new \RuntimeException('You created this duel, you cannot join it as opponent');
        }
        if ($session->getOpponentUid() !== null) {
            throw new \RuntimeException('Duel already has an opponent');
        }

        $session->setOpponentUid($userId);
        $session->setStatus('ready');
        $session = $this->sessionMapper->update($session);

        return $this->buildState($session, $userId);
    }

    public function setReady(string $code, string $userId, ?string $lang = null): array {
        $session = $this->sessionMapper->findByCode($code);
        $this->assertParticipant($session, $userId);

        if ($session->getCreatorUid() === $userId) {
            $session->setCreatorReady(true);
        } else {
            $session->setOpponentReady(true);
        }

        // Both ready → activate
        if ($session->getCreatorReady() && $session->getOpponentReady()) {
            $session->setStatus('active');
        }

        $session = $this->sessionMapper->update($session);
        return $this->buildState($session, $userId, $this->resolveContentLanguage($lang, $userId));
    }

    public function getState(string $code, string $userId, ?string $lang = null): array {
        $session = $this->sessionMapper->findByCode($code);
        $this->assertParticipant($session, $userId);
        $contentLanguage = $this->resolveContentLanguage($lang, $userId);

        // Update last poll timestamp
        $now = time();
        if ($session->getCreatorUid() === $userId) {
            $session->setCreatorLastPoll($now);
        } else {
            $session->setOpponentLastPoll($now);
        }

        // Check for abandoned opponent
        if (in_array($session->getStatus(), ['ready', 'active'], true)) {
            $cutoff = $now - self::TIMEOUT_SECONDS;
            $otherLastPoll = $session->getCreatorUid() === $userId
                ? $session->getOpponentLastPoll()
                : $session->getCreatorLastPoll();

            if ($otherLastPoll !== null && $otherLastPoll < $cutoff) {
                // Other player timed out — current player wins by forfeit
                $session->setStatus('expired');
                if ($session->getCreatorUid() === $userId) {
                    // Creator wins — give creator a big score advantage
                    $session->setCreatorScore($session->getCreatorScore() + 100);
                } else {
                    $session->setOpponentScore($session->getOpponentScore() + 100);
                }
                if ($session->getLeagueChallengeId() !== null) {
                    $this->leagueService->recordExpiredDuel($session);
                }
            }
        }

        $session = $this->sessionMapper->update($session);
        return $this->buildState($session, $userId, $contentLanguage);
    }

    public function submitAnswer(string $code, string $userId, int $answerId, int $answeredAt, ?string $lang = null): array {
        $session = $this->sessionMapper->findByCode($code);
        $this->assertParticipant($session, $userId);
        $contentLanguage = $this->resolveContentLanguage($lang, $userId);

        if ($session->getStatus() !== 'active') {
            throw new \RuntimeException('Duel is not active');
        }

        $questionIndex = $session->getCurrentQuestionIndex();

        // Check player hasn't already answered this question
        $existingAnswers = $this->answerMapper->findByDuelAndQuestion($session->getId(), $questionIndex);
        foreach ($existingAnswers as $existing) {
            if ($existing->getPlayerUid() === $userId) {
                throw new \RuntimeException('You already answered this question');
            }
        }

        // Validate answer server-side
        $qb = $this->db->getQueryBuilder();
        $qb->select('is_correct')->from('learning_answers')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($answerId, IQueryBuilder::PARAM_INT)));
        $res = $qb->executeQuery();
        $row = $res->fetch();
        $res->closeCursor();
        if ($row === false) {
            throw new \RuntimeException('Invalid answer');
        }
        $answerCorrect = (bool)$row['is_correct'];

        // Insert answer
        $answer = new DuelAnswer();
        $answer->setDuelId($session->getId());
        $answer->setQuestionIndex($questionIndex);
        $answer->setPlayerUid($userId);
        $answer->setAnswerCorrect($answerCorrect);
        $answer->setAnsweredAt($answeredAt);
        $answer->setPointsEarned(0);
        $this->answerMapper->insert($answer);

        // Determine correct answer ID for this question (for feedback display)
        $questionIds = json_decode($session->getQuestionIds(), true) ?? [];
        $questionId = (int)($questionIds[$questionIndex] ?? 0);
        $correctAnswerId = $this->getCorrectAnswerId($questionId);

        // Check if both have answered
        $answers = $this->answerMapper->findByDuelAndQuestion($session->getId(), $questionIndex);
        if (count($answers) === 2) {
            $session = $this->applyScoring($session, $answers);
        }

        $state = $this->buildState($session, $userId, $contentLanguage);
        $state['correct_answer_id'] = $correctAnswerId;
        $state['my_answer_correct'] = $answerCorrect;
        return $state;
    }

    public function rematch(string $code, string $userId): array {
        $session = $this->sessionMapper->findByCode($code);
        $this->assertParticipant($session, $userId);

        if (!in_array($session->getStatus(), ['finished', 'expired'], true)) {
            throw new \RuntimeException('Duel must be finished or expired to request a rematch');
        }

        // If a rematch already exists (other player requested first), return it
        $existingCode = $this->findPendingRematch($session);
        if ($existingCode !== null) {
            $existingSession = $this->sessionMapper->findByCode($existingCode);
            return ['new_code' => $existingCode] + $this->buildState($existingSession, $userId);
        }

        $oldCount = count(json_decode($session->getQuestionIds(), true) ?: []) ?: 10;
        $newQuestionIds = $this->selectQuestions($session->getPoolId(), $oldCount, $userId, $session->getCourseId());
        $newCode = $this->generateCode();

        $newSession = new DuelSession();
        $newSession->setCode($newCode);
        $newSession->setCreatorUid($session->getCreatorUid());
        $newSession->setOpponentUid($session->getOpponentUid());
        $newSession->setPoolId($session->getPoolId());
        $newSession->setCourseId($session->getCourseId());
        $newSession->setQuestionIds(json_encode($newQuestionIds));
        $newSession->setStatus('active');   // Both already agreed — skip lobby
        $newSession->setCurrentQuestionIndex(0);
        $newSession->setCreatorScore(0);
        $newSession->setOpponentScore(0);
        $newSession->setCreatorReady(true);
        $newSession->setOpponentReady(true);
        $newSession->setCreatorLastPoll(null);
        $newSession->setOpponentLastPoll(null);
        $newSession->setCreatedAt(time());

        $newSession = $this->sessionMapper->insert($newSession);
        return ['new_code' => $newCode] + $this->buildState($newSession, $userId);
    }

    // ---------- Private helpers ----------

    private function assertParticipant(DuelSession $session, string $userId): void {
        if ($session->getCreatorUid() !== $userId && $session->getOpponentUid() !== $userId) {
            throw new \RuntimeException('You are not a participant in this duel');
        }
    }

    private function resolveContentLanguage(?string $lang, string $userId): ?string {
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
        throw new \RuntimeException('Could not generate unique duel code after 10 attempts');
    }

    private function selectQuestions(int $poolId, int $numQuestions = 10, string $userId = '', ?int $courseId = null): array {
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

        shuffle($allIds);
        return array_slice($allIds, 0, $numQuestions);
    }

    private function applyScoring(DuelSession $session, array $answers): DuelSession {
        // Sort answers: identify creator and opponent
        $creatorAnswer = null;
        $opponentAnswer = null;
        foreach ($answers as $answer) {
            if ($answer->getPlayerUid() === $session->getCreatorUid()) {
                $creatorAnswer = $answer;
            } else {
                $opponentAnswer = $answer;
            }
        }

        if ($creatorAnswer === null || $opponentAnswer === null) {
            return $session;
        }

        $creatorCorrect = $creatorAnswer->getAnswerCorrect();
        $opponentCorrect = $opponentAnswer->getAnswerCorrect();
        $creatorAt = $creatorAnswer->getAnsweredAt();
        $opponentAt = $opponentAnswer->getAnsweredAt();

        $creatorPoints = 0;
        $opponentPoints = 0;

        if ($creatorCorrect && $opponentCorrect) {
            $diff = abs($creatorAt - $opponentAt);
            if ($diff <= self::TIE_THRESHOLD_MS) {
                // Both correct, tied
                $creatorPoints = 3;
                $opponentPoints = 3;
            } elseif ($creatorAt < $opponentAt) {
                // Creator was faster
                $creatorPoints = 3;
                $opponentPoints = 2;
            } else {
                // Opponent was faster
                $creatorPoints = 2;
                $opponentPoints = 3;
            }
        } elseif ($creatorCorrect && !$opponentCorrect) {
            // Creator correct + steal bonus, opponent gets nothing
            $creatorPoints = 4;
            $opponentPoints = 0;
        } elseif (!$creatorCorrect && $opponentCorrect) {
            // Opponent correct + steal bonus, creator gets nothing
            $creatorPoints = 0;
            $opponentPoints = 4;
        } else {
            // Both wrong
            $creatorPoints = -1;
            $opponentPoints = -1;
        }

        // Update answer records
        $creatorAnswer->setPointsEarned($creatorPoints);
        $this->answerMapper->update($creatorAnswer);
        $opponentAnswer->setPointsEarned($opponentPoints);
        $this->answerMapper->update($opponentAnswer);

        // Update session scores
        $session->setCreatorScore($session->getCreatorScore() + $creatorPoints);
        $session->setOpponentScore($session->getOpponentScore() + $opponentPoints);

        // Advance question index
        $questionIds = json_decode($session->getQuestionIds(), true) ?? [];
        $nextIndex = $session->getCurrentQuestionIndex() + 1;
        if ($nextIndex >= count($questionIds)) {
            $session->setStatus('finished');
        } else {
            $session->setCurrentQuestionIndex($nextIndex);
        }

        $session = $this->sessionMapper->update($session);
        if ($session->getStatus() === 'finished' && $session->getLeagueChallengeId() !== null) {
            $this->leagueService->recordFinishedDuel($session);
        }

        return $session;
    }

    private function buildState(DuelSession $session, string $userId, ?string $lang = null): array {
        $questionIds = json_decode($session->getQuestionIds(), true) ?? [];
        $currentIndex = $session->getCurrentQuestionIndex();
        $status = $session->getStatus();

        $currentQuestion = null;
        if ($status === 'active' && $currentIndex < count($questionIds)) {
            $questionId = $questionIds[$currentIndex];
            $currentQuestion = $this->loadQuestion((int)$questionId, $lang);
        }

        $myRole = $session->getCreatorUid() === $userId ? 'creator' : 'opponent';

        $state = [
            'code' => $session->getCode(),
            'status' => $status,
            'creator_uid' => $session->getCreatorUid(),
            'opponent_uid' => $session->getOpponentUid(),
            'creator_score' => $session->getCreatorScore(),
            'opponent_score' => $session->getOpponentScore(),
            'creator_ready' => (bool)$session->getCreatorReady(),
            'opponent_ready' => (bool)$session->getOpponentReady(),
            'current_question_index' => $currentIndex,
            'total_questions' => count($questionIds),
            'current_question' => $currentQuestion,
            'my_role' => $myRole,
        ];

        if ($status === 'finished' || $status === 'expired') {
            $rematchCode = $this->findPendingRematch($session);
            if ($rematchCode !== null) {
                $state['rematch_code'] = $rematchCode;
            }
        }

        return $state;
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
            $this->logger->warning('DuelService: Failed to load question ' . $questionId . ': ' . $e->getMessage());
            return null;
        }
    }

    private function findPendingRematch(DuelSession $session): ?string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('code')
           ->from('learning_duel_sessions')
           ->where($qb->expr()->eq('creator_uid', $qb->createNamedParameter($session->getCreatorUid())))
           ->andWhere($qb->expr()->eq('opponent_uid', $qb->createNamedParameter($session->getOpponentUid())))
           ->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($session->getId(), IQueryBuilder::PARAM_INT)))
           ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('active')))
           ->orderBy('id', 'DESC')
           ->setMaxResults(1);
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row ? (string)$row['code'] : null;
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
}
