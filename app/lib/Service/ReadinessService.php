<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\CoursePool;
use OCA\Learning\Db\CoursePoolMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class ReadinessService {
    private CourseService $courseService;
    private CoursePoolMapper $coursePoolMapper;
    private IDBConnection $db;

    public function __construct(
        CourseService $courseService,
        CoursePoolMapper $coursePoolMapper,
        IDBConnection $db
    ) {
        $this->courseService = $courseService;
        $this->coursePoolMapper = $coursePoolMapper;
        $this->db = $db;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCourseReadiness(int $courseId, string $userId): array {
        $course = $this->courseService->findById($courseId, $userId);
        $examDate = (string)($course['exam_date'] ?? '');
        $examTimestamp = $this->parseExamDate($examDate);
        if ($examTimestamp === null) {
            throw new \RuntimeException('Course has no exam date');
        }

        $targetTimestamp = max(time(), $examTimestamp);
        $allCoursePools = $this->coursePoolMapper->findByCourse($courseId);
        $examRelevantPools = array_filter($allCoursePools, static fn(CoursePool $pool): bool => (bool)$pool->getExamRelevant());
        // If no pool is marked exam_relevant, fall back to all pools (backward compat)
        $coursePools = $examRelevantPools !== [] ? array_values($examRelevantPools) : $allCoursePools;
        $poolNames = $this->getPoolNames(array_map(static fn(CoursePool $pool): int => $pool->getPoolId(), $coursePools));

        $courseReadinessTotal = 0.0;
        $courseWeight = 0;
        $poolReadiness = [];

        foreach ($coursePools as $coursePool) {
            $poolId = $coursePool->getPoolId();
            $questionIds = $this->getFilteredQuestionIdsForCoursePool($courseId, $coursePool);
            $metrics = $this->calculatePoolReadiness($userId, $poolId, $questionIds, $targetTimestamp);

            $courseReadinessTotal += $metrics['readiness'] * $metrics['question_count'];
            $courseWeight += $metrics['question_count'];

            $poolReadiness[] = [
                'pool_id' => $poolId,
                'pool_name' => (string)($poolNames[$poolId] ?? ('Pool #' . $poolId)),
                'question_count' => $metrics['question_count'],
                'reviewed_count' => $metrics['reviewed_count'],
                'readiness_percent' => (int)round($metrics['readiness'] * 100),
            ];
        }

        usort($poolReadiness, static function (array $left, array $right): int {
            if ($left['readiness_percent'] !== $right['readiness_percent']) {
                return $left['readiness_percent'] <=> $right['readiness_percent'];
            }
            return strcasecmp((string)$left['pool_name'], (string)$right['pool_name']);
        });

        $readiness = $courseWeight > 0 ? ($courseReadinessTotal / $courseWeight) : 0.0;
        $daysUntilExam = max(0, (int)ceil(($examTimestamp - time()) / 86400));

        return [
            'course_id' => $courseId,
            'exam_date' => $examDate,
            'days_until_exam' => $daysUntilExam,
            'readiness_percent' => (int)round($readiness * 100),
            'status' => $readiness >= 0.75 ? 'on_track' : 'needs_practice',
            'question_count' => $courseWeight,
            'pool_readiness' => $poolReadiness,
        ];
    }

    /**
     * @param int[] $questionIds
     * @return array{readiness: float, question_count: int, reviewed_count: int}
     */
    private function calculatePoolReadiness(string $userId, int $poolId, array $questionIds, int $targetTimestamp): array {
        if ($questionIds === []) {
            return [
                'readiness' => 0.0,
                'question_count' => 0,
                'reviewed_count' => 0,
            ];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('question_id', 'stability', 'difficulty', 'last_reviewed')
            ->from('learning_leitner_items')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->in('question_id', $qb->createNamedParameter($questionIds, IQueryBuilder::PARAM_INT_ARRAY)));

        $result = $qb->executeQuery();
        $itemsByQuestionId = [];
        while ($row = $result->fetch()) {
            $itemsByQuestionId[(int)$row['question_id']] = [
                'stability' => $row['stability'] !== null ? (float)$row['stability'] : null,
                'difficulty' => $row['difficulty'] !== null ? (float)$row['difficulty'] : null,
                'last_reviewed' => $row['last_reviewed'] !== null ? (int)$row['last_reviewed'] : null,
            ];
        }
        $result->closeCursor();

        $sum = 0.0;
        $reviewedCount = 0;
        foreach ($questionIds as $questionId) {
            $item = $itemsByQuestionId[$questionId] ?? null;
            if ($item === null) {
                continue;
            }

            $stability = (float)($item['stability'] ?? 0.0);
            $lastReviewed = (int)($item['last_reviewed'] ?? 0);
            if ($stability <= 0.0 || $lastReviewed <= 0) {
                continue;
            }

            $elapsedDays = max(0.0, ($targetTimestamp - $lastReviewed) / 86400);
            $sum += exp(-($elapsedDays / max(0.1, $stability)));
            $reviewedCount++;
        }

        return [
            'readiness' => $sum / count($questionIds),
            'question_count' => count($questionIds),
            'reviewed_count' => $reviewedCount,
        ];
    }

    /**
     * @param int[] $poolIds
     * @return array<int, string>
     */
    private function getPoolNames(array $poolIds): array {
        $poolIds = array_values(array_unique(array_filter(array_map('intval', $poolIds), static fn(int $id): bool => $id > 0)));
        if ($poolIds === []) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'name')
            ->from('learning_pools')
            ->where($qb->expr()->in('id', $qb->createNamedParameter($poolIds, IQueryBuilder::PARAM_INT_ARRAY)));

        $result = $qb->executeQuery();
        $names = [];
        while ($row = $result->fetch()) {
            $names[(int)$row['id']] = (string)$row['name'];
        }
        $result->closeCursor();

        return $names;
    }

    private function parseExamDate(string $examDate): ?int {
        $examDate = trim($examDate);
        if ($examDate === '') {
            return null;
        }

        // "YYYY-MM-DDTHH:MM" (datetime-local with time)
        if (str_contains($examDate, 'T')) {
            $date = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $examDate);
            if ($date !== false) {
                return $date->getTimestamp();
            }
        }

        // Legacy "YYYY-MM-DD" — assume end of day
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $examDate);
        if ($date === false) {
            return null;
        }

        return $date->setTime(23, 59, 59)->getTimestamp();
    }

    /**
     * @return int[]
     */
    private function getFilteredQuestionIdsForCoursePool(int $courseId, CoursePool $coursePool): array {
        $qb = $this->db->getQueryBuilder();
        $expr = $qb->expr();
        $qb->select('id')
            ->from('learning_questions')
            ->where($expr->eq('pool_id', $qb->createNamedParameter($coursePool->getPoolId(), IQueryBuilder::PARAM_INT)));

        $filterExamKey = $coursePool->getFilterExamKey();
        if ($filterExamKey !== null && $filterExamKey !== '') {
            $qb->andWhere($expr->eq('exam_key', $qb->createNamedParameter($filterExamKey)));
        }

        $filterChapterKey = $coursePool->getFilterChapterKey();
        if ($filterChapterKey !== null && $filterChapterKey !== '') {
            $qb->andWhere($expr->eq('chapter_key', $qb->createNamedParameter($filterChapterKey)));
        }

        $filterQuestionIds = $this->decodeQuestionIds($coursePool->getFilterQuestionIds());
        if ($filterQuestionIds !== []) {
            $qb->andWhere($expr->in('id', $qb->createNamedParameter($filterQuestionIds, IQueryBuilder::PARAM_INT_ARRAY)));
        }

        $qb->orderBy('created_at', 'DESC');
        $result = $qb->executeQuery();
        $questionIds = array_map('intval', array_column($result->fetchAll(), 'id'));
        $result->closeCursor();

        return $this->courseService->applyCurriculumScope($courseId, $questionIds);
    }

    /**
     * @return int[]
     */
    private function decodeQuestionIds(?string $json): array {
        if ($json === null || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map('intval', $decoded), static fn(int $id): bool => $id > 0));
    }
}
