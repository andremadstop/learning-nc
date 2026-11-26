<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * GitHub #92: when the next counting exam on a pool in a course may start.
 *
 * Reads the starts TrainingService::enforceExamStartPolicy() counts (mode 'exam', exam_kind NULL,
 * this user, this course, the last 24 hours) and applies ExamAttemptPolicy::nextStartAt() to
 * them. The exam result (CertificationTrigger) and the pass status (the Exam tab's pool list)
 * both ask here, so the countdown a learner sees after an attempt and the one on returning to
 * the Exam tab are the same number.
 *
 * The course's total limit (ExamAttemptLimitService) is not part of it: once that is used up no
 * wait helps, and attempt_policy carries it separately (total_limit, used_by_pool).
 */
class ExamStartWindow {
    public function __construct(
        private readonly IDBConnection $db,
        private readonly ExamAttemptPolicy $policy,
    ) {}

    /**
     * @return int|null the earliest allowed start on this pool, or null when one may start now
     */
    public function nextStartAt(string $userId, int $courseId, int $poolId, ?int $now = null): ?int {
        $now ??= time();
        $qb = $this->db->getQueryBuilder();
        $qb->select('started_at');
        $this->whereRecentStarts($qb, $userId, $courseId, $now);
        $qb->andWhere($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT)))
            ->orderBy('started_at', 'DESC')
            ->setMaxResults(max($this->policy->limitPerDay(), 1));
        $result = $qb->executeQuery();
        $starts = [];
        while (($row = $result->fetch()) !== false) {
            if (is_array($row) && isset($row['started_at'])) {
                $starts[] = (int)$row['started_at'];
            }
        }
        $result->closeCursor();
        return $this->policy->nextStartAt($starts, $now);
    }

    /**
     * The pools of this course on which the learner has to wait, in one query.
     *
     * @return array<int, int> pool_id => earliest allowed start; pools that may start now are absent
     */
    public function nextStartByPool(string $userId, int $courseId, ?int $now = null): array {
        $now ??= time();
        $qb = $this->db->getQueryBuilder();
        $qb->select('pool_id', 'started_at');
        $this->whereRecentStarts($qb, $userId, $courseId, $now);
        $result = $qb->executeQuery();
        $startsByPool = [];
        while (($row = $result->fetch()) !== false) {
            if (is_array($row) && isset($row['pool_id'], $row['started_at'])) {
                $startsByPool[(int)$row['pool_id']][] = (int)$row['started_at'];
            }
        }
        $result->closeCursor();
        $next = [];
        foreach ($startsByPool as $poolId => $starts) {
            $at = $this->policy->nextStartAt($starts, $now);
            if ($at !== null) {
                $next[$poolId] = $at;
            }
        }
        ksort($next);
        return $next;
    }

    /**
     * The counting exams of this user in this course started within the last 24 hours.
     *
     * @param IQueryBuilder $qb
     */
    private function whereRecentStarts($qb, string $userId, int $courseId, int $now): void {
        $qb->from('learning_sessions')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('mode', $qb->createNamedParameter('exam')))
            ->andWhere($qb->expr()->isNull('exam_kind'))
            ->andWhere($qb->expr()->gte('started_at', $qb->createNamedParameter($now - 86400, IQueryBuilder::PARAM_INT)));
    }
}
