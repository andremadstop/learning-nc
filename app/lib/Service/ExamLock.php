<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCP\IDBConnection;

/**
 * The exam-oracle lock outside TrainingService (audit #56): while a counting exam runs on a pool,
 * the question APIs, Leitner and the AI explanation withhold correct answers and explanations.
 *
 * "Runs" means: mode 'exam', no exam_kind (practice attempts never count and must not lock a
 * pool), not completed, and — for a timed exam — not past its deadline plus the grace period for
 * the final batch. Before, the three copies of this check only looked at completed_at, so an exam
 * whose tab was closed locked the pool until something happened to run through a TrainingService
 * path that closes it.
 *
 * Read-only by design: a read path must not complete the session or write the
 * exam_timeout_auto_complete audit event; TrainingService::closeExpiredExamSessionIfNeeded()
 * stays the one place that does.
 */
final class ExamLock {
    private function __construct() {
    }

    public static function isActiveOnPool(IDBConnection $db, int $poolId, string $userId, ?int $now = null): bool {
        $now ??= time();
        $qb = $db->getQueryBuilder();
        $qb->select('id', 'started_at', 'time_limit_seconds')
           ->from('learning_sessions')
           ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId)))
           ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('mode', $qb->createNamedParameter('exam')))
           ->andWhere($qb->expr()->isNull('exam_kind'))
           ->andWhere($qb->expr()->isNull('completed_at'));
        $result = $qb->executeQuery();
        $active = false;
        while (($row = $result->fetch()) !== false) {
            if (self::isRunning($row, $now)) {
                $active = true;
                break;
            }
        }
        $result->closeCursor();
        return $active;
    }

    /**
     * An open counting exam still runs unless its time limit (plus the submit grace period) is
     * over. An untimed exam runs until it is completed.
     *
     * @param array<string, mixed> $session
     */
    public static function isRunning(array $session, int $now): bool {
        $timeLimit = isset($session['time_limit_seconds']) ? (int)$session['time_limit_seconds'] : 0;
        $startedAt = isset($session['started_at']) ? (int)$session['started_at'] : 0;
        if ($timeLimit <= 0 || $startedAt <= 0) {
            return true;
        }
        return $now <= $startedAt + $timeLimit + TrainingService::EXAM_SUBMIT_GRACE_SECONDS;
    }
}
