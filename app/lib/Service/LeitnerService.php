<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\PoolShareMapper;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\LernprofilService;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\XpService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;

class LeitnerService {
    /** Standard Leitner intervals: 0, 1d, 3d, 7d, 14d */
    private const NORMAL_INTERVALS = [1 => 0, 2 => 86400, 3 => 259200, 4 => 604800, 5 => 1209600];
    /** Sprint intervals for intensive courses: 0, 4h, 12h, 1d, 2d */
    private const SPRINT_INTERVALS = [1 => 0, 2 => 14400, 3 => 43200, 4 => 86400, 5 => 172800];

    private IDBConnection $db;
    private PoolMapper $poolMapper;
    private PoolShareMapper $shareMapper;
    private BadgeService $badgeService;
    private StreakService $streakService;
    private XpService $xpService;
    private ICacheFactory $cacheFactory;
    private TranslationService $translationService;
    private IConfig $config;
    private CourseService $courseService;
    private LernprofilService $lernprofilService;

    public function __construct(IDBConnection $db, PoolMapper $poolMapper, PoolShareMapper $shareMapper, BadgeService $badgeService, StreakService $streakService, XpService $xpService, ICacheFactory $cacheFactory, TranslationService $translationService, IConfig $config, CourseService $courseService, LernprofilService $lernprofilService) {
        $this->db = $db;
        $this->poolMapper = $poolMapper;
        $this->shareMapper = $shareMapper;
        $this->badgeService = $badgeService;
        $this->streakService = $streakService;
        $this->xpService = $xpService;
        $this->cacheFactory = $cacheFactory;
        $this->translationService = $translationService;
        $this->config = $config;
        $this->courseService = $courseService;
        $this->lernprofilService = $lernprofilService;
    }

