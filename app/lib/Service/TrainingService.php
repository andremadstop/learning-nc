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
use OCP\ICacheFactory;
use OCP\IDBConnection;

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

    public function __construct(
        IDBConnection $db,
        QuestionMapper $questionMapper,
        AnswerMapper $answerMapper,
        PoolMapper $poolMapper,
        PoolShareMapper $shareMapper,
        BadgeService $badgeService,
        StreakService $streakService,
        XpService $xpService,
        ICacheFactory $cacheFactory
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
    }

    private function hasPoolAccess(int $poolId, string $userId): bool {
        try {
            $this->poolMapper->find($poolId, $userId);
            return true;
        } catch (DoesNotExistException $e) {
            $share = $this->shareMapper->findByPoolAndUser($poolId, $userId);
            return $share !== null;
        }
    }

    /**
     * Check if user has an active (uncompleted) exam session on a given pool.
     * Used to suppress correct answers even from training sessions during active exam.
     */
    private function hasActiveExamOnPool(int $poolId, string $userId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from('learning_sessions')
           ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId)))
           ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('mode', $qb->createNamedParameter('exam')))
           ->andWhere($qb->expr()->isNull('completed_at'))
           ->setMaxResults(1);
        $result = $qb->execute();
        $hasExam = $result->fetch();
        $result->closeCursor();
        return $hasExam !== false;
    }

    private function verifySessionOwnership(int $sessionId, string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('learning_sessions')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($sessionId)))
           ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $result = $qb->execute();
        $session = $result->fetch();
        $result->closeCursor();

        if (!$session) {
            throw new \Exception('Session not found');
        }
        return $session;
    }

    public function startSession(int $poolId, string $userId, ?int $limit = null, string $mode = 'training'): array {
        if (!in_array($mode, ['training', 'exam'], true)) {
            $mode = 'training';
        }

        if (!$this->hasPoolAccess($poolId, $userId)) {
            throw new \Exception('Pool not found or no access');
        }

        // Block starting any session while user has an active exam on the same pool
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from('learning_sessions')
           ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId)))
           ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('mode', $qb->createNamedParameter('exam')))
           ->andWhere($qb->expr()->isNull('completed_at'))
           ->setMaxResults(1);
        $activeExam = $qb->execute();
        $hasActiveExam = $activeExam->fetch();
        $activeExam->closeCursor();
        if ($hasActiveExam) {
            throw new \Exception('Cannot start session while an exam is active for this pool');
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
            $qb->execute();
        }

        $questions = $this->questionMapper->findByPoolId($poolId);

        if (empty($questions)) {
            throw new \Exception('No questions in this pool');
        }

        shuffle($questions);

        // Apply question limit (for exam mode)
        if ($limit !== null && $limit > 0 && $limit < count($questions)) {
            $questions = array_slice($questions, 0, $limit);
        }

        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_sessions')
           ->values([
               'pool_id' => $qb->createNamedParameter($poolId),
               'user_id' => $qb->createNamedParameter($userId),
               'started_at' => $qb->createNamedParameter(time()),
               'total_questions' => $qb->createNamedParameter(count($questions)),
               'correct_answers' => $qb->createNamedParameter(0),
               'mode' => $qb->createNamedParameter($mode)
           ]);
        $qb->execute();

        $sessionId = $qb->getLastInsertId();

        $questionsWithAnswers = [];
        foreach ($questions as $q) {
            $qData = $q->jsonSerialize();
            $answers = $this->answerMapper->findByQuestion($q->getId());
            $qData['answers'] = array_map(static function ($a) {
                $row = $a->jsonSerialize();
                unset($row['is_correct']);
                return $row;
            }, $answers);
            $questionsWithAnswers[] = $qData;
        }

        return [
            'session_id' => $sessionId,
            'total_questions' => count($questions),
            'questions' => $questionsWithAnswers
        ];
    }

    private function getAllCorrectAnswers(int $questionId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'text')
           ->from('learning_answers')
           ->where($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId)))
           ->andWhere($qb->expr()->eq('is_correct', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
           ->orderBy('position', 'ASC');
        $result = $qb->execute();
        $rows = $result->fetchAll();
        $result->closeCursor();
        return $rows;
    }

    public function submitAnswer(int $sessionId, int $questionId, ?int $answerId, string $userId, ?array $answerIds = null): array {
        $session = $this->verifySessionOwnership($sessionId, $userId);

        // Block submissions to completed sessions
        if (!empty($session['completed_at'])) {
            throw new \Exception('Session already completed');
        }

        // SECURITY: Block individual answer submission during exams (prevents answer oracle)
        if (($session['mode'] ?? 'training') === 'exam') {
            throw new \Exception('Individual answers not allowed during exam');
        }

        // SECURITY: Defense-in-depth — suppress correct answers if user has active exam on same pool
        $poolId = (int)$session['pool_id'];
        $suppressAnswers = $this->hasActiveExamOnPool($poolId, $userId);

        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from('learning_questions')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($questionId)))
           ->andWhere($qb->expr()->eq('pool_id', $qb->createNamedParameter((int)$session['pool_id'])));
        $checkResult = $qb->execute();
        $questionRow = $checkResult->fetch();
        $checkResult->closeCursor();
        if (!$questionRow) {
            throw new \Exception('Question not in this session pool');
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from('learning_user_answers')
           ->where($qb->expr()->eq('session_id', $qb->createNamedParameter($sessionId)))
           ->andWhere($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId)));
        $dupResult = $qb->execute();
        $dupRow = $dupResult->fetch();
        $dupResult->closeCursor();
        if ($dupRow) {
            throw new \Exception('Question already answered in this session');
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
                $vResult = $qb->execute();
                $vRow = $vResult->fetch();
                $vResult->closeCursor();
                if (!$vRow) {
                    throw new \Exception('Answer not found for this question');
                }
            }

            // Get correct answer IDs
            $correctRows = $this->getAllCorrectAnswers($questionId);
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
            $qb->execute();

            if ($isCorrect) {
                $qb = $this->db->getQueryBuilder();
                $qb->update('learning_sessions')
                   ->set('correct_answers', $qb->createFunction('correct_answers + 1'))
                   ->where($qb->expr()->eq('id', $qb->createNamedParameter($sessionId)));
                $qb->execute();
            }

            // SECURITY: Suppress correct answers if active exam on same pool
            if ($suppressAnswers) {
                return ['is_correct' => $isCorrect];
            }

            $correctTexts = array_map(fn($r) => $r['text'], $correctRows);
            return [
                'is_correct' => $isCorrect,
                'correct_answer_id' => !empty($correctIds) ? $correctIds[0] : null,
                'correct_answer_text' => !empty($correctTexts) ? $correctTexts[0] : '',
                'correct_answer_ids' => $correctIds,
                'correct_answer_texts' => $correctTexts,
            ];
        }

        // Single-select path (original logic)
        $qb = $this->db->getQueryBuilder();
        $qb->select('is_correct')
           ->from('learning_answers')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($answerId)))
           ->andWhere($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId)));
        $result = $qb->execute();
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
        $qb->execute();

        if ($isCorrect) {
            $qb = $this->db->getQueryBuilder();
            $qb->update('learning_sessions')
               ->set('correct_answers', $qb->createFunction('correct_answers + 1'))
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($sessionId)));
            $qb->execute();
        }

        // SECURITY: Suppress correct answers if active exam on same pool
        if ($suppressAnswers) {
            return ['is_correct' => $isCorrect];
        }

        // Return all correct answers info
        $correctRows = $this->getAllCorrectAnswers($questionId);
        $correctIds = array_map(fn($r) => (int)$r['id'], $correctRows);
        $correctTexts = array_map(fn($r) => $r['text'], $correctRows);

        return [
            'is_correct' => $isCorrect,
            'correct_answer_id' => !empty($correctIds) ? $correctIds[0] : null,
            'correct_answer_text' => !empty($correctTexts) ? $correctTexts[0] : '',
            'correct_answer_ids' => $correctIds,
            'correct_answer_texts' => $correctTexts,
        ];
    }

    public function submitBatch(int $sessionId, array $answers, string $userId): array {
        $session = $this->verifySessionOwnership($sessionId, $userId);

        // Block submissions to completed sessions
        if (!empty($session['completed_at'])) {
            throw new \Exception('Session already completed');
        }

        $poolId = (int)$session['pool_id'];
        $isExam = (($session['mode'] ?? 'training') === 'exam');

        // SECURITY: Also suppress answers for training sessions if user has active exam on same pool
        $suppressAnswers = $isExam || $this->hasActiveExamOnPool($poolId, $userId);

        // S3: Validate batch size against session's question count
        $maxAnswers = (int)$session['total_questions'];
        if (count($answers) > $maxAnswers) {
            throw new \Exception('Batch size exceeds session question count');
        }

        $results = [];
        foreach ($answers as $entry) {
            $questionId = (int)$entry['questionId'];
            $entryAnswerIds = $entry['answerIds'] ?? null;
            $answerId = isset($entry['answerId']) ? (int)$entry['answerId'] : null;

            // Validate question belongs to this session's pool
            $qb = $this->db->getQueryBuilder();
            $qb->select('id')
               ->from('learning_questions')
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($questionId)))
               ->andWhere($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId)));
            $checkResult = $qb->execute();
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
               ->andWhere($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId)));
            $dupResult = $qb->execute();
            $dupRow = $dupResult->fetch();
            $dupResult->closeCursor();
            if ($dupRow) {
                $results[] = ['questionId' => $questionId, 'error' => 'Already answered'];
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
                    $vResult = $qb->execute();
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
                $qb->execute();

                if ($isCorrect) {
                    $qb = $this->db->getQueryBuilder();
                    $qb->update('learning_sessions')
                       ->set('correct_answers', $qb->createFunction('correct_answers + 1'))
                       ->where($qb->expr()->eq('id', $qb->createNamedParameter($sessionId)));
                    $qb->execute();
                }

                // SECURITY: Suppress correct answers during active exam
                if ($suppressAnswers) {
                    $results[] = ['questionId' => $questionId, 'recorded' => true];
                } else {
                    $correctTexts = array_map(fn($r) => $r['text'], $correctRows);
                    $results[] = [
                        'questionId' => $questionId,
                        'is_correct' => $isCorrect,
                        'correct_answer_id' => !empty($correctIds) ? $correctIds[0] : null,
                        'correct_answer_text' => !empty($correctTexts) ? $correctTexts[0] : '',
                        'correct_answer_ids' => $correctIds,
                        'correct_answer_texts' => $correctTexts,
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
            $result = $qb->execute();
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
            $qb->execute();

            if ($isCorrect) {
                $qb = $this->db->getQueryBuilder();
                $qb->update('learning_sessions')
                   ->set('correct_answers', $qb->createFunction('correct_answers + 1'))
                   ->where($qb->expr()->eq('id', $qb->createNamedParameter($sessionId)));
                $qb->execute();
            }

            // SECURITY: Suppress correct answers during active exam
            if ($suppressAnswers) {
                $results[] = ['questionId' => $questionId, 'recorded' => true];
            } else {
                $correctRows = $this->getAllCorrectAnswers($questionId);
                $correctIds = array_map(fn($r) => (int)$r['id'], $correctRows);
                $correctTexts = array_map(fn($r) => $r['text'], $correctRows);

                $results[] = [
                    'questionId' => $questionId,
                    'is_correct' => $isCorrect,
                    'correct_answer_id' => !empty($correctIds) ? $correctIds[0] : null,
                    'correct_answer_text' => !empty($correctTexts) ? $correctTexts[0] : '',
                    'correct_answer_ids' => $correctIds,
                    'correct_answer_texts' => $correctTexts,
                ];
            }
        }

        return $results;
    }

    public function completeSession(int $sessionId, string $userId): array {
        $session = $this->verifySessionOwnership($sessionId, $userId);

        // Idempotent: already completed sessions return existing data
        if (!empty($session['completed_at'])) {
            $response = [
                'session_id' => (int)$session['id'],
                'total_questions' => (int)$session['total_questions'],
                'correct_answers' => (int)$session['correct_answers'],
                'completed_at' => (int)$session['completed_at'],
            ];
            if (($session['mode'] ?? 'training') === 'exam') {
                $response['review'] = $this->getSessionReview($sessionId);
            }
            $totalQ = (int)$session['total_questions'];
            $response['score_percentage'] = $totalQ > 0
                ? round((int)$session['correct_answers'] / $totalQ * 100)
                : 0;
            return $response;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->update('learning_sessions')
           ->set('completed_at', $qb->createNamedParameter(time()))
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($sessionId)))
           ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $qb->execute();

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('learning_sessions')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($sessionId)))
           ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $result = $qb->execute();
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

        // For exam sessions, include full review data (only available after completion)
        if (($session['mode'] ?? 'training') === 'exam') {
            $response['review'] = $this->getSessionReview($sessionId);
        }

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
        $streak = $this->streakService->getStreak($userId);
        $sessionXp = $this->xpService->calculateSessionXp($sessionData, $streak['current_streak']);
        $response['xp_earned'] = $sessionXp;

        // Update denormalized stats
        $this->xpService->incrementSessionXp($userId, $sessionXp, $streak['current_streak']);

        // Streak badge check
        $streakBadges = $this->badgeService->checkAndAward($userId, 'streak_update', $streak);
        $response['newly_earned_badges'] = array_merge($newBadges, $streakBadges);

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
        $result = $qb->execute();
        $row = $result->fetch();
        $result->closeCursor();
        return round((float)($row['avg_pct'] ?? 0));
    }

    private function getSessionReview(int $sessionId): array {
        // Fetch all user answers for this session
        $qb = $this->db->getQueryBuilder();
        $qb->select('ua.*', 'q.text AS question_text', 'q.question_type')
           ->from('learning_user_answers', 'ua')
           ->innerJoin('ua', 'learning_questions', 'q', $qb->expr()->eq('ua.question_id', 'q.id'))
           ->where($qb->expr()->eq('ua.session_id', $qb->createNamedParameter($sessionId)))
           ->orderBy('ua.id', 'ASC');
        $result = $qb->execute();
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
            $aResult = $qb->execute();
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

            $review[] = $entry;
        }

        return $review;
    }
}
