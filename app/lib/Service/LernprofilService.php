<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCP\ICacheFactory;
use OCP\IDBConnection;

/**
 * LernprofilService — Phase 22: Persönlicher Lernbot
 *
 * Aggregates per-user learning profiles from Leitner boxes,
 * training sessions and exam results. Computed on-the-fly from
 * existing tables; cached in ICache (distributed) for 5 minutes.
 *
 * PROF-01: aggregateProfile — Leitner + Training + Exam aggregation
 * PROF-02: getWeakestTopics — top N pools sorted by error rate
 * PROF-03: getLernhistorie — daily activity + overall trend
 * PROF-04: invalidateCache — called passively after each session/answer
 */
class LernprofilService {
    private IDBConnection $db;
    private ICacheFactory $cacheFactory;

    private const CACHE_TTL = 300; // 5 minutes
    private const CACHE_NAMESPACE = 'learning';
    private const CACHE_PREFIX = 'profile_';
    private const LEGACY_CACHE_PREFIX = 'lernprofil_';

    public function __construct(IDBConnection $db, ICacheFactory $cacheFactory) {
        $this->db = $db;
        $this->cacheFactory = $cacheFactory;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Aggregate the full learning profile for a user.
     *
     * Returns:
     *   pools[] — per-pool stats with leitner, training, exam and computed error_rate
     *   summary  — overall totals
     *   cached_at — unix timestamp of when this result was generated
     */
    public function aggregateProfile(string $userId, ?int $courseId = null): array {
        $cacheKey = $this->profileCacheKey($userId, $courseId);
        $cache = $this->cacheFactory->createDistributed(self::CACHE_NAMESPACE);

        $cached = $cache->get($cacheKey);
        if ($cached !== null && is_array($cached)) {
            return $cached;
        }

        $poolIds = $this->resolvePoolIds($userId, $courseId);

        if (empty($poolIds)) {
            $result = [
                'pools' => [],
                'summary' => [
                    'total_questions' => 0,
                    'total_mastered' => 0,
                    'overall_accuracy' => 0,
                ],
                'cached_at' => time(),
            ];
            $cache->set($cacheKey, $result, self::CACHE_TTL);
            return $result;
        }

        $leitnerStats = $this->getLeitnerStatsByPool($userId, $poolIds);
        $trainingStats = $this->getTrainingStatsByPool($userId, $poolIds);
        $examStats = $this->getExamStatsByPool($userId, $poolIds);
        $poolNames = $this->getPoolNames($poolIds);

        $pools = [];
        foreach ($poolIds as $poolId) {
            $leitner = $leitnerStats[$poolId] ?? [
                'box_1' => 0, 'box_2' => 0, 'box_3' => 0, 'box_4' => 0, 'box_5' => 0,
                'total' => 0, 'mastered' => 0, 'correct_count' => 0, 'incorrect_count' => 0,
            ];
            $training = $trainingStats[$poolId] ?? ['accuracy' => 0, 'sessions' => 0, 'recent_accuracies' => []];
            $exam = $examStats[$poolId] ?? ['avg_score' => 0, 'attempts' => 0, 'last_score' => null];

            // Error rate: weighted blend of Leitner answer history and training accuracy
            $leitnerTotal = (int)$leitner['correct_count'] + (int)$leitner['incorrect_count'];
            $leitnerAccuracy = $leitnerTotal > 0
                ? ((int)$leitner['correct_count'] * 1.0 / $leitnerTotal)
                : 0.0;

            $trainingAccuracy = (float)$training['accuracy'] / 100.0;

            // If we have both sources, blend 50/50; otherwise use whichever is available
            $hasBoth = $leitnerTotal > 0 && $training['sessions'] > 0;
            if ($hasBoth) {
                $blendedAccuracy = ($leitnerAccuracy + $trainingAccuracy) / 2.0;
            } elseif ($leitnerTotal > 0) {
                $blendedAccuracy = $leitnerAccuracy;
            } elseif ($training['sessions'] > 0) {
                $blendedAccuracy = $trainingAccuracy;
            } else {
                $blendedAccuracy = 0.5; // No data — treat as neutral
            }

            $errorRate = round((1.0 - $blendedAccuracy) * 100, 1);

            // Trend from recent training sessions (last 3 vs previous 3)
            $trend = $this->computeTrend($training['recent_accuracies']);

            $pools[] = [
                'pool_id' => $poolId,
                'pool_name' => $poolNames[$poolId] ?? ('Pool ' . $poolId),
                'leitner_stats' => [
                    'box_1' => (int)$leitner['box_1'],
                    'box_2' => (int)$leitner['box_2'],
                    'box_3' => (int)$leitner['box_3'],
                    'box_4' => (int)$leitner['box_4'],
                    'box_5' => (int)$leitner['box_5'],
                    'total' => (int)$leitner['total'],
                    'mastered' => (int)$leitner['mastered'],
                ],
                'training_accuracy' => round($trainingAccuracy * 100, 1),
                'training_sessions' => (int)$training['sessions'],
                'exam_avg_score' => round((float)$exam['avg_score'], 1),
                'exam_attempts' => (int)$exam['attempts'],
                'exam_last_score' => $exam['last_score'] !== null ? round((float)$exam['last_score'], 1) : null,
                'error_rate' => $errorRate,
                'trend' => $trend,
            ];
        }

        // Sort pools by error_rate descending (weakest first)
        usort($pools, fn($a, $b) => $b['error_rate'] <=> $a['error_rate']);

        // Compute overall summary
        $totalQ = 0;
        $totalMastered = 0;
        $allCorrect = 0;
        $allTotal = 0;
        foreach ($pools as $p) {
            $totalQ += $p['leitner_stats']['total'];
            $totalMastered += $p['leitner_stats']['mastered'];
        }
        foreach ($leitnerStats as $ls) {
            $allCorrect += (int)$ls['correct_count'];
            $allTotal += (int)$ls['correct_count'] + (int)$ls['incorrect_count'];
        }
        $overallAccuracy = $allTotal > 0 ? round($allCorrect * 1.0 / $allTotal * 100, 1) : 0.0;

        $result = [
            'pools' => $pools,
            'summary' => [
                'total_questions' => $totalQ,
                'total_mastered' => $totalMastered,
                'overall_accuracy' => $overallAccuracy,
            ],
            'cached_at' => time(),
        ];

        $cache->set($cacheKey, $result, self::CACHE_TTL);
        return $result;
    }

    /**
     * Return the $limit weakest pools sorted by error rate.
     *
     * PROF-02 — identifies the 5 weakest topics/chapters.
     */
    public function getWeakestTopics(string $userId, ?int $courseId = null, int $limit = 5): array {
        $limit = max(1, min($limit, 20));
        $profile = $this->aggregateProfile($userId, $courseId);
        // aggregateProfile already sorts by error_rate DESC
        return array_slice($profile['pools'], 0, $limit);
    }

    /**
     * Return daily learning activity for the last $days days.
     *
     * PROF-03 — learning history + trend indicator.
     *
     * Returns:
     *   history[]    — [{date, sessions_count, questions_answered, accuracy, mode_breakdown}]
     *   overall_trend — 'improving'|'declining'|'stable'
     *   total_sessions — int
     */
    public function getLernhistorie(string $userId, int $days = 30): array {
        $days = max(1, min($days, 365));
        $cutoff = time() - ($days * 86400);

        $qb = $this->db->getQueryBuilder();
        $qb->select('started_at', 'completed_at', 'total_questions', 'correct_answers', 'mode')
           ->from('learning_sessions')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->isNotNull('completed_at'))
           ->andWhere($qb->expr()->gte('completed_at', $qb->createNamedParameter($cutoff)))
           ->orderBy('completed_at', 'ASC')
           ->setMaxResults(500); // safety cap

        $result = $qb->executeQuery();
        $sessions = $result->fetchAll();
        $result->closeCursor();

        // Group by date
        $byDate = [];
        $allAccuracies = [];
        foreach ($sessions as $s) {
            $date = date('Y-m-d', (int)$s['completed_at']);
            if (!isset($byDate[$date])) {
                $byDate[$date] = [
                    'date' => $date,
                    'sessions_count' => 0,
                    'questions_answered' => 0,
                    'correct_answers' => 0,
                    'mode_breakdown' => ['training' => 0, 'exam' => 0],
                ];
            }
            $totalQ = (int)$s['total_questions'];
            $correct = (int)$s['correct_answers'];

            $byDate[$date]['sessions_count']++;
            $byDate[$date]['questions_answered'] += $totalQ;
            $byDate[$date]['correct_answers'] += $correct;

            $mode = in_array($s['mode'], ['training', 'exam'], true) ? $s['mode'] : 'training';
            $byDate[$date]['mode_breakdown'][$mode]++;

            if ($totalQ > 0) {
                $allAccuracies[] = $correct * 1.0 / $totalQ;
            }
        }

        // Compute per-day accuracy and clean up temp field
        $history = [];
        foreach ($byDate as $day) {
            $qa = (int)$day['questions_answered'];
            $ca = (int)$day['correct_answers'];
            $day['accuracy'] = $qa > 0 ? round($ca * 1.0 / $qa * 100, 1) : 0.0;
            unset($day['correct_answers']);
            $history[] = $day;
        }

        // Sort ascending by date
        usort($history, fn($a, $b) => strcmp($a['date'], $b['date']));

        // Overall trend: compare first half vs second half of accuracies
        $overallTrend = $this->computeTrend($allAccuracies);

        return [
            'history' => $history,
            'overall_trend' => $overallTrend,
            'total_sessions' => count($sessions),
            'days' => $days,
        ];
    }