    private function resolveCourseQuestionIds(?int $courseId, int $poolId, string $userId): ?array {
        if ($courseId === null) {
            return null;
        }
        return $this->courseService->resolveCoursePoolContext($courseId, $poolId, $userId)['question_ids'];
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
     * Check if any course using this pool has leitner_sprint enabled.
     * Returns true if at least one linked course has the sprint flag set.
     * Falls back to false for standalone (non-course) pools.
     */
    private function isSprintPool(int $poolId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('c.leitner_sprint')
           ->from('learning_course_pools', 'cp')
           ->innerJoin('cp', 'learning_courses', 'c', $qb->expr()->eq('cp.course_id', 'c.id'))
           ->where($qb->expr()->eq('cp.pool_id', $qb->createNamedParameter($poolId)))
           ->andWhere($qb->expr()->eq('c.leitner_sprint', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
           ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return $row !== false;
    }

    /**
     * Check if user has an active (uncompleted) exam session on a given pool.
     * Used to suppress correct answers in Leitner responses to prevent exam oracle attacks.
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
        $result = $qb->executeQuery();
        $hasExam = $result->fetch();
        $result->closeCursor();
        return $hasExam !== false;
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

    private function translateQueueItems(array $items, ?string $lang): array {
        return $this->translationService->translateQuestions($items, $lang);
    }

    public function getSmartQueue(string $userId, int $limit = 30, ?string $lang = null): array {
        $limit = max(1, min($limit, 100));
        $now = time();
        $contentLanguage = $this->resolveContentLanguage($lang, $userId);

        $qb = $this->db->getQueryBuilder();
        $qb->select('l.*', 'q.text', 'q.explanation', 'q.difficulty', 'q.question_type',
                     'q.pbq_subtype', 'q.pbq_config', 'p.name AS pool_name')
           ->from('learning_leitner_items', 'l')
           ->innerJoin('l', 'learning_questions', 'q', 'l.question_id = q.id')
           ->innerJoin('l', 'learning_pools', 'p', 'l.pool_id = p.id')
           ->where($qb->expr()->eq('l.user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->lte('l.next_review', $qb->createNamedParameter($now)))
           ->orderBy('l.box', 'ASC')
           ->addOrderBy('l.next_review', 'ASC')
           ->setMaxResults($limit);

        $result = $qb->executeQuery();
        $items = $result->fetchAll();
        $result->closeCursor();

        if (!empty($items)) {
            $questionIds = array_unique(array_column($items, 'question_id'));
            $aqb = $this->db->getQueryBuilder();
            $aqb->select('id', 'question_id', 'text', 'is_correct', 'position')
               ->from('learning_answers')
               ->where($aqb->expr()->in('question_id', $aqb->createNamedParameter($questionIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
               ->orderBy('position', 'ASC');
            $aResult = $aqb->executeQuery();
            $allAnswers = $aResult->fetchAll();
            $aResult->closeCursor();

            $answersByQuestion = [];
            foreach ($allAnswers as $answer) {
                $answersByQuestion[$answer['question_id']][] = $answer;
            }

            foreach ($items as &$item) {
                $item['answers'] = $answersByQuestion[$item['question_id']] ?? [];
                if (isset($item['pbq_config']) && is_string($item['pbq_config'])) {
                    $item['pbq_config'] = json_decode($item['pbq_config'], true) ?: null;
                }
            }
            $this->stripOpenAnswers($items);
        }

        return $this->translateQueueItems($items, $contentLanguage);
    }

    public function getQueueCount(string $userId): int {
        $now = time();
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*) as cnt'))
           ->from('learning_leitner_items')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->lte('next_review', $qb->createNamedParameter($now)));
        $result = $qb->executeQuery();
        $count = (int)$result->fetch()['cnt'];
        $result->closeCursor();
        return $count;
    }

    public function getDueQuestions(int $poolId, string $userId, int $limit = 10, ?string $lang = null, ?int $courseId = null): array {
        if (!$this->hasPoolAccess($poolId, $userId)) {
            throw new \Exception('Pool not found or no access');
        }

        $limit = max(1, min($limit, 100));
        $contentLanguage = $this->resolveContentLanguage($lang, $userId);
        $courseQuestionIds = $this->resolveCourseQuestionIds($courseId, $poolId, $userId);
        if ($courseQuestionIds === []) {
            return [];
        }

        $now = time();

        $qb = $this->db->getQueryBuilder();
        $qb->select('l.*', 'q.text', 'q.explanation', 'q.difficulty', 'q.question_type',
                     'q.pbq_subtype', 'q.pbq_config')
           ->from('learning_leitner_items', 'l')
           ->innerJoin('l', 'learning_questions', 'q', 'l.question_id = q.id')
           ->where($qb->expr()->eq('l.user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('l.pool_id', $qb->createNamedParameter($poolId)))
           ->andWhere($qb->expr()->lte('l.next_review', $qb->createNamedParameter($now)))
           ->orderBy('l.next_review', 'ASC')
           ->setMaxResults($limit);
        if (is_array($courseQuestionIds)) {
            $qb->andWhere($qb->expr()->in('l.question_id', $qb->createNamedParameter($courseQuestionIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
        }

        $result = $qb->executeQuery();
        $items = $result->fetchAll();
        $result->closeCursor();

        // FIX-LO-2: Batch-load answers for all questions at once instead of N+1
        if (!empty($items)) {
            $questionIds = array_unique(array_column($items, 'question_id'));
            $aqb = $this->db->getQueryBuilder();
            $aqb->select('id', 'question_id', 'text', 'is_correct', 'position')
               ->from('learning_answers')
               ->where($aqb->expr()->in('question_id', $aqb->createNamedParameter($questionIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
               ->orderBy('position', 'ASC');
            $aResult = $aqb->executeQuery();
            $allAnswers = $aResult->fetchAll();
            $aResult->closeCursor();

            // Group answers by question_id
            $answersByQuestion = [];
            foreach ($allAnswers as $answer) {
                $answersByQuestion[$answer['question_id']][] = $answer;
            }

            foreach ($items as &$item) {
                $item['answers'] = $answersByQuestion[$item['question_id']] ?? [];
                if (isset($item['pbq_config']) && is_string($item['pbq_config'])) {
                    $item['pbq_config'] = json_decode($item['pbq_config'], true) ?: null;
                }
            }
            $this->stripOpenAnswers($items);
        }

        return $this->translateQueueItems($items, $contentLanguage);
    }

    public function answerQuestion(int $itemId, ?int $answerId, string $userId, ?array $answerIds = null, ?string $answerText = null, ?array $pbqAnswers = null, ?string $lang = null): array {
        // === Read phase (outside transaction) ===
        // Read streak before transaction (read-only, safe outside)
        $streak = $this->streakService->getStreak($userId);
        $streakDays = $streak['current_streak'];
        $contentLanguage = $this->resolveContentLanguage($lang, $userId);

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('learning_leitner_items')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($itemId)))
           ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $result = $qb->executeQuery();
        $item = $result->fetch();
        $result->closeCursor();

        if (!$item) {
            throw new \Exception('Item not found');
        }

        $questionId = (int)$item['question_id'];

        // Open-question path
        $qType = $this->getQuestionType($questionId);
        if ($qType === 'pbq') {
            $pbqAnswers = $pbqAnswers ?? [];
            $qb = $this->db->getQueryBuilder();
            $qb->select('pbq_subtype', 'pbq_config')
               ->from('learning_questions')
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($questionId)));
            $pResult = $qb->executeQuery();
            $pRow = $pResult->fetch();
            $pResult->closeCursor();
            [$correct, $pbqPoints, $pbqMaxPoints] = $this->scorePbqAnswer(
                $pRow['pbq_subtype'] ?? '',
                json_decode($pRow['pbq_config'] ?? '{}', true) ?: [],
                $pbqAnswers
            );
        } elseif ($qType === 'open') {
            if ($answerText === null || trim($answerText) === '') {
                throw new \Exception('Answer text required for open questions');
            }
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'text')
               ->from('learning_answers')
               ->where($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId)))
               ->andWhere($qb->expr()->eq('is_correct', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
               ->orderBy('position', 'ASC');
            $cResult = $qb->executeQuery();
            $correctRows = $cResult->fetchAll();
            $cResult->closeCursor();
            $modelText = $correctRows[0]['text'] ?? '';
            $correct = QuestionService::isOpenAnswerCorrect($answerText, $modelText);
        }
        // Multi-select path — batch validation (Gemini #3: N+1 fix)
        elseif (is_array($answerIds) && count($answerIds) > 0) {
            $intIds = array_map('intval', $answerIds);
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('COUNT(*) as cnt'))
               ->from('learning_answers')
               ->where($qb->expr()->in('id', $qb->createNamedParameter($intIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
               ->andWhere($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId)));
            $vResult = $qb->executeQuery();
            $validCount = (int)$vResult->fetch()['cnt'];
            $vResult->closeCursor();
            if ($validCount !== count($intIds)) {
                throw new \Exception('Answer not found for this question');
            }

            // Get correct answer IDs
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'text')
               ->from('learning_answers')
               ->where($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId)))
               ->andWhere($qb->expr()->eq('is_correct', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
               ->orderBy('position', 'ASC');
            $cResult = $qb->executeQuery();
            $correctRows = $cResult->fetchAll();
            $cResult->closeCursor();

            $correctIds = array_map(fn($r) => (int)$r['id'], $correctRows);
            sort($correctIds);
            sort($intIds);
            $correct = ($intIds === $correctIds);
        } else {
            // Single-select path
            $qb = $this->db->getQueryBuilder();
            $qb->select('is_correct')
               ->from('learning_answers')
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($answerId)))
               ->andWhere($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId)));
            $ansResult = $qb->executeQuery();
            $ansRow = $ansResult->fetch();
            $ansResult->closeCursor();

            if (!$ansRow) {
                throw new \Exception('Answer not found for this question');
            }

            $correct = filter_var($ansRow['is_correct'], FILTER_VALIDATE_BOOLEAN);
        }

        $currentBox = (int)$item['box'];
        $newBox = $correct ? min(5, $currentBox + 1) : 1;

        $intervals = $this->isSprintPool((int)$item['pool_id'])
            ? self::SPRINT_INTERVALS
            : self::NORMAL_INTERVALS;
        $nextReview = time() + $intervals[$newBox];

        $correctCount = (int)$item['correct_count'] + ($correct ? 1 : 0);
        $incorrectCount = (int)$item['incorrect_count'] + ($correct ? 0 : 1);

        // Read level before XP increment for level-up detection
        $levelBefore = $this->xpService->calculateXp($userId)['level'];

        $response = [
            'old_box' => $currentBox,
            'new_box' => $newBox,
            'next_review' => $nextReview,
            'correct' => $correct,
            'newly_earned_badges' => [],
            'level_before' => $levelBefore,
            'level_after' => $levelBefore,
            'pbq_points' => $pbqPoints ?? null,
            'pbq_max_points' => $pbqMaxPoints ?? null,
        ];

        // === Write phase (all atomic) ===
        $this->db->beginTransaction();
        try {
            // Optimistic update on Leitner item — guards box AND correct_count for Box-5→5 safety (R2 #1+#2)
            $qb = $this->db->getQueryBuilder();
            $qb->update('learning_leitner_items')
               ->set('box', $qb->createNamedParameter($newBox))
               ->set('next_review', $qb->createNamedParameter($nextReview))
               ->set('last_reviewed', $qb->createNamedParameter(time()))
               ->set('correct_count', $qb->createNamedParameter($correctCount))
               ->set('incorrect_count', $qb->createNamedParameter($incorrectCount))
               ->where($qb->expr()->eq('id', $qb->createNamedParameter($itemId)))
               ->andWhere($qb->expr()->eq('box', $qb->createNamedParameter($currentBox)))
               ->andWhere($qb->expr()->eq('correct_count', $qb->createNamedParameter((int)$item['correct_count'])))
               ->andWhere($qb->expr()->eq('incorrect_count', $qb->createNamedParameter((int)$item['incorrect_count'])));
            $affected = $qb->executeStatement();

            if ($affected === 0) {
                // R2 #3: single rollback point — let catch handle it
                throw new \Exception('Concurrent modification — please retry');
            }

            // Demotion handling AFTER item update (recalc sees correct box value)
            if ($currentBox === 5 && $newBox < 5) {
                $dqb = $this->db->getQueryBuilder();
                $dqb->update('learning_user_stats')
                    ->set('total_mastered', $dqb->createFunction('CASE WHEN total_mastered > 0 THEN total_mastered - 1 ELSE 0 END'))
                    ->set('updated_at', $dqb->createNamedParameter(time()))
                    ->where($dqb->expr()->eq('user_id', $dqb->createNamedParameter($userId)));
                $demoted = $dqb->executeStatement();

                if ($demoted === 0) {
                    $this->xpService->updateUserStats($userId);
                }
            }

            // Award Leitner XP: 5 XP per correct answer (with streak multiplier)
            if ($correct) {
                $leitnerXp = $this->xpService->applyMultiplier(5, $streakDays);

                // R2 #1: Only award mastery bonus on actual promotion (Box <5 → 5), not Box 5→5
                if ($currentBox < 5 && $newBox === 5) {
                    $leitnerXp += $this->xpService->applyMultiplier(25, $streakDays);
                    // DB-only badge insert, no notifications (post-commit pattern)
                    $response['newly_earned_badges'] = $this->badgeService->checkAndAward($userId, 'leitner_mastery', [], false);

                    // Increment denormalized mastered count
                    $mqb = $this->db->getQueryBuilder();
                    $mqb->update('learning_user_stats')
                        ->set('total_mastered', $mqb->createFunction('total_mastered + 1'))
                        ->set('updated_at', $mqb->createNamedParameter(time()))
                        ->where($mqb->expr()->eq('user_id', $mqb->createNamedParameter($userId)));
                    $mqb->executeStatement();
                }

                // Skip syncLevel inside transaction (defer to after commit)
                $this->xpService->incrementLeitnerXp($userId, $leitnerXp, true);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // === Post-commit side effects ===

        // Sync level after XP increment (Gemini #2: outside transaction)
        if ($correct) {
            $this->xpService->syncLevel($userId);
            $response['level_after'] = $this->xpService->calculateXp($userId)['level'];
        }

        // Daily goal XP bonus: +10 XP when daily goal reached for the first time today
        $todayStart = strtotime('today midnight');
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*) as cnt'))
           ->from('learning_leitner_items')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->gte('last_reviewed', $qb->createNamedParameter($todayStart)));
        $result = $qb->executeQuery();
        $cardsToday = (int)$result->fetch()['cnt'];
        $result->closeCursor();

        // Check daily goal
        $qb = $this->db->getQueryBuilder();
        $qb->select('daily_goal')
           ->from('learning_user_stats')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $result = $qb->executeQuery();
        $goalRow = $result->fetch();
        $result->closeCursor();
        $dailyGoal = (int)($goalRow['daily_goal'] ?? 20);

        // Award bonus exactly when crossing the threshold (cardsToday == dailyGoal)
        if ($cardsToday === $dailyGoal) {
            $goalBonus = $this->xpService->applyMultiplier(10, $streakDays);
            $this->xpService->incrementLeitnerXp($userId, $goalBonus);
        }

        // Dispatch badge notifications after commit (Codex #2)
        if (!empty($response['newly_earned_badges'])) {
            $this->badgeService->dispatchNotifications($userId, $response['newly_earned_badges']);
        }

        // Invalidate cache AFTER commit
        $this->cacheFactory->createDistributed('learning')->remove('user_state_' . $userId);

        // PROF-04: Passively invalidate Lernprofil cache so next GET /api/profile is fresh
        $this->lernprofilService->invalidateCache($userId);

        // SECURITY: Suppress correct answer details during active exam to prevent oracle attack
        $poolId = (int)$item['pool_id'];
        if ($this->hasActiveExamOnPool($poolId, $userId)) {
            return $response;
        }

        // Open-question: return model answer text
        if ($qType === 'open') {
            $qb = $this->db->getQueryBuilder();
            $qb->select('text')
               ->from('learning_answers')
               ->where($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId)))
               ->andWhere($qb->expr()->eq('is_correct', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)));
            $oResult = $qb->executeQuery();
            $oRows = $oResult->fetchAll();
            $oResult->closeCursor();
            $oRows = $this->translationService->translateAnswerRows($oRows, $contentLanguage);
            $response['correct_answer_text'] = $oRows[0]['text'] ?? '';
            $response['user_answer_text'] = $answerText;
            return $response;
        }

        // Return all correct answers for frontend display (only when no active exam)
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'text')
           ->from('learning_answers')
           ->where($qb->expr()->eq('question_id', $qb->createNamedParameter($questionId)))
           ->andWhere($qb->expr()->eq('is_correct', $qb->createNamedParameter(true, \PDO::PARAM_BOOL)))
           ->orderBy('position', 'ASC');
        $correctResult = $qb->executeQuery();
        $allCorrectRows = $correctResult->fetchAll();
        $correctResult->closeCursor();
        $translatedCorrectRows = $this->translationService->translateAnswerRows($allCorrectRows, $contentLanguage);

        $allCorrectIds = array_map(fn($r) => (int)$r['id'], $allCorrectRows);
        $allCorrectTexts = array_map(fn($r) => $r['text'], $translatedCorrectRows);

        $response['correct_answer_id'] = !empty($allCorrectIds) ? $allCorrectIds[0] : null;
        $response['correct_answer_text'] = !empty($allCorrectTexts) ? $allCorrectTexts[0] : '';
        $response['correct_answer_ids'] = $allCorrectIds;
        $response['correct_answer_texts'] = $allCorrectTexts;

        return $response;
    }

    public function getRemediationQueue(string $userId, int $limit = 20, ?string $lang = null): array {
        $limit = max(1, min($limit, 100));
        $contentLanguage = $this->resolveContentLanguage($lang, $userId);

        $qb = $this->db->getQueryBuilder();
        $qb->select('l.*', 'q.text', 'q.explanation', 'q.difficulty', 'q.question_type',
                     'q.pbq_subtype', 'q.pbq_config', 'p.name AS pool_name')
           ->from('learning_leitner_items', 'l')
           ->innerJoin('l', 'learning_questions', 'q', 'l.question_id = q.id')
           ->innerJoin('l', 'learning_pools', 'p', 'l.pool_id = p.id')
           ->where($qb->expr()->eq('l.user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->gte('l.incorrect_count', $qb->createNamedParameter(3)))
           ->andWhere($qb->expr()->lte('l.box', $qb->createNamedParameter(2)))
           ->orderBy('l.incorrect_count', 'DESC')
           ->addOrderBy('l.last_reviewed', 'ASC')
           ->setMaxResults($limit);

        $result = $qb->executeQuery();
        $items = $result->fetchAll();
        $result->closeCursor();

        // Filter by <30% accuracy in PHP (avoids platform-specific float division in SQL)
        $items = array_values(array_filter($items, function ($item) {
            $total = (int)$item['correct_count'] + (int)$item['incorrect_count'];
            if ($total === 0) return false;
            return ((int)$item['correct_count'] / $total) < 0.3;
        }));

        if (!empty($items)) {
            $questionIds = array_unique(array_column($items, 'question_id'));
            $aqb = $this->db->getQueryBuilder();
            $aqb->select('id', 'question_id', 'text', 'is_correct', 'position')
               ->from('learning_answers')
               ->where($aqb->expr()->in('question_id', $aqb->createNamedParameter($questionIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
               ->orderBy('position', 'ASC');
            $aResult = $aqb->executeQuery();
            $allAnswers = $aResult->fetchAll();
            $aResult->closeCursor();

            $answersByQuestion = [];
            foreach ($allAnswers as $answer) {
                $answersByQuestion[$answer['question_id']][] = $answer;
            }

            foreach ($items as &$item) {
                $item['answers'] = $answersByQuestion[$item['question_id']] ?? [];
                if (isset($item['pbq_config']) && is_string($item['pbq_config'])) {
                    $item['pbq_config'] = json_decode($item['pbq_config'], true) ?: null;
                }
            }
            $this->stripOpenAnswers($items);
        }

        return $this->translateQueueItems($items, $contentLanguage);
    }

    public function getRemediationCount(string $userId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('correct_count', 'incorrect_count')
           ->from('learning_leitner_items')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->gte('incorrect_count', $qb->createNamedParameter(3)))
           ->andWhere($qb->expr()->lte('box', $qb->createNamedParameter(2)));
        $result = $qb->executeQuery();
        $count = 0;
        while ($row = $result->fetch()) {
            $total = (int)$row['correct_count'] + (int)$row['incorrect_count'];
            if ($total > 0 && ((int)$row['correct_count'] / $total) < 0.3) {
                $count++;
            }
        }
        $result->closeCursor();
        return $count;
    }

    public function initializePool(int $poolId, string $userId, ?int $courseId = null): int {
        if (!$this->hasPoolAccess($poolId, $userId)) {
            throw new \Exception('Pool not found or no access');
        }

        $courseQuestionIds = $this->resolveCourseQuestionIds($courseId, $poolId, $userId);
        if ($courseQuestionIds === []) {
            return 0;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('q.id')
           ->from('learning_questions', 'q')
           ->leftJoin('q', 'learning_leitner_items', 'l',
                      $qb->expr()->andX(
                          $qb->expr()->eq('l.question_id', 'q.id'),
                          $qb->expr()->eq('l.user_id', $qb->createNamedParameter($userId))
                      ))
           ->where($qb->expr()->eq('q.pool_id', $qb->createNamedParameter($poolId)))
           ->andWhere($qb->expr()->isNull('l.id'));
        if (is_array($courseQuestionIds)) {
            $qb->andWhere($qb->expr()->in('q.id', $qb->createNamedParameter($courseQuestionIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
        }

        $result = $qb->executeQuery();
        $questions = $result->fetchAll();
        $result->closeCursor();

        $count = 0;
        foreach ($questions as $question) {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('learning_leitner_items')
               ->values([
                   'user_id' => $qb->createNamedParameter($userId),
                   'pool_id' => $qb->createNamedParameter($poolId),
                   'question_id' => $qb->createNamedParameter($question['id']),
                   'box' => $qb->createNamedParameter(1),
                   'next_review' => $qb->createNamedParameter(time()),
                   'correct_count' => $qb->createNamedParameter(0),
                   'incorrect_count' => $qb->createNamedParameter(0)
               ]);
            $qb->executeStatement();
            $count++;
        }

        return $count;
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

    private function stripOpenAnswers(array &$items): void {
        foreach ($items as &$item) {
            if (($item['question_type'] ?? 'single') === 'open') {
                foreach ($item['answers'] as &$a) {
                    $a['text'] = '';
                }
            }
        }
    }

    public function getStats(int $poolId, string $userId, ?int $courseId = null): array {
        if (!$this->hasPoolAccess($poolId, $userId)) {
            throw new \Exception('Pool not found or no access');
        }

        $courseQuestionIds = $this->resolveCourseQuestionIds($courseId, $poolId, $userId);
        if ($courseQuestionIds === []) {
            return [
                'box_1' => 0,
                'box_2' => 0,
                'box_3' => 0,
                'box_4' => 0,
                'box_5' => 0,
                'total' => 0,
                'mastered' => 0,
                'mastery_percentage' => 0,
                'due_count' => 0,
                'total_correct' => 0,
                'total_answered' => 0,
                'accuracy' => 0,
            ];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('box', $qb->createFunction('COUNT(*) as count'))
           ->from('learning_leitner_items')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId)))
           ->groupBy('box');
        if (is_array($courseQuestionIds)) {
            $qb->andWhere($qb->expr()->in('question_id', $qb->createNamedParameter($courseQuestionIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
        }

        $result = $qb->executeQuery();
        $boxes = $result->fetchAll();
        $result->closeCursor();

        $stats = ['box_1' => 0, 'box_2' => 0, 'box_3' => 0, 'box_4' => 0, 'box_5' => 0];
        foreach ($boxes as $box) {
            $stats['box_' . $box['box']] = (int)$box['count'];
        }

        $total = array_sum($stats);
        $stats['total'] = $total;
        $stats['mastered'] = $stats['box_5'];
        $stats['mastery_percentage'] = $total > 0 ? round($stats['box_5'] / $total * 100) : 0;

        $now = time();
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*) as due_count'))
           ->from('learning_leitner_items')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId)))
           ->andWhere($qb->expr()->lte('next_review', $qb->createNamedParameter($now)));
        if (is_array($courseQuestionIds)) {
            $qb->andWhere($qb->expr()->in('question_id', $qb->createNamedParameter($courseQuestionIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
        }
        $result = $qb->executeQuery();
        $stats['due_count'] = (int)$result->fetch()['due_count'];
        $result->closeCursor();

        $qb = $this->db->getQueryBuilder();
        $qb->select(
               $qb->createFunction('COALESCE(SUM(correct_count), 0) as total_correct'),
               $qb->createFunction('COALESCE(SUM(correct_count + incorrect_count), 0) as total_answered')
           )
           ->from('learning_leitner_items')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId)));
        if (is_array($courseQuestionIds)) {
            $qb->andWhere($qb->expr()->in('question_id', $qb->createNamedParameter($courseQuestionIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
        }
        $result = $qb->executeQuery();
        $accRow = $result->fetch();
        $result->closeCursor();
        $stats['total_correct'] = (int)$accRow['total_correct'];
        $stats['total_answered'] = (int)$accRow['total_answered'];
        $stats['accuracy'] = $stats['total_answered'] > 0
            ? round($stats['total_correct'] / $stats['total_answered'] * 100)
            : 0;

        return $stats;
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
                            // Intentionally ignored: invalid regex pattern in PBQ config — skip award
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
                            // Intentionally ignored: invalid regex pattern in PBQ config — skip award
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
}
