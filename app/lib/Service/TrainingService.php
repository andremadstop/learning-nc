<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Db\AnswerMapper;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\PoolShareMapper;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\XpService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class TrainingService {
    private $db;
    private $questionMapper;
    private $answerMapper;
    private $poolMapper;
    private $shareMapper;
    private $badgeService;
    private $streakService;
    private $xpService;
    private $cacheFactory;
    private TranslationService $translationService;
    private IConfig $config;
    private LoggerInterface $logger;
    private CourseService $courseService;

    public function __construct(
        IDBConnection $db,
        QuestionMapper $questionMapper,
        AnswerMapper $answerMapper,
        PoolMapper $poolMapper,
        PoolShareMapper $shareMapper,
        BadgeService $badgeService,
        StreakService $streakService,
        XpService $xpService,
        ICacheFactory $cacheFactory,
        TranslationService $translationService,
        IConfig $config,
        LoggerInterface $logger,
        CourseService $courseService
    ) {
        $this->db = $db;
        $this->questionMapper = $questionMapper;
        $this->answerMapper = $answerMapper;
        $this->poolMapper = $poolMapper;
        $this->shareMapper = $shareMapper;
        $this->badgeService = $badgeService;
        $this->streakService = $streakService;
        $this->xpService = $xpService;
        $this->cacheFactory = $cacheFactory;
        $this->translationService = $translationService;
        $this->config = $config;
        $this->logger = $logger;
        $this->courseService = $courseService;
    }

    private function applyNullableCourseFilter($qb, ?int $courseId): void {
        if ($courseId === null) {
            $qb->andWhere($qb->expr()->isNull('course_id'));
            return;
        }
        $qb->andWhere($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId)));
    }

    private function logSecurityEvent(string $event, array $context = []): void {
        $this->logger->info('learning.training.security.' . $event, array_merge(['app' => 'learning'], $context));
    }

    private function logAuditEvent(string $event, array $context = []): void {
        $this->logger->info('learning.training.audit.' . $event, array_merge(['app' => 'learning'], $context));
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('learning_audit_events')
                ->values([
                    'event_key' => $qb->createNamedParameter($event),
                    'user_id' => $qb->createNamedParameter($context['user_id'] ?? null, isset($context['user_id']) ? \PDO::PARAM_STR : \PDO::PARAM_NULL),
                    'session_id' => $qb->createNamedParameter($context['session_id'] ?? null, isset($context['session_id']) ? \PDO::PARAM_INT : \PDO::PARAM_NULL),
                    'pool_id' => $qb->createNamedParameter($context['pool_id'] ?? null, isset($context['pool_id']) ? \PDO::PARAM_INT : \PDO::PARAM_NULL),
                    'context_json' => $qb->createNamedParameter(json_encode($context)),
                    'created_at' => $qb->createNamedParameter(time()),
                ]);
            $qb->executeStatement();
        } catch (\Throwable $e) {
            // Keep main flow resilient even if audit storage is not available.
        }
    }

    private function getExamAttemptNo(int $poolId, string $userId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from('learning_sessions')
            ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('mode', $qb->createNamedParameter('exam')));
        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count + 1;
    }

    private function getExamDeadlineAt(array $session): ?int {
        if (($session['mode'] ?? 'training') !== 'exam') {
            return null;
        }
        $timeLimit = isset($session['time_limit_seconds']) ? (int)$session['time_limit_seconds'] : 0;
        $startedAt = isset($session['started_at']) ? (int)$session['started_at'] : 0;
        if ($timeLimit <= 0 || $startedAt <= 0) {
            return null;
        }
        return $startedAt + $timeLimit;
    }

    /**
     * Auto-close expired exam sessions server-side.
     * Returns updated session plus timeout flag.
     */
    private function closeExpiredExamSessionIfNeeded(array $session, string $userId, string $trigger): array {
        $deadlineAt = $this->getExamDeadlineAt($session);
        if ($deadlineAt === null) {
            return ['session' => $session, 'timed_out' => false];
        }

        if (!empty($session['completed_at'])) {
            return ['session' => $session, 'timed_out' => ((int)$session['completed_at'] > $deadlineAt)];
        }

        $now = time();
        if ($now <= $deadlineAt) {
            return ['session' => $session, 'timed_out' => false];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->update('learning_sessions')
            ->set('completed_at', $qb->createNamedParameter($now))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$session['id'])))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->isNull('completed_at'));
        $qb->executeStatement();
        $session['completed_at'] = $now;

        $this->logAuditEvent('exam_timeout_auto_complete', [
            'user_id' => $userId,
            'session_id' => (int)$session['id'],
            'pool_id' => (int)$session['pool_id'],
            'trigger' => $trigger,
            'deadline_at' => $deadlineAt,
            'now' => $now,
        ]);

        return ['session' => $session, 'timed_out' => true];
    }

    /**
     * Deterministic answer ordering per session/question to prevent shared answer position patterns.
     */
    private function shuffleAnswersForSession(array $answers, int $sessionId, int $questionId): array {
        usort($answers, function ($a, $b) use ($sessionId, $questionId): int {
            $sa = hash('sha256', $sessionId . ':' . $questionId . ':' . (string)$a->getId());
            $sb = hash('sha256', $sessionId . ':' . $questionId . ':' . (string)$b->getId());
            return strcmp($sa, $sb);
        });
        return $answers;
    }

    private function getExamAttemptLimitPerDay(): int {
        return max(1, min(50, (int)$this->config->getAppValue('learning', 'exam_attempt_limit_per_day', '5')));
    }

    private function getExamAttemptCooldownMinutes(): int {
        return max(0, min(1440, (int)$this->config->getAppValue('learning', 'exam_attempt_cooldown_minutes', '10')));
    }

    private function enforceExamStartPolicy(int $poolId, string $userId): void {
        $now = time();
        $limitPerDay = $this->getExamAttemptLimitPerDay();
        $cooldownMinutes = $this->getExamAttemptCooldownMinutes();

        $sinceDay = $now - 86400;
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from('learning_sessions')
            ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('mode', $qb->createNamedParameter('exam')))
            ->andWhere($qb->expr()->gte('started_at', $qb->createNamedParameter($sinceDay)));
        $result = $qb->executeQuery();
        $attemptsLast24h = (int)$result->fetchOne();
        $result->closeCursor();
        if ($attemptsLast24h >= $limitPerDay) {
            throw new \Exception('Exam attempt limit reached for the last 24 hours');
        }

        if ($cooldownMinutes > 0) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('started_at')
                ->from('learning_sessions')
                ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId)))
                ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->eq('mode', $qb->createNamedParameter('exam')))
                ->orderBy('started_at', 'DESC')
                ->setMaxResults(1);
            $result = $qb->executeQuery();
            $lastStartedAt = (int)($result->fetchOne() ?: 0);
            $result->closeCursor();

            if ($lastStartedAt > 0) {
                $cooldownUntil = $lastStartedAt + ($cooldownMinutes * 60);
                if ($now < $cooldownUntil) {
                    throw new \Exception('Exam cooldown active');
                }
            }
        }
    }

    private function getSessionQuestionIds(array $session): array {
        if (!empty($session['question_order_json'])) {
            $decoded = json_decode((string)$session['question_order_json'], true);
            if (is_array($decoded) && !empty($decoded)) {
                return array_values(array_map('intval', $decoded));
            }
        }
        return [];
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

    private function getSessionQuestionsWithAnswers(array $session, ?string $lang = null): array {
        $poolId = (int)$session['pool_id'];
        $sessionId = (int)$session['id'];
        $questionIds = $this->getSessionQuestionIds($session);
        $byId = [];
        foreach ($this->questionMapper->findByPoolId($poolId) as $q) {
            $byId[(int)$q->getId()] = $q;
        }

        if (empty($questionIds)) {
            $questionIds = array_keys($byId);
            sort($questionIds);
        }

        // Compute 1-based pool position (rank by id ascending)
        $sortedPoolIds = array_keys($byId);
        sort($sortedPoolIds);
        $poolPositions = array_flip($sortedPoolIds); // id => 0-based index

        $questionsWithAnswers = [];
        foreach ($questionIds as $qid) {
            if (!isset($byId[$qid])) {
                continue;
            }
            $q = $byId[$qid];
            $qData = $q->jsonSerialize();
            $qData['pool_position'] = ($poolPositions[$qid] ?? 0) + 1;
            $answers = $this->answerMapper->findByQuestion($q->getId());
            $answers = $this->shuffleAnswersForSession($answers, $sessionId, (int)$q->getId());
            $qData['answers'] = array_map(static function ($a) {
                $row = $a->jsonSerialize();
                unset($row['is_correct']);
                return $row;
            }, $answers);
            $questionsWithAnswers[] = $qData;
        }

        return $this->translationService->translateQuestions($questionsWithAnswers, $lang);
    }

    private function getSessionUserAnswersMap(int $sessionId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('question_id', 'answer_id', 'answer_ids')
            ->from('learning_user_answers')
            ->where($qb->expr()->eq('session_id', $qb->createNamedParameter($sessionId)))
            ->orderBy('id', 'ASC');
        $result = $qb->executeQuery();

        $map = [];
        while ($row = $result->fetch()) {
            $questionId = (int)$row['question_id'];
            $answerId = $row['answer_id'] !== null ? (int)$row['answer_id'] : null;
            $answerIds = [];
            if (!empty($row['answer_ids'])) {
                $parsed = json_decode((string)$row['answer_ids'], true);
                if (is_array($parsed)) {
                    $answerIds = $parsed;
                }
            }

            if (isset($answerIds['text']) && is_string($answerIds['text'])) {
                $map[$questionId] = ['answerText' => $answerIds['text']];
            } elseif (!empty($answerIds)) {
                $map[$questionId] = array_values(array_map('intval', $answerIds));
            } elseif ($answerId !== null) {
                $map[$questionId] = $answerId;
            } else {
                $map[$questionId] = null;
            }
        }
        $result->closeCursor();

        return $map;
    }

    private function difficultyBucket($rawDifficulty): string {
        $val = is_string($rawDifficulty) ? trim(mb_strtolower($rawDifficulty)) : (string)$rawDifficulty;
        if ($val === '') {
            return 'medium';
        }
        if (is_numeric($val)) {
            $n = (int)$val;
            if ($n <= 2) return 'easy';
            if ($n >= 4) return 'hard';
            return 'medium';
        }
        if (strpos($val, 'easy') !== false || strpos($val, 'leicht') !== false) return 'easy';
        if (strpos($val, 'hard') !== false || strpos($val, 'schwer') !== false) return 'hard';
        return 'medium';
    }

    private function pickExamQuestionsByBlueprint(array $questions, ?int $limit): array {
        if (empty($questions)) {
            return $questions;
        }

        // PBQs are always included — separate them first
        $pbqs = array_values(array_filter($questions, fn($q) => $q->getQuestionType() === 'pbq'));
        $mcqs = array_values(array_filter($questions, fn($q) => $q->getQuestionType() !== 'pbq'));

        $pbqCount = count($pbqs);
        $mcqTotal = count($mcqs);

        if ($limit === null || $limit <= 0 || $limit >= count($questions)) {
            $limit = count($questions);
        }

        // After reserving slots for all PBQs, fill remaining with blueprint MCQs
        $mcqLimit = max(0, $limit - $pbqCount);

        if ($mcqLimit === 0 || $mcqTotal === 0) {
            return $pbqs;
        }

        if ($mcqLimit >= $mcqTotal) {
            shuffle($mcqs);
            return array_merge($pbqs, $mcqs);
        }

        $buckets = ['easy' => [], 'medium' => [], 'hard' => []];
        foreach ($mcqs as $q) {
            $bucket = $this->difficultyBucket($q->getDifficulty());
            $buckets[$bucket][] = $q;
        }

        shuffle($buckets['easy']);
        shuffle($buckets['medium']);
        shuffle($buckets['hard']);

        $targets = [
            'easy'   => (int)floor($mcqLimit * 0.3),
            'medium' => (int)floor($mcqLimit * 0.4),
            'hard'   => (int)floor($mcqLimit * 0.3),
        ];
        $selected = [];
        foreach (['easy', 'medium', 'hard'] as $k) {
            $take = min($targets[$k], count($buckets[$k]));
            if ($take > 0) {
                $selected = array_merge($selected, array_slice($buckets[$k], 0, $take));
                $buckets[$k] = array_slice($buckets[$k], $take);
            }
        }

        if (count($selected) < $mcqLimit) {
            $remaining = array_merge($buckets['easy'], $buckets['medium'], $buckets['hard']);
            shuffle($remaining);
            $need = $mcqLimit - count($selected);
            $selected = array_merge($selected, array_slice($remaining, 0, $need));
        }

        shuffle($selected);
        // PBQs prepended — ExamMode frontend also sorts them first, so ordering is consistent
        return array_merge($pbqs, $selected);
    }

    private function buildSessionStartPayload(array $session, bool $resumed = false, ?string $lang = null): array {
        $sessionId = (int)$session['id'];
        $startedAt = (int)$session['started_at'];
        $timeLimit = isset($session['time_limit_seconds']) ? (int)$session['time_limit_seconds'] : null;
        $deadlineAt = $this->getExamDeadlineAt($session);
        $questionsWithAnswers = $this->getSessionQuestionsWithAnswers($session, $lang);
        $answeredMap = $resumed ? $this->getSessionUserAnswersMap($sessionId) : [];

        return [
            'session_id' => $sessionId,
            'total_questions' => count($questionsWithAnswers),
            'questions' => $questionsWithAnswers,
            'mode' => $session['mode'] ?? 'training',
            'server_time' => time(),
            'started_at' => $startedAt,
            'time_limit_seconds' => $timeLimit,
            'exam_deadline_at' => $deadlineAt,
            'attempt_no' => isset($session['attempt_no']) ? (int)$session['attempt_no'] : null,
            'resumed' => $resumed,
            'answered' => $answeredMap,
        ];
    }

    private function hasPoolAccess(int $poolId, string $userId): bool {
        try {
            $this->poolMapper->find($poolId, $userId);
            return true;
        } catch (DoesNotExistException $e) {
            $share = $this->shareMapper->findByPoolAndUser($poolId, $userId);
            if ($share !== null) {
                return true;
            }
            return $this->hasCoursePoolAccess($poolId, $userId);
        }
    }

    private function hasCoursePoolAccess(int $poolId, string $userId): bool {
        $qb = $this->db->getQueryBuilder();
        $expr = $qb->expr();
        $qb->select('cp.id')
           ->from('learning_course_pools', 'cp')
           ->innerJoin('cp', 'learning_courses', 'c', $expr->eq('cp.course_id', 'c.id'))
           ->leftJoin('c', 'learning_course_members', 'cm', $expr->andX(
               $expr->eq('cm.course_id', 'c.id'),
               $expr->eq('cm.user_id', $qb->createNamedParameter($userId))
           ))
           ->where($expr->eq('cp.pool_id', $qb->createNamedParameter($poolId)))
           ->andWhere($expr->orX(
               $expr->eq('c.instructor_id', $qb->createNamedParameter($userId)),
               $expr->isNotNull('cm.id')
           ))
           ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return $row !== false;
    }

    /**
     * Check if user has an active (uncompleted) exam session on a given pool.
     * Used to suppress correct answers even from training sessions during active exam.
     */
    private function hasActiveExamOnPool(int $poolId, string $userId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('learning_sessions')
           ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId)))
           ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('mode', $qb->createNamedParameter('exam')))
           ->andWhere($qb->expr()->isNull('completed_at'));
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        foreach ($rows as $row) {
            $timeoutInfo = $this->closeExpiredExamSessionIfNeeded($row, $userId, 'active_exam_check');
            if (!$timeoutInfo['timed_out']) {
                return true;
            }
        }
        return false;
    }

    private function verifySessionOwnership(int $sessionId, string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('learning_sessions')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($sessionId)))
           ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $result = $qb->executeQuery();
        $session = $result->fetch();
        $result->closeCursor();

        if (!$session) {
            throw new \Exception('Session not found');
        }
        return $session;
    }

    public function startSession(
        int $poolId,
        string $userId,
        ?int $limit = null,
        string $mode = 'training',
        ?int $timeLimitSeconds = null,
        ?string $lang = null,
        ?int $courseId = null
    ): array {
        $contentLanguage = $this->resolveContentLanguage($lang, $userId);

        if (!in_array($mode, ['training', 'exam'], true)) {
            $mode = 'training';
        }

        if (!$this->hasPoolAccess($poolId, $userId)) {
            throw new \Exception('Pool not found or no access');
        }

        // Resume existing active exam first.
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('learning_sessions')
            ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('mode', $qb->createNamedParameter('exam')))
            ->andWhere($qb->expr()->isNull('completed_at'));
        $this->applyNullableCourseFilter($qb, $courseId);
        $qb
            ->orderBy('started_at', 'DESC')
            ->setMaxResults(1);
        $activeExamResult = $qb->executeQuery();
        $activeExam = $activeExamResult->fetch();
        $activeExamResult->closeCursor();

        if ($activeExam) {
            $timeoutInfo = $this->closeExpiredExamSessionIfNeeded($activeExam, $userId, 'start_session_guard');
            if (!$timeoutInfo['timed_out']) {
                if ($mode === 'exam') {
                    $payload = $this->buildSessionStartPayload($timeoutInfo['session'], true, $contentLanguage);
                    $this->logAuditEvent('session_resumed', [
                        'user_id' => $userId,
                        'session_id' => (int)$timeoutInfo['session']['id'],
                        'pool_id' => $poolId,
                        'mode' => $mode,
                    ]);
                    return $payload;
                }
                throw new \Exception('Cannot start session while an exam is active for this pool');
            }
        }

        // SECURITY: When starting an exam, auto-complete all open training sessions
        // on the same pool to prevent using them as answer oracles
        if ($mode === 'exam') {
            $qb = $this->db->getQueryBuilder();
            $qb->update('learning_sessions')
               ->set('completed_at', $qb->createNamedParameter(time()))
               ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId)))
               ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
               ->andWhere($qb->expr()->isNull('completed_at'));
            $qb->executeStatement();
        }

        if ($courseId !== null) {
            $context = $this->courseService->resolveCoursePoolContext($courseId, $poolId, $userId);
            $questionIds = $context['question_ids'];
            $questions = $this->questionMapper->findByIds($questionIds);
        } else {
            $questions = $this->questionMapper->findByPoolId($poolId);
        }

        if (empty($questions)) {
            throw new \Exception('No questions in this pool');
        }

        if ($mode === 'exam') {
            $questions = $this->pickExamQuestionsByBlueprint($questions, $limit);
        } else {
            shuffle($questions);
            if ($limit !== null && $limit > 0 && $limit < count($questions)) {
                $questions = array_slice($questions, 0, $limit);
            }
        }

        $startedAt = time();
        $examAttemptNo = null;
        $effectiveTimeLimit = null;
        if ($mode === 'exam') {
            $this->enforceExamStartPolicy($poolId, $userId);
            $examAttemptNo = $this->getExamAttemptNo($poolId, $userId);
            $requestedLimit = $timeLimitSeconds ?? 600;
            $effectiveTimeLimit = max(60, min(7200, (int)$requestedLimit));
        }

        $questionOrder = array_map(static fn($q) => (int)$q->getId(), $questions);
        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_sessions')
           ->values([
               'pool_id' => $qb->createNamedParameter($poolId),
               'course_id' => $qb->createNamedParameter($courseId, $courseId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT),
               'user_id' => $qb->createNamedParameter($userId),
               'started_at' => $qb->createNamedParameter($startedAt),
               'total_questions' => $qb->createNamedParameter(count($questions)),
               'correct_answers' => $qb->createNamedParameter(0),
               'mode' => $qb->createNamedParameter($mode),
               'time_limit_seconds' => $qb->createNamedParameter($effectiveTimeLimit, $effectiveTimeLimit === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT),
               'attempt_no' => $qb->createNamedParameter($examAttemptNo, $examAttemptNo === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT),
               'question_order_json' => $qb->createNamedParameter(json_encode($questionOrder)),
           ]);
        $qb->executeStatement();

        $sessionId = (int)$qb->getLastInsertId();
        $deadlineAt = ($mode === 'exam' && $effectiveTimeLimit !== null) ? ($startedAt + $effectiveTimeLimit) : null;

        $sessionForPayload = [
            'id' => $sessionId,
            'pool_id' => $poolId,
            'course_id' => $courseId,
            'user_id' => $userId,
            'started_at' => $startedAt,
            'time_limit_seconds' => $effectiveTimeLimit,
            'attempt_no' => $examAttemptNo,
            'mode' => $mode,
            'question_order_json' => json_encode($questionOrder),
        ];
        $payload = $this->buildSessionStartPayload($sessionForPayload, false, $contentLanguage);

        $this->logAuditEvent('session_started', [
            'user_id' => $userId,
            'session_id' => $sessionId,
            'pool_id' => $poolId,
            'course_id' => $courseId,
            'mode' => $mode,
            'question_count' => count($questionOrder),
            'time_limit_seconds' => $effectiveTimeLimit,
            'exam_deadline_at' => $deadlineAt,
            'attempt_no' => $examAttemptNo,
        ]);

        return $payload;
    }

    private function getAllCorrectAnswers(int $questionId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'text')
           ->from('learning_answers')
           ->where($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId)))
           ->andWhere($qb->expr()->eq('is_correct', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
           ->orderBy('position', 'ASC');
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        return $rows;
    }

    private function getQuestionType(int $questionId): string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('question_type')->from('learning_questions')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($questionId)));
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row ? ($row['question_type'] ?? 'single') : 'single';
    }

    public function submitAnswer(int $sessionId, int $questionId, ?int $answerId, string $userId, ?array $answerIds = null, ?string $answerText = null, ?array $pbqAnswers = null, ?string $lang = null): array {
        $session = $this->verifySessionOwnership($sessionId, $userId);
        $timeoutInfo = $this->closeExpiredExamSessionIfNeeded($session, $userId, 'submit_answer');
        $session = $timeoutInfo['session'];
        if ($timeoutInfo['timed_out']) {
            throw new \Exception('Exam timed out');
        }
        $contentLanguage = $this->resolveContentLanguage($lang, $userId);
        $mode = $session['mode'] ?? 'training';

        // Block submissions to completed sessions
        if (!empty($session['completed_at'])) {
            throw new \Exception('Session already completed');
        }

        // SECURITY: Block individual answer submission during exams (prevents answer oracle)
        if (($session['mode'] ?? 'training') === 'exam') {
            throw new \Exception('Individual answers not allowed during exam');
        }

        $sessionQuestionIds = $this->getSessionQuestionIds($session);
        if (!empty($sessionQuestionIds) && !in_array((int)$questionId, $sessionQuestionIds, true)) {
            return ['error' => 'Question not part of this session'];
        }

        // SECURITY: Defense-in-depth — suppress correct answers if user has active exam on same pool
        $poolId = (int)$session['pool_id'];
        $suppressAnswers = $this->hasActiveExamOnPool($poolId, $userId);
        if ($suppressAnswers) {
            $this->logSecurityEvent('suppressed_single_submit', [
                'user_id' => $userId,
                'session_id' => $sessionId,
                'pool_id' => $poolId,
                'question_id' => $questionId,
                'mode' => $mode,
            ]);
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from('learning_questions')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($questionId)))
           ->andWhere($qb->expr()->eq('pool_id', $qb->createNamedParameter((int)$session['pool_id'])));
        $checkResult = $qb->executeQuery();
        $questionRow = $checkResult->fetch();
        $checkResult->closeCursor();
        if (!$questionRow) {
            throw new \Exception('Question not in this session pool');
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from('learning_user_answers')
           ->where($qb->expr()->eq('session_id', $qb->createNamedParameter($sessionId)))
           ->andWhere($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId)))
           ->setMaxResults(1);
        $dupResult = $qb->executeQuery();
        $dupRow = $dupResult->fetch();
        $dupResult->closeCursor();
        if ($dupRow) {
            throw new \Exception('Question already answered in this session');
        }

        // Open-question path
        $qType = $this->getQuestionType($questionId);
        if ($qType === 'open') {
            if ($answerText === null || trim($answerText) === '') {
                throw new \Exception('Answer text required for open questions');
            }
            if ($answerIds !== null || $answerId !== null) {
                throw new \Exception('Answer IDs not allowed for open questions');
            }
            $correctAnswers = $this->getAllCorrectAnswers($questionId);
            $correctAnswers = $this->translationService->translateAnswerRows($correctAnswers, $contentLanguage);
            $modelText = $correctAnswers[0]['text'] ?? '';
            $isCorrect = QuestionService::isOpenAnswerCorrect($answerText, $modelText);

            $qb = $this->db->getQueryBuilder();
            $qb->insert('learning_user_answers')->values([
                'session_id' => $qb->createNamedParameter($sessionId),
                'question_id' => $qb->createNamedParameter($questionId),
                'answer_id' => $qb->createNamedParameter(null, \PDO::PARAM_NULL),
                'answer_ids' => $qb->createNamedParameter(json_encode(['text' => $answerText])),
                'is_correct' => $qb->createNamedParameter($isCorrect, \PDO::PARAM_BOOL),
                'answered_at' => $qb->createNamedParameter(time())
            ]);
            $qb->executeStatement();

            if ($isCorrect) {
                $this->incrementSessionCorrectAnswers($sessionId);
            }
            $xpEarned = ($isCorrect && !$suppressAnswers) ? $this->awardPerQuestionXp($userId, $mode) : 0;

            if ($suppressAnswers) {
                return [
                    'recorded' => true,
                    'suppressed' => true,
                ];
            }

            // Get explanation
            $qb = $this->db->getQueryBuilder();
            $qb->select('explanation')
               ->from('learning_questions')
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($questionId)));
            $result = $qb->executeQuery();
            $qRow = $result->fetch();
            $result->closeCursor();

            return [
                'is_correct' => $isCorrect,
                'correct_answer_text' => $modelText,
                'user_answer_text' => $answerText,
                'explanation' => $qRow ? $qRow['explanation'] : null,
                'xp_earned' => $xpEarned,
            ];
        }

        // PBQ path
        if ($qType === 'pbq') {
            $pbqAnswers = $pbqAnswers ?? [];
            $qb2 = $this->db->getQueryBuilder();
            $qb2->select('pbq_subtype', 'pbq_config', 'explanation')
                ->from('learning_questions')
                ->where($qb2->expr()->eq('id', $qb2->createNamedParameter($questionId, \PDO::PARAM_INT)));
            $qRes2 = $qb2->executeQuery();
            $qRow2 = $qRes2->fetch();
            $qRes2->closeCursor();

            [$isCorrect, $points, $maxPoints] = $this->scorePbqAnswer(
                $qRow2['pbq_subtype'] ?? '',
                json_decode($qRow2['pbq_config'] ?? '{}', true) ?: [],
                $pbqAnswers
            );
            $pbqIsCorrect = $maxPoints > 0 && ($points / $maxPoints) >= 0.5;

            $pbqMeta = json_encode([
                'pbq' => true,
                'answers' => $pbqAnswers,
                'points' => $points,
                'max_points' => $maxPoints,
            ]);

            $qb = $this->db->getQueryBuilder();
            $qb->insert('learning_user_answers')->values([
                'session_id' => $qb->createNamedParameter($sessionId),
                'question_id' => $qb->createNamedParameter($questionId),
                'answer_id' => $qb->createNamedParameter(null, \PDO::PARAM_NULL),
                'answer_ids' => $qb->createNamedParameter($pbqMeta),
                'is_correct' => $qb->createNamedParameter($pbqIsCorrect, \PDO::PARAM_BOOL),
                'answered_at' => $qb->createNamedParameter(time()),
            ]);
            $qb->executeStatement();

            if ($suppressAnswers) {
                return [
                    'recorded' => true,
                    'suppressed' => true,
                    'pbq_points' => null,
                    'pbq_max_points' => null,
                ];
            }

            if ($pbqIsCorrect) {
                $this->incrementSessionCorrectAnswers($sessionId);
            }
            $xpEarned = $pbqIsCorrect ? $this->awardPerQuestionXp($userId, $mode) : 0;

            return [
                'is_correct' => $pbqIsCorrect,
                'pbq_points' => $points,
                'pbq_max_points' => $maxPoints,
                'explanation' => $qRow2['explanation'] ?? null,
                'xp_earned' => $xpEarned,
            ];
        }

        // Multi-select path
        if ($answerIds !== null && is_array($answerIds) && count($answerIds) > 0) {
            // Validate all answer IDs belong to this question
            foreach ($answerIds as $aid) {
                $qb = $this->db->getQueryBuilder();
                $qb->select('id')
                   ->from('learning_answers')
                   ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$aid)))
                   ->andWhere($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId)));
                $vResult = $qb->executeQuery();
                $vRow = $vResult->fetch();
                $vResult->closeCursor();
                if (!$vRow) {
                    throw new \Exception('Answer not found for this question');
                }
            }

            // Get correct answer IDs
            $correctRows = $this->getAllCorrectAnswers($questionId);
            $translatedCorrectRows = $this->translationService->translateAnswerRows($correctRows, $contentLanguage);
            $correctIds = array_map(fn($r) => (int)$r['id'], $correctRows);
            $userIds = array_map('intval', $answerIds);
            sort($correctIds);
            sort($userIds);
            $isCorrect = ($userIds === $correctIds);

            // Insert user answer row with answer_ids JSON
            $qb = $this->db->getQueryBuilder();
            $qb->insert('learning_user_answers')
               ->values([
                   'session_id' => $qb->createNamedParameter($sessionId),
                   'question_id' => $qb->createNamedParameter($questionId),
                   'answer_id' => $qb->createNamedParameter(null, \PDO::PARAM_NULL),
                   'answer_ids' => $qb->createNamedParameter(json_encode($userIds)),
                   'is_correct' => $qb->createNamedParameter($isCorrect, \PDO::PARAM_BOOL),
                   'answered_at' => $qb->createNamedParameter(time())
               ]);
            $qb->executeStatement();

            if ($isCorrect) {
                $this->incrementSessionCorrectAnswers($sessionId);
            }
            $xpEarned = ($isCorrect && !$suppressAnswers) ? $this->awardPerQuestionXp($userId, $mode) : 0;

            // SECURITY: Suppress correct answers if active exam on same pool
            if ($suppressAnswers) {
                return [
                    'recorded' => true,
                    'suppressed' => true,
                ];
            }

            $correctTexts = array_map(fn($r) => $r['text'], $translatedCorrectRows);
            return [
                'is_correct' => $isCorrect,
                'correct_answer_id' => !empty($correctIds) ? $correctIds[0] : null,
                'correct_answer_text' => !empty($correctTexts) ? $correctTexts[0] : '',
                'correct_answer_ids' => $correctIds,
                'correct_answer_texts' => $correctTexts,
                'xp_earned' => $xpEarned,
            ];
        }

        // Single-select path (original logic)
        $qb = $this->db->getQueryBuilder();
        $qb->select('is_correct')
           ->from('learning_answers')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($answerId)))
           ->andWhere($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId)));
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if (!$row) {
            throw new \Exception('Answer not found for this question');
        }

        $isCorrect = filter_var($row['is_correct'], FILTER_VALIDATE_BOOLEAN);

        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_user_answers')
           ->values([
               'session_id' => $qb->createNamedParameter($sessionId),
               'question_id' => $qb->createNamedParameter($questionId),
               'answer_id' => $qb->createNamedParameter($answerId),
               'is_correct' => $qb->createNamedParameter($isCorrect, \PDO::PARAM_BOOL),
               'answered_at' => $qb->createNamedParameter(time())
           ]);
        $qb->executeStatement();

        if ($isCorrect) {
            $this->incrementSessionCorrectAnswers($sessionId);
        }
        $xpEarned = ($isCorrect && !$suppressAnswers) ? $this->awardPerQuestionXp($userId, $mode) : 0;

        // SECURITY: Suppress correct answers if active exam on same pool
        if ($suppressAnswers) {
            return [
                'recorded' => true,
                'suppressed' => true,
            ];
        }

        // Return all correct answers info
        $correctRows = $this->getAllCorrectAnswers($questionId);
        $translatedCorrectRows = $this->translationService->translateAnswerRows($correctRows, $contentLanguage);
        $correctIds = array_map(fn($r) => (int)$r['id'], $correctRows);
        $correctTexts = array_map(fn($r) => $r['text'], $translatedCorrectRows);

        return [
            'is_correct' => $isCorrect,
            'correct_answer_id' => !empty($correctIds) ? $correctIds[0] : null,
            'correct_answer_text' => !empty($correctTexts) ? $correctTexts[0] : '',
            'correct_answer_ids' => $correctIds,
            'correct_answer_texts' => $correctTexts,
            'xp_earned' => $xpEarned,
        ];
    }

    public function submitBatch(int $sessionId, array $answers, string $userId, ?string $lang = null): array {
        $session = $this->verifySessionOwnership($sessionId, $userId);
        $timeoutInfo = $this->closeExpiredExamSessionIfNeeded($session, $userId, 'submit_batch');
        $session = $timeoutInfo['session'];
        if ($timeoutInfo['timed_out']) {
            throw new \Exception('Exam timed out');
        }
        $contentLanguage = $this->resolveContentLanguage($lang, $userId);

        // Block submissions to completed sessions
        if (!empty($session['completed_at'])) {
            throw new \Exception('Session already completed');
        }

        $poolId = (int)$session['pool_id'];
        $isExam = (($session['mode'] ?? 'training') === 'exam');
        $sessionQuestionIds = $this->getSessionQuestionIds($session);

        // SECURITY: Also suppress answers for training sessions if user has active exam on same pool
        $suppressAnswers = $isExam || $this->hasActiveExamOnPool($poolId, $userId);
        if ($suppressAnswers) {
            $this->logSecurityEvent('suppressed_batch_submit', [
                'user_id' => $userId,
                'session_id' => $sessionId,
                'pool_id' => $poolId,
                'answers_count' => count($answers),
                'mode' => $session['mode'] ?? 'training',
                'exam_mode' => $isExam,
            ]);
        }

        // S3: Validate batch size against session's question count
        $maxAnswers = (int)$session['total_questions'];
        if (count($answers) > $maxAnswers) {
            throw new \Exception('Batch size exceeds session question count');
        }

        $results = [];
        $awardImmediateXp = !$suppressAnswers && !$isExam;
        $xpPerCorrect = 0;
        if ($awardImmediateXp) {
            $streak = $this->streakService->getStreak($userId, true);
            $xpPerCorrect = $this->xpService->applyMultiplier(5, (int)$streak['current_streak']);
        }
        $batchXpEarned = 0;
        foreach ($answers as $entry) {
            $questionId = (int)$entry['questionId'];
            $entryAnswerIds = $entry['answerIds'] ?? null;
            $entryAnswerText = $entry['answerText'] ?? null;
            $answerId = isset($entry['answerId']) ? (int)$entry['answerId'] : null;

            if (!empty($sessionQuestionIds) && !in_array($questionId, $sessionQuestionIds, true)) {
                $results[] = ['questionId' => $questionId, 'error' => 'Question not part of this session'];
                continue;
            }

            // Validate question belongs to this session's pool
            $qb = $this->db->getQueryBuilder();
            $qb->select('id')
               ->from('learning_questions')
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($questionId)))
               ->andWhere($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId)));
            $checkResult = $qb->executeQuery();
            $questionRow = $checkResult->fetch();
            $checkResult->closeCursor();
            if (!$questionRow) {
                $results[] = ['questionId' => $questionId, 'error' => 'Question not in session pool'];
                continue;
            }

            // Skip if already answered
            $qb = $this->db->getQueryBuilder();
            $qb->select('id')
               ->from('learning_user_answers')
               ->where($qb->expr()->eq('session_id', $qb->createNamedParameter($sessionId)))
               ->andWhere($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId)))
               ->setMaxResults(1);
            $dupResult = $qb->executeQuery();
            $dupRow = $dupResult->fetch();
            $dupResult->closeCursor();
            if ($dupRow) {
                $results[] = ['questionId' => $questionId, 'error' => 'Already answered'];
                continue;
            }

            // Open-question path in batch
            $batchQType = $this->getQuestionType($questionId);
            if ($batchQType === 'open') {
                if ($entryAnswerText === null || !is_string($entryAnswerText) || trim($entryAnswerText) === '') {
                    $results[] = ['questionId' => $questionId, 'error' => 'Answer text required'];
                    continue;
                }
                if ($entryAnswerIds !== null) {
                    $results[] = ['questionId' => $questionId, 'error' => 'Answer IDs not allowed for open questions'];
                    continue;
                }
                if (mb_strlen($entryAnswerText) > 2000) {
                    $results[] = ['questionId' => $questionId, 'error' => 'Answer text too long (max 2000 chars)'];
                    continue;
                }
                $correctRows = $this->getAllCorrectAnswers($questionId);
                $translatedCorrectRows = $this->translationService->translateAnswerRows($correctRows, $contentLanguage);
                $modelText = $translatedCorrectRows[0]['text'] ?? '';
                $isCorrect = QuestionService::isOpenAnswerCorrect($entryAnswerText, $modelText);

                $qb = $this->db->getQueryBuilder();
                $qb->insert('learning_user_answers')->values([
                    'session_id' => $qb->createNamedParameter($sessionId),
                    'question_id' => $qb->createNamedParameter($questionId),
                    'answer_id' => $qb->createNamedParameter(null, \PDO::PARAM_NULL),
                    'answer_ids' => $qb->createNamedParameter(json_encode(['text' => $entryAnswerText])),
                    'is_correct' => $qb->createNamedParameter($isCorrect, \PDO::PARAM_BOOL),
                    'answered_at' => $qb->createNamedParameter(time())
                ]);
                $qb->executeStatement();

                if ($isCorrect) {
                    $this->incrementSessionCorrectAnswers($sessionId);
                    $batchXpEarned += $xpPerCorrect;
                }

                if ($suppressAnswers) {
                    $results[] = [
                        'questionId' => $questionId,
                        'recorded' => true,
                        'suppressed' => true,
                    ];
                } else {
                    $results[] = [
                        'questionId' => $questionId,
                        'is_correct' => $isCorrect,
                        'correct_answer_text' => $modelText,
                        'user_answer_text' => $entryAnswerText,
                        'xp_earned' => ($awardImmediateXp && $isCorrect) ? $xpPerCorrect : 0,
                    ];
                }
                continue;
            }

            // PBQ path
            if ($batchQType === 'pbq') {
                $pbqUserAnswers = $entry['pbqAnswers'] ?? [];
                if (is_string($pbqUserAnswers)) {
                    $pbqUserAnswers = json_decode($pbqUserAnswers, true) ?? [];
                }

                // Fetch pbq_subtype and pbq_config for scoring
                $qb2 = $this->db->getQueryBuilder();
                $qb2->select('pbq_subtype', 'pbq_config')
                    ->from('learning_questions')
                    ->where($qb2->expr()->eq('id', $qb2->createNamedParameter($questionId, \PDO::PARAM_INT)));
                $qRes2 = $qb2->executeQuery();
                $qRow = $qRes2->fetch();
                $qRes2->closeCursor();

                [$isCorrect, $points, $maxPoints] = $this->scorePbqAnswer(
                    $qRow['pbq_subtype'] ?? '',
                    json_decode($qRow['pbq_config'] ?? '{}', true) ?: [],
                    is_array($pbqUserAnswers) ? $pbqUserAnswers : []
                );

                $pbqMeta = json_encode([
                    'pbq' => true,
                    'answers' => $pbqUserAnswers,
                    'points' => $points,
                    'max_points' => $maxPoints,
                ]);
                $pbqIsCorrect = $maxPoints > 0 && ($points / $maxPoints) >= 0.5;

                $qb = $this->db->getQueryBuilder();
                $qb->insert('learning_user_answers')->values([
                    'session_id' => $qb->createNamedParameter($sessionId),
                    'question_id' => $qb->createNamedParameter($questionId),
                    'answer_id' => $qb->createNamedParameter(null, \PDO::PARAM_NULL),
                    'answer_ids' => $qb->createNamedParameter($pbqMeta),
                    'is_correct' => $qb->createNamedParameter($pbqIsCorrect, \PDO::PARAM_BOOL),
                    'answered_at' => $qb->createNamedParameter(time()),
                ]);
                $qb->executeStatement();

                if ($pbqIsCorrect && !$suppressAnswers) {
                    $this->incrementSessionCorrectAnswers($sessionId);
                    $batchXpEarned += $xpPerCorrect;
                }

                if ($suppressAnswers) {
                    $results[] = [
                        'questionId' => $questionId,
                        'recorded' => true,
                        'suppressed' => true,
                    ];
                } else {
                    $results[] = [
                        'questionId' => $questionId,
                        'is_correct' => $pbqIsCorrect,
                        'pbq_points' => $points,
                        'pbq_max_points' => $maxPoints,
                    ];
                }
                continue;
            }

            // Multi-select path
            if (is_array($entryAnswerIds) && count($entryAnswerIds) > 0) {
                // Validate all answer IDs
                $allValid = true;
                foreach ($entryAnswerIds as $aid) {
                    $qb = $this->db->getQueryBuilder();
                    $qb->select('id')
                       ->from('learning_answers')
                       ->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$aid)))
                       ->andWhere($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId)));
                    $vResult = $qb->executeQuery();
                    $vRow = $vResult->fetch();
                    $vResult->closeCursor();
                    if (!$vRow) {
                        $allValid = false;
                        break;
                    }
                }
                if (!$allValid) {
                    $results[] = ['questionId' => $questionId, 'error' => 'Answer not found'];
                    continue;
                }

                $correctRows = $this->getAllCorrectAnswers($questionId);
                $translatedCorrectRows = $this->translationService->translateAnswerRows($correctRows, $contentLanguage);
                $correctIds = array_map(fn($r) => (int)$r['id'], $correctRows);
                $userIds = array_map('intval', $entryAnswerIds);
                sort($correctIds);
                sort($userIds);
                $isCorrect = ($userIds === $correctIds);

                $qb = $this->db->getQueryBuilder();
                $qb->insert('learning_user_answers')
                   ->values([
                       'session_id' => $qb->createNamedParameter($sessionId),
                       'question_id' => $qb->createNamedParameter($questionId),
                       'answer_id' => $qb->createNamedParameter(null, \PDO::PARAM_NULL),
                       'answer_ids' => $qb->createNamedParameter(json_encode($userIds)),
                       'is_correct' => $qb->createNamedParameter($isCorrect, \PDO::PARAM_BOOL),
                       'answered_at' => $qb->createNamedParameter(time())
                   ]);
                $qb->executeStatement();

                if ($isCorrect) {
                    $this->incrementSessionCorrectAnswers($sessionId);
                    $batchXpEarned += $xpPerCorrect;
                }

                // SECURITY: Suppress correct answers during active exam
                if ($suppressAnswers) {
                    $results[] = [
                        'questionId' => $questionId,
                        'recorded' => true,
                        'suppressed' => true,
                    ];
                } else {
                    $correctTexts = array_map(fn($r) => $r['text'], $translatedCorrectRows);
                    $results[] = [
                        'questionId' => $questionId,
                        'is_correct' => $isCorrect,
                        'correct_answer_id' => !empty($correctIds) ? $correctIds[0] : null,
                        'correct_answer_text' => !empty($correctTexts) ? $correctTexts[0] : '',
                        'correct_answer_ids' => $correctIds,
                        'correct_answer_texts' => $correctTexts,
                        'xp_earned' => ($awardImmediateXp && $isCorrect) ? $xpPerCorrect : 0,
                    ];
                }
                continue;
            }

            // Single-select path
            if ($answerId === null) {
                $results[] = ['questionId' => $questionId, 'error' => 'No answer provided'];
                continue;
            }

            $qb = $this->db->getQueryBuilder();
            $qb->select('is_correct')
               ->from('learning_answers')
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($answerId)))
               ->andWhere($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId)));
            $result = $qb->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();

            if (!$row) {
                $results[] = ['questionId' => $questionId, 'error' => 'Answer not found'];
                continue;
            }

            $isCorrect = filter_var($row['is_correct'], FILTER_VALIDATE_BOOLEAN);

            $qb = $this->db->getQueryBuilder();
            $qb->insert('learning_user_answers')
               ->values([
                   'session_id' => $qb->createNamedParameter($sessionId),
                   'question_id' => $qb->createNamedParameter($questionId),
                   'answer_id' => $qb->createNamedParameter($answerId),
                   'is_correct' => $qb->createNamedParameter($isCorrect, \PDO::PARAM_BOOL),
                   'answered_at' => $qb->createNamedParameter(time())
               ]);
            $qb->executeStatement();

            if ($isCorrect) {
                $this->incrementSessionCorrectAnswers($sessionId);
                $batchXpEarned += $xpPerCorrect;
            }

            // SECURITY: Suppress correct answers during active exam
            if ($suppressAnswers) {
                $results[] = [
                    'questionId' => $questionId,
                    'recorded' => true,
                    'suppressed' => true,
                ];
            } else {
                $correctRows = $this->getAllCorrectAnswers($questionId);
                $translatedCorrectRows = $this->translationService->translateAnswerRows($correctRows, $contentLanguage);
                $correctIds = array_map(fn($r) => (int)$r['id'], $correctRows);
                $correctTexts = array_map(fn($r) => $r['text'], $translatedCorrectRows);

                $results[] = [
                    'questionId' => $questionId,
                    'is_correct' => $isCorrect,
                    'correct_answer_id' => !empty($correctIds) ? $correctIds[0] : null,
                    'correct_answer_text' => !empty($correctTexts) ? $correctTexts[0] : '',
                    'correct_answer_ids' => $correctIds,
                    'correct_answer_texts' => $correctTexts,
                    'xp_earned' => ($awardImmediateXp && $isCorrect) ? $xpPerCorrect : 0,
                ];
            }
        }

        if ($batchXpEarned > 0) {
            $this->xpService->incrementLeitnerXp($userId, $batchXpEarned);
            $this->cacheFactory->createDistributed('learning')->remove('user_state_' . $userId);
        }

        $this->logAuditEvent('batch_submitted', [
            'user_id' => $userId,
            'session_id' => $sessionId,
            'pool_id' => $poolId,
            'mode' => $session['mode'] ?? 'training',
            'submitted_count' => count($answers),
            'processed_count' => count($results),
            'batch_xp_earned' => $batchXpEarned,
        ]);

        return $results;
    }

    private function scorePbqAnswer(string $subtype, array $config, array $userAnswers): array {
        $points = 0;
        $maxPoints = 0;
        switch ($subtype) {
            case 'dropdown':
                foreach (($config['questions'] ?? []) as $q) {
                    if (($q['scorable'] ?? true) !== true) {
                        continue;
                    }
                    $maxPoints++;
                    if (isset($userAnswers[$q['id']]) && $userAnswers[$q['id']] === $q['correct']) {
                        $points++;
                    }
                }
                break;
            case 'placement':
                foreach (($config['positions'] ?? []) as $pos) {
                    $maxPoints++;
                    $expected = $pos['correct'] ?? null;
                    $actual = $userAnswers[$pos['id']] ?? null;
                    $isMatch = is_array($expected) ? in_array($actual, $expected, true) : $actual === $expected;
                    if ($actual !== null && $isMatch) {
                        $points++;
                    }
                }
                break;
            case 'cli':
                foreach (($config['evaluation'] ?? []) as $ev) {
                    $maxPoints += ($ev['points'] ?? 1);
                    $history = $userAnswers[$ev['terminal']] ?? [];
                    $allCmds = is_array($history) ? implode("\n", $history) : (string)$history;
                    $pattern = $ev['required_pattern'] ?? '';
                    if (strlen($pattern) > 0 && strlen($pattern) <= 200) {
                        try {
                            if (@preg_match('/' . addcslashes($pattern, '/') . '/i', $allCmds) === 1) {
                                $points += ($ev['points'] ?? 1);
                            }
                        } catch (\Throwable $e) {
                            // invalid pattern, skip
                        }
                    }
                }
                break;
            case 'cable':
                foreach (($config['questions'] ?? []) as $q) {
                    $maxPoints += 2;
                    if (isset($userAnswers[$q['cable_id'] . '_problem']) && $userAnswers[$q['cable_id'] . '_problem'] === $q['correct_problem']) {
                        $points++;
                    }
                    if (isset($userAnswers[$q['cable_id'] . '_solution']) && $userAnswers[$q['cable_id'] . '_solution'] === $q['correct_solution']) {
                        $points++;
                    }
                }
                break;
            case 'multi_panel':
                foreach ((($config['cli'] ?? [])['evaluation'] ?? []) as $ev) {
                    $maxPoints += ($ev['points'] ?? 1);
                    $history = ($userAnswers['cli'] ?? [])[$ev['terminal']] ?? [];
                    $allCmds = is_array($history) ? implode("\n", $history) : (string)$history;
                    $pattern = $ev['required_pattern'] ?? '';
                    if (strlen($pattern) > 0 && strlen($pattern) <= 200) {
                        try {
                            if (@preg_match('/' . addcslashes($pattern, '/') . '/i', $allCmds) === 1) {
                                $points += ($ev['points'] ?? 1);
                            }
                        } catch (\Throwable $e) {
                            // invalid pattern, skip
                        }
                    }
                }
                foreach ((($config['dropdown'] ?? [])['questions'] ?? []) as $q) {
                    if (($q['scorable'] ?? true) !== true) {
                        continue;
                    }
                    $maxPoints++;
                    if ((($userAnswers['dropdown'] ?? [])[$q['id']] ?? null) === $q['correct']) {
                        $points++;
                    }
                }
                foreach ((($config['placement'] ?? [])['positions'] ?? []) as $pos) {
                    $maxPoints++;
                    $expected = $pos['correct'] ?? null;
                    $actual = (($userAnswers['placement'] ?? [])[$pos['id']] ?? null);
                    $isMatch = is_array($expected) ? in_array($actual, $expected, true) : $actual === $expected;
                    if ($actual !== null && $isMatch) {
                        $points++;
                    }
                }
                break;
            case 'switch_config':
                foreach (($config['switches'] ?? []) as $device) {
                    foreach (($device['ports'] ?? []) as $port) {
                        $expected = $port['correct'] ?? null;
                        if (!is_array($expected)) {
                            continue;
                        }
                        $answer = $userAnswers[$port['id']] ?? [];
                        $initial = $port['initial'] ?? [];

                        $maxPoints++;
                        $effectiveStatus = $answer['status'] ?? $initial['status'] ?? 'Enabled';
                        if ($effectiveStatus === ($expected['status'] ?? 'Enabled')) {
                            $points++;
                        }

                        $maxPoints++;
                        $effectiveLacp = $answer['lacp'] ?? $initial['lacp'] ?? 'Disabled';
                        if ($effectiveLacp === ($expected['lacp'] ?? 'Disabled')) {
                            $points++;
                        }

                        foreach (($expected['vlans'] ?? []) as $vlanId => $expectedMode) {
                            $maxPoints++;
                            $effectiveMode = $answer['vlans'][$vlanId] ?? $initial['vlans'][$vlanId] ?? 'none';
                            if ($effectiveMode === $expectedMode) {
                                $points++;
                            }
                        }
                    }
                }
                break;
            case 'routing_config':
                foreach (($config['routers'] ?? []) as $router) {
                    $expected = $router['correct'] ?? null;
                    if (!is_array($expected)) {
                        continue;
                    }
                    $answer = $userAnswers[$router['id']] ?? [];
                    $initial = $router['initial'] ?? [];
                    foreach (['problem_found', 'destination_prefix', 'destination_prefix_mask', 'interface'] as $field) {
                        $maxPoints++;
                        $effectiveValue = $answer[$field] ?? $initial[$field] ?? '';
                        if ((string)$effectiveValue === (string)($expected[$field] ?? '')) {
                            $points++;
                        }
                    }
                }
                break;
            case 'diagnostic':
                foreach (($config['findings'] ?? []) as $finding) {
                    $expected = $finding['correct'] ?? null;
                    if (!is_array($expected)) {
                        continue;
                    }
                    $answer = $userAnswers[$finding['id']] ?? [];
                    foreach (['component', 'problem', 'solution'] as $field) {
                        $maxPoints++;
                        if ((string)($answer[$field] ?? '') === (string)($expected[$field] ?? '')) {
                            $points++;
                        }
                    }
                }
                break;
        }
        $isCorrect = $maxPoints > 0 && ($points / $maxPoints) >= 0.5;
        return [$isCorrect, $points, $maxPoints];
    }

    /**
     * Award immediate XP for a correctly answered question in training/exam sessions.
     */
    private function awardPerQuestionXp(string $userId, string $mode): int {
        $streak = $this->streakService->getStreak($userId, true);
        $baseXp = $mode === 'exam' ? 10 : 5;
        $xpEarned = $this->xpService->applyMultiplier($baseXp, (int)$streak['current_streak']);
        if ($xpEarned > 0) {
            $this->xpService->incrementLeitnerXp($userId, $xpEarned);
            $this->cacheFactory->createDistributed('learning')->remove('user_state_' . $userId);
        }
        return $xpEarned;
    }

    public function completeSession(int $sessionId, string $userId, ?string $lang = null): array {
        $session = $this->verifySessionOwnership($sessionId, $userId);
        $timeoutInfo = $this->closeExpiredExamSessionIfNeeded($session, $userId, 'complete');
        $session = $timeoutInfo['session'];
        $timedOut = $timeoutInfo['timed_out'];
        $deadlineAt = $this->getExamDeadlineAt($session);
        $contentLanguage = $this->resolveContentLanguage($lang, $userId);

        // Idempotent: already completed sessions return existing data
        if (!empty($session['completed_at'])) {
            $response = [
                'session_id' => (int)$session['id'],
                'total_questions' => (int)$session['total_questions'],
                'correct_answers' => (int)$session['correct_answers'],
                'completed_at' => (int)$session['completed_at'],
            ];
            if (($session['mode'] ?? 'training') === 'exam') {
                $response['review'] = $this->getSessionReview($sessionId, $contentLanguage);
            }
            $totalQ = (int)$session['total_questions'];
            $response['score_percentage'] = $totalQ > 0
                ? round((int)$session['correct_answers'] / $totalQ * 100)
                : 0;
            $response['timed_out'] = $timedOut;
            $response['exam_deadline_at'] = $deadlineAt;
            $response['attempt_no'] = isset($session['attempt_no']) ? (int)$session['attempt_no'] : null;
            return $response;
        }

        $now = time();
        $qb = $this->db->getQueryBuilder();
        $affected = $qb->update('learning_sessions')
            ->set('completed_at', $qb->createNamedParameter($now))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->isNull('completed_at'))
            ->executeStatement();

        if ($affected === 0) {
            return $this->getExistingCompletionResult($sessionId, $userId, $contentLanguage);
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('learning_sessions')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($sessionId)))
           ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $result = $qb->executeQuery();
        $session = $result->fetch();
        $result->closeCursor();

        $response = [
            'session_id' => (int)$session['id'],
            'total_questions' => (int)$session['total_questions'],
            'correct_answers' => (int)$session['correct_answers'],
            'completed_at' => (int)$session['completed_at'],
            'score_percentage' => (int)$session['total_questions'] > 0
                ? round((int)$session['correct_answers'] / (int)$session['total_questions'] * 100)
                : 0
        ];
        $response['timed_out'] = $timedOut;
        $response['exam_deadline_at'] = $deadlineAt;
        $response['attempt_no'] = isset($session['attempt_no']) ? (int)$session['attempt_no'] : null;

        // For exam sessions, include full review data (only available after completion)
        if (($session['mode'] ?? 'training') === 'exam') {
            $response['review'] = $this->getSessionReview($sessionId, $contentLanguage);
        }

        // Read level before XP increment for level-up detection
        $levelBefore = $this->xpService->calculateXp($userId)['level'];

        // Badge check
        $sessionData = [
            'mode' => $session['mode'] ?? 'training',
            'total_questions' => (int)$session['total_questions'],
            'correct_answers' => (int)$session['correct_answers'],
            'completed_at' => (int)$session['completed_at'],
            'started_at' => (int)$session['started_at'],
        ];
        $newBadges = $this->badgeService->checkAndAward($userId, 'session_complete', $sessionData);
        $response['newly_earned_badges'] = $newBadges;

        // XP for this session
        $streak = $this->streakService->getStreak($userId, true);
        $sessionXp = $this->xpService->calculateSessionXp($sessionData, $streak['current_streak']);
        $response['xp_earned'] = $sessionXp;

        // Update denormalized stats
        $this->xpService->incrementSessionXp($userId, $sessionXp, $streak['current_streak']);

        // Streak badge check
        $streakBadges = $this->badgeService->checkAndAward($userId, 'streak_update', $streak);
        $response['newly_earned_badges'] = array_merge($newBadges, $streakBadges);

        // Level-up detection
        $levelAfter = $this->xpService->calculateXp($userId)['level'];
        $response['level_before'] = $levelBefore;
        $response['level_after'] = $levelAfter;

        // Invalidate cache AFTER all state-changing writes (XP, badges, streak)
        $this->cacheFactory->createDistributed('learning')->remove('user_state_' . $userId);

        // Personal best: compare score to user's average for this pool in this mode
        $poolId = (int)$session['pool_id'];
        $mode = $session['mode'] ?? 'training';
        $avgAccuracy = $this->getAverageAccuracy($poolId, $userId, $mode, $sessionId);
        $response['average_accuracy'] = $avgAccuracy;
        $response['is_personal_best'] = $response['score_percentage'] > $avgAccuracy && $avgAccuracy > 0;
        $improvement = $avgAccuracy > 0 ? round($response['score_percentage'] - $avgAccuracy) : 0;
        $response['improvement'] = $improvement;

        $this->logAuditEvent('session_completed', [
            'user_id' => $userId,
            'session_id' => $sessionId,
            'pool_id' => $poolId,
            'mode' => $mode,
            'timed_out' => $timedOut,
            'score_percentage' => $response['score_percentage'],
            'correct_answers' => (int)$session['correct_answers'],
            'total_questions' => (int)$session['total_questions'],
            'xp_earned' => $sessionXp,
            'attempt_no' => isset($session['attempt_no']) ? (int)$session['attempt_no'] : null,
        ]);

        return $response;
    }

    private function getExistingCompletionResult(int $sessionId, string $userId, ?string $lang = null): array {
        $session = $this->verifySessionOwnership($sessionId, $userId);
        $timeoutInfo = $this->closeExpiredExamSessionIfNeeded($session, $userId, 'complete_existing');
        $session = $timeoutInfo['session'];
        $deadlineAt = $this->getExamDeadlineAt($session);
        $contentLanguage = $this->resolveContentLanguage($lang, $userId);

        $response = [
            'session_id' => (int)$session['id'],
            'total_questions' => (int)$session['total_questions'],
            'correct_answers' => (int)$session['correct_answers'],
            'completed_at' => $session['completed_at'] !== null ? (int)$session['completed_at'] : null,
            'score_percentage' => (int)$session['total_questions'] > 0
                ? round((int)$session['correct_answers'] / (int)$session['total_questions'] * 100)
                : 0,
            'timed_out' => $timeoutInfo['timed_out'],
            'exam_deadline_at' => $deadlineAt,
            'attempt_no' => isset($session['attempt_no']) ? (int)$session['attempt_no'] : null,
        ];

        if (($session['mode'] ?? 'training') === 'exam') {
            $response['review'] = $this->getSessionReview($sessionId, $contentLanguage);
        }

        return $response;
    }

    public function getSessionStatus(int $sessionId, string $userId, ?string $lang = null, bool $includeQuestions = false): array {
        $session = $this->verifySessionOwnership($sessionId, $userId);
        $timeoutInfo = $this->closeExpiredExamSessionIfNeeded($session, $userId, 'status');
        $session = $timeoutInfo['session'];
        $contentLanguage = $this->resolveContentLanguage($lang, $userId);

        $deadlineAt = $this->getExamDeadlineAt($session);
        $now = time();
        $remaining = $deadlineAt !== null ? max(0, $deadlineAt - $now) : null;

        $response = [
            'session_id' => (int)$session['id'],
            'pool_id' => (int)$session['pool_id'],
            'mode' => $session['mode'] ?? 'training',
            'server_time' => $now,
            'started_at' => (int)$session['started_at'],
            'completed_at' => $session['completed_at'] !== null ? (int)$session['completed_at'] : null,
            'completed' => !empty($session['completed_at']),
            'timed_out' => $timeoutInfo['timed_out'],
            'time_limit_seconds' => isset($session['time_limit_seconds']) ? (int)$session['time_limit_seconds'] : null,
            'exam_deadline_at' => $deadlineAt,
            'remaining_seconds' => $remaining,
            'attempt_no' => isset($session['attempt_no']) ? (int)$session['attempt_no'] : null,
            'total_questions' => (int)$session['total_questions'],
            'correct_answers' => (int)$session['correct_answers'],
            'answered' => $this->getSessionUserAnswersMap((int)$session['id']),
        ];

        if ($includeQuestions) {
            $response['questions'] = $this->getSessionQuestionsWithAnswers($session, $contentLanguage);
        }

        return $response;
    }

    private function getAverageAccuracy(int $poolId, string $userId, string $mode, int $excludeSessionId): float {
        $qb = $this->db->getQueryBuilder();
        $qb->select(
            $qb->createFunction('AVG(CASE WHEN total_questions > 0 THEN (correct_answers * 100.0) / total_questions ELSE 0 END) as avg_pct')
        )
           ->from('learning_sessions')
           ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId)))
           ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('mode', $qb->createNamedParameter($mode)))
           ->andWhere($qb->expr()->isNotNull('completed_at'))
           ->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($excludeSessionId)));
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return round((float)($row['avg_pct'] ?? 0));
    }

    private function getSessionReview(int $sessionId, ?string $lang = null): array {
        // Fetch all user answers for this session
        $qb = $this->db->getQueryBuilder();
        $qb->select('ua.*', 'q.text AS question_text', 'q.question_type')
           ->from('learning_user_answers', 'ua')
           ->innerJoin('ua', 'learning_questions', 'q', $qb->expr()->eq('ua.question_id', 'q.id'))
           ->where($qb->expr()->eq('ua.session_id', $qb->createNamedParameter($sessionId)))
           ->orderBy('ua.id', 'ASC');
        $result = $qb->executeQuery();
        $userAnswers = $result->fetchAll();
        $result->closeCursor();

        $review = [];
        foreach ($userAnswers as $ua) {
            $questionId = (int)$ua['question_id'];
            $isCorrect = filter_var($ua['is_correct'], FILTER_VALIDATE_BOOLEAN);

            // Get correct answers for this question
            $correctRows = $this->getAllCorrectAnswers($questionId);
            $correctIds = array_map(fn($r) => (int)$r['id'], $correctRows);
            $correctTexts = array_map(fn($r) => $r['text'], $correctRows);

            // Get all answers for this question (for multi-select review)
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'text', 'is_correct')
               ->from('learning_answers')
               ->where($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId)))
               ->orderBy('position', 'ASC');
            $aResult = $qb->executeQuery();
            $allAnswers = $aResult->fetchAll();
            $aResult->closeCursor();

            // Determine user's selected answer IDs
            $userAnswerIds = [];
            if (!empty($ua['answer_ids'])) {
                $userAnswerIds = json_decode($ua['answer_ids'], true) ?: [];
            } elseif (!empty($ua['answer_id'])) {
                $userAnswerIds = [(int)$ua['answer_id']];
            }

            $entry = [
                'questionId' => $questionId,
                'questionText' => $ua['question_text'],
                'questionType' => $ua['question_type'] ?? 'single',
                'is_correct' => $isCorrect,
                'correct_answer_ids' => $correctIds,
                'correct_answer_texts' => $correctTexts,
                'user_answer_ids' => $userAnswerIds,
                'answers' => array_map(function ($a) use ($userAnswerIds) {
                    return [
                        'id' => (int)$a['id'],
                        'text' => $a['text'],
                        'is_correct' => filter_var($a['is_correct'], FILTER_VALIDATE_BOOLEAN),
                        'was_selected' => in_array((int)$a['id'], $userAnswerIds, true),
                    ];
                }, $allAnswers),
            ];

            if (($ua['question_type'] ?? '') === 'pbq') {
                $pbqData = json_decode($ua['answer_ids'] ?? '{}', true) ?: [];
                $entry['is_pbq'] = true;
                $entry['pbq_points'] = $pbqData['points'] ?? 0;
                $entry['pbq_max_points'] = $pbqData['max_points'] ?? 1;
                $entry['pbq_user_answers'] = $pbqData['answers'] ?? [];
            }

            $review[] = $entry;
        }

        return $this->translationService->translateReviewEntries($review, $lang);
    }

    /**
     * Atomically increment correct_answers for a session.
     * Uses raw executeStatement to avoid PostgreSQL quoting bug with QB createFunction().
     */
    private function incrementSessionCorrectAnswers(int $sessionId): void {
        $inner = method_exists($this->db, 'getInner') ? $this->db->getInner() : $this->db;
        $prefix = method_exists($inner, 'getPrefix') ? $inner->getPrefix() : 'oc_';
        $this->db->executeStatement(
            'UPDATE ' . $prefix . 'learning_sessions SET correct_answers = correct_answers + 1 WHERE id = :id',
            ['id' => $sessionId]
        );
    }

    public function abortSession(int $sessionId, string $userId): void {
        $session = $this->verifySessionOwnership($sessionId, $userId);

        // Only incomplete sessions can be aborted
        if (!empty($session['completed_at'])) {
            return;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->delete('learning_user_answers')
           ->where($qb->expr()->eq('session_id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT)))
           ->executeStatement();

        $qb2 = $this->db->getQueryBuilder();
        $qb2->delete('learning_sessions')
            ->where($qb2->expr()->eq('id', $qb2->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb2->expr()->eq('user_id', $qb2->createNamedParameter($userId)))
            ->executeStatement();
    }
}