    /**
     * Enrich pool data with course_id and course_name.
     *
     * Looks up learning_course_pools + learning_courses to find which course
     * each pool belongs to. Pools not assigned to any course get course_id = null.
     *
     * @param string $userId
     * @param array $pools - pools from aggregateProfile
     * @return array - pools with added course_id and course_name fields
     */
    public function enrichPoolsWithCourseData(string $userId, array $pools): array {
        if (empty($pools)) {
            return [];
        }

        $poolIds = array_column($pools, 'pool_id');

        // Query course assignments for these pools
        $qb = $this->db->getQueryBuilder();
        $expr = $qb->expr();
        $qb->select('cp.pool_id', 'c.id AS course_id', 'c.name AS course_name')
           ->from('learning_course_pools', 'cp')
           ->innerJoin('cp', 'learning_courses', 'c', $expr->eq('cp.course_id', 'c.id'))
           ->where($expr->in('cp.pool_id', $qb->createNamedParameter(
               $poolIds,
               \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY
           )));

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        // Build pool_id -> course mapping (use first course if pool is in multiple)
        $courseMap = [];
        foreach ($rows as $row) {
            $pid = (int)$row['pool_id'];
            if (!isset($courseMap[$pid])) {
                $courseMap[$pid] = [
                    'course_id' => (int)$row['course_id'],
                    'course_name' => $row['course_name'],
                ];
            }
        }

        // Enrich each pool
        $enriched = [];
        foreach ($pools as $pool) {
            $pid = $pool['pool_id'];
            if (isset($courseMap[$pid])) {
                $pool['course_id'] = $courseMap[$pid]['course_id'];
                $pool['course_name'] = $courseMap[$pid]['course_name'];
            } else {
                $pool['course_id'] = null;
                $pool['course_name'] = null;
            }
            $enriched[] = $pool;
        }

        return $enriched;
    }

    /**
     * Invalidate all cached profile data for a user.
     * Called passively after each session completion or Leitner answer.
     *
     * PROF-04 — passive update trigger.
     */
    public function invalidateCache(string $userId): void {
        $cache = $this->cacheFactory->createDistributed(self::CACHE_NAMESPACE);
        // Remove global cache and any course-specific caches
        // We can't enumerate keys easily, so remove the known patterns
        $cache->remove($this->profileCacheKey($userId, null));
        $cache->remove(self::LEGACY_CACHE_PREFIX . $userId . '_all');
        // For course-specific keys we rely on the TTL (5 min) — acceptable latency
        // A full invalidation would require a user-keyed set; overkill for this use case
    }

    /**
     * Collect trouble-spot questions (accuracy <50%) across all enrolled courses.
     *
     * Returns enriched data per question: text, correct answers, explanation,
     * pool name, chapter, accuracy — sorted by lowest accuracy first.
     *
     * @param string $userId
     * @param int    $limit  Max questions to return (1-100, default 50)
     * @return array{spots: array, course_count: int, total_found: int}
     */
    public function getCheatSheet(string $userId, int $limit = 50): array {
        $limit = max(1, min($limit, 100));

        // 1. Get all accessible pool IDs
        $poolIds = $this->resolvePoolIds($userId, null);
        if (empty($poolIds)) {
            return ['spots' => [], 'course_count' => 0, 'total_found' => 0];
        }

        // 2. Get per-question accuracy from leitner_items (accuracy < 50%)
        $qb = $this->db->getQueryBuilder();
        $qb->select(
                'li.question_id',
                'li.pool_id',
                $qb->createFunction('COALESCE(SUM(li.correct_count), 0) as correct_count'),
                $qb->createFunction('COALESCE(SUM(li.incorrect_count), 0) as incorrect_count')
            )
           ->from('learning_leitner_items', 'li')
           ->where($qb->expr()->eq('li.user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->in('li.pool_id', $qb->createNamedParameter(
               $poolIds,
               \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY
           )))
           ->groupBy('li.question_id', 'li.pool_id');

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        // Filter to accuracy < 50% and need at least 1 attempt
        $troubleItems = [];
        foreach ($rows as $row) {
            $correct = (int)$row['correct_count'];
            $incorrect = (int)$row['incorrect_count'];
            $total = $correct + $incorrect;
            if ($total < 1) {
                continue;
            }
            $accuracy = round($correct / $total * 100, 1);
            if ($accuracy < 50.0) {
                $troubleItems[] = [
                    'question_id' => (int)$row['question_id'],
                    'pool_id' => (int)$row['pool_id'],
                    'accuracy' => $accuracy,
                    'attempts' => $total,
                ];
            }
        }

        // Sort by accuracy ASC (worst first)
        usort($troubleItems, fn($a, $b) => $a['accuracy'] <=> $b['accuracy']);
        $totalFound = count($troubleItems);
        $troubleItems = array_slice($troubleItems, 0, $limit);

        if (empty($troubleItems)) {
            return ['spots' => [], 'course_count' => 0, 'total_found' => 0];
        }

        // 3. Fetch question details (text, explanation, chapter)
        $questionIds = array_column($troubleItems, 'question_id');
        $qb2 = $this->db->getQueryBuilder();
        $qb2->select('id', 'text', 'explanation', 'chapter_key', 'chapter_title', 'pool_id')
            ->from('learning_questions')
            ->where($qb2->expr()->in('id', $qb2->createNamedParameter(
                $questionIds,
                \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY
            )));
        $result2 = $qb2->executeQuery();
        $questionRows = $result2->fetchAll();
        $result2->closeCursor();

        $questionMap = [];
        foreach ($questionRows as $qr) {
            $questionMap[(int)$qr['id']] = $qr;
        }

        // 4. Fetch correct answers for these questions
        $qb3 = $this->db->getQueryBuilder();
        $qb3->select('question_id', 'text')
            ->from('learning_answers')
            ->where($qb3->expr()->in('question_id', $qb3->createNamedParameter(
                $questionIds,
                \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY
            )))
            ->andWhere($qb3->expr()->eq('is_correct', $qb3->createNamedParameter(true, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL)))
            ->orderBy('question_id')
            ->addOrderBy('position');
        $result3 = $qb3->executeQuery();
        $answerRows = $result3->fetchAll();
        $result3->closeCursor();

        $correctAnswers = [];
        foreach ($answerRows as $ar) {
            $correctAnswers[(int)$ar['question_id']][] = $ar['text'];
        }

        // 5. Get pool names and course data
        $poolIdsInResult = array_unique(array_column($troubleItems, 'pool_id'));
        $poolNames = $this->getPoolNames($poolIdsInResult);

        // Pool-to-course mapping
        $qb4 = $this->db->getQueryBuilder();
        $expr4 = $qb4->expr();
        $qb4->select('cp.pool_id', 'c.id AS course_id', 'c.name AS course_name')
            ->from('learning_course_pools', 'cp')
            ->innerJoin('cp', 'learning_courses', 'c', $expr4->eq('cp.course_id', 'c.id'))
            ->where($expr4->in('cp.pool_id', $qb4->createNamedParameter(
                $poolIdsInResult,
                \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY
            )));
        $result4 = $qb4->executeQuery();
        $courseRows = $result4->fetchAll();
        $result4->closeCursor();

        $poolCourseMap = [];
        foreach ($courseRows as $cr) {
            $pid = (int)$cr['pool_id'];
            if (!isset($poolCourseMap[$pid])) {
                $poolCourseMap[$pid] = [
                    'course_id' => (int)$cr['course_id'],
                    'course_name' => $cr['course_name'],
                ];
            }
        }

        // 6. Assemble result
        $courseIds = [];
        $spots = [];
        foreach ($troubleItems as $item) {
            $qid = $item['question_id'];
            $pid = $item['pool_id'];
            $q = $questionMap[$qid] ?? null;
            if ($q === null) {
                continue;
            }

            $courseInfo = $poolCourseMap[$pid] ?? null;
            if ($courseInfo) {
                $courseIds[$courseInfo['course_id']] = true;
            }

            $spots[] = [
                'question_id' => $qid,
                'question_text' => $q['text'] ?? '',
                'correct_answers' => $correctAnswers[$qid] ?? [],
                'explanation' => $q['explanation'] ?? '',
                'pool_id' => $pid,
                'pool_name' => $poolNames[$pid] ?? ('Pool ' . $pid),
                'chapter_key' => $q['chapter_key'] ?? null,
                'chapter_title' => $q['chapter_title'] ?? null,
                'course_name' => $courseInfo['course_name'] ?? null,
                'accuracy' => $item['accuracy'],
                'attempts' => $item['attempts'],
            ];
        }

        return [
            'spots' => $spots,
            'course_count' => count($courseIds),
            'total_found' => $totalFound,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve accessible pool IDs for a user, optionally filtered by course.
     */
    private function resolvePoolIds(string $userId, ?int $courseId): array {
        if ($courseId !== null) {
            // Pools that belong to this course and the user is enrolled in
            $qb = $this->db->getQueryBuilder();
            $expr = $qb->expr();
            $qb->select('cp.pool_id')
               ->from('learning_course_pools', 'cp')
               ->innerJoin('cp', 'learning_courses', 'c', $expr->eq('cp.course_id', 'c.id'))
               ->leftJoin('c', 'learning_course_members', 'cm', $expr->andX(
                   $expr->eq('cm.course_id', 'c.id'),
                   $expr->eq('cm.user_id', $qb->createNamedParameter($userId))
               ))
               ->where($expr->eq('cp.course_id', $qb->createNamedParameter($courseId)))
               ->andWhere($expr->orX(
                   $expr->eq('c.instructor_id', $qb->createNamedParameter($userId)),
                   $expr->isNotNull('cm.id')
               ));

            $result = $qb->executeQuery();
            $rows = $result->fetchAll();
            $result->closeCursor();
            return array_unique(array_column($rows, 'pool_id'));
        }

        // All pools accessible to the user: owned + shared + course-pools
        $ownedIds = $this->getOwnedPoolIds($userId);
        $sharedIds = $this->getSharedPoolIds($userId);
        $coursePoolIds = $this->getCoursePoolIds($userId);

        return array_unique(array_merge($ownedIds, $sharedIds, $coursePoolIds));
    }

    private function getOwnedPoolIds(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from('learning_pools')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        return array_column($rows, 'id');
    }

    private function getSharedPoolIds(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('pool_id')->from('learning_pool_shares')
           ->where($qb->expr()->eq('shared_with', $qb->createNamedParameter($userId)));
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        return array_column($rows, 'pool_id');
    }

    private function getCoursePoolIds(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $expr = $qb->expr();
        $qb->select('cp.pool_id')
           ->from('learning_course_pools', 'cp')
           ->innerJoin('cp', 'learning_courses', 'c', $expr->eq('cp.course_id', 'c.id'))
           ->leftJoin('c', 'learning_course_members', 'cm', $expr->andX(
               $expr->eq('cm.course_id', 'c.id'),
               $expr->eq('cm.user_id', $qb->createNamedParameter($userId))
           ))
           ->where($expr->orX(
               $expr->eq('c.instructor_id', $qb->createNamedParameter($userId)),
               $expr->isNotNull('cm.id')
           ));
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        return array_column($rows, 'pool_id');
    }

    private function getPoolNames(array $poolIds): array {
        if (empty($poolIds)) {
            return [];
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'name')->from('learning_pools')
           ->where($qb->expr()->in('id', $qb->createNamedParameter($poolIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        $names = [];
        foreach ($rows as $row) {
            $names[(int)$row['id']] = $row['name'];
        }
        return $names;
    }

    /**
     * Leitner box distribution + answer history per pool.
     */
    private function getLeitnerStatsByPool(string $userId, array $poolIds): array {
        if (empty($poolIds)) {
            return [];
        }

        // Box distribution
        $qb = $this->db->getQueryBuilder();
        $qb->select('pool_id', 'box',
                    $qb->createFunction('COUNT(*) as cnt'),
                    $qb->createFunction('COALESCE(SUM(correct_count), 0) as correct_count'),
                    $qb->createFunction('COALESCE(SUM(incorrect_count), 0) as incorrect_count'))
           ->from('learning_leitner_items')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->in('pool_id', $qb->createNamedParameter($poolIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
           ->groupBy('pool_id', 'box');

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        $stats = [];
        foreach ($rows as $row) {
            $pid = (int)$row['pool_id'];
            if (!isset($stats[$pid])) {
                $stats[$pid] = [
                    'box_1' => 0, 'box_2' => 0, 'box_3' => 0, 'box_4' => 0, 'box_5' => 0,
                    'total' => 0, 'mastered' => 0,
                    'correct_count' => 0, 'incorrect_count' => 0,
                ];
            }
            $box = (int)$row['box'];
            if ($box >= 1 && $box <= 5) {
                $stats[$pid]['box_' . $box] = (int)$row['cnt'];
            }
            $stats[$pid]['total'] += (int)$row['cnt'];
            $stats[$pid]['correct_count'] += (int)$row['correct_count'];
            $stats[$pid]['incorrect_count'] += (int)$row['incorrect_count'];
        }

        foreach ($stats as $pid => &$s) {
            $s['mastered'] = $s['box_5'];
        }

        return $stats;
    }

    /**
     * Training session accuracy per pool (last 10 completed sessions).
     * Also returns last 6 individual session accuracies for trend computation.
     */
    private function getTrainingStatsByPool(string $userId, array $poolIds): array {
        if (empty($poolIds)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('pool_id', 'correct_answers', 'total_questions', 'completed_at')
           ->from('learning_sessions')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->in('pool_id', $qb->createNamedParameter($poolIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
           ->andWhere($qb->expr()->eq('mode', $qb->createNamedParameter('training')))
           ->andWhere($qb->expr()->isNotNull('completed_at'))
           ->orderBy('completed_at', 'DESC')
           ->setMaxResults(count($poolIds) * 10);

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        // Group by pool, keep last 10 per pool
        $byPool = [];
        foreach ($rows as $row) {
            $pid = (int)$row['pool_id'];
            if (!isset($byPool[$pid])) {
                $byPool[$pid] = [];
            }
            if (count($byPool[$pid]) < 10) {
                $byPool[$pid][] = $row;
            }
        }

        $stats = [];
        foreach ($byPool as $pid => $sessions) {
            $totalQ = 0;
            $totalC = 0;
            $accuracies = [];
            foreach ($sessions as $s) {
                $q = (int)$s['total_questions'];
                $c = (int)$s['correct_answers'];
                $totalQ += $q;
                $totalC += $c;
                if ($q > 0) {
                    $accuracies[] = $c * 1.0 / $q;
                }
            }
            $stats[$pid] = [
                'accuracy' => $totalQ > 0 ? round($totalC * 1.0 / $totalQ * 100, 1) : 0.0,
                'sessions' => count($sessions),
                'recent_accuracies' => $accuracies, // newest first (for trend)
            ];
        }

        return $stats;
    }

    /**
     * Exam scores per pool (last 5 completed exam sessions).
     */
    private function getExamStatsByPool(string $userId, array $poolIds): array {
        if (empty($poolIds)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('pool_id', 'correct_answers', 'total_questions', 'completed_at')
           ->from('learning_sessions')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->in('pool_id', $qb->createNamedParameter($poolIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
           ->andWhere($qb->expr()->eq('mode', $qb->createNamedParameter('exam')))
           ->andWhere($qb->expr()->isNotNull('completed_at'))
           ->orderBy('completed_at', 'DESC')
           ->setMaxResults(count($poolIds) * 5);

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        $byPool = [];
        foreach ($rows as $row) {
            $pid = (int)$row['pool_id'];
            if (!isset($byPool[$pid])) {
                $byPool[$pid] = [];
            }
            if (count($byPool[$pid]) < 5) {
                $byPool[$pid][] = $row;
            }
        }

        $stats = [];
        foreach ($byPool as $pid => $sessions) {
            $totalQ = 0;
            $totalC = 0;
            $lastScore = null;
            foreach ($sessions as $idx => $s) {
                $q = (int)$s['total_questions'];
                $c = (int)$s['correct_answers'];
                $totalQ += $q;
                $totalC += $c;
                if ($idx === 0 && $q > 0) {
                    $lastScore = round($c * 1.0 / $q * 100, 1);
                }
            }
            $stats[$pid] = [
                'avg_score' => $totalQ > 0 ? round($totalC * 1.0 / $totalQ * 100, 1) : 0.0,
                'attempts' => count($sessions),
                'last_score' => $lastScore,
            ];
        }

        return $stats;
    }

    /**
     * Compute trend from an array of accuracy values (newest first or chronological).
     *
     * Splits into two halves; if recent half average is >5% better → 'improving',
     * >5% worse → 'declining', otherwise 'stable'.
     */
    private function computeTrend(array $accuracies): string {
        $n = count($accuracies);
        if ($n < 2) {
            return 'stable';
        }

        $half = (int)ceil($n / 2);
        // Recent values are at the start (newest-first order from DB query)
        $recent = array_slice($accuracies, 0, $half);
        $older = array_slice($accuracies, $half);

        if (empty($older)) {
            return 'stable';
        }

        $recentAvg = array_sum($recent) / count($recent);
        $olderAvg = array_sum($older) / count($older);

        $delta = $recentAvg - $olderAvg;

        if ($delta > 0.05) {
            return 'improving';
        }
        if ($delta < -0.05) {
            return 'declining';
        }
        return 'stable';
    }

    private function profileCacheKey(string $userId, ?int $courseId): string {
        return self::CACHE_PREFIX . $userId . '_' . ($courseId ?? 'all');
    }
}
