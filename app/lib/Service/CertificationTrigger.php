<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Issues the certificate right after a counting exam completes (GitHub #35).
 *
 * Before, issuance only ran when the learner opened the course's completion tab
 * (GET /pass-status), which most learners never saw — they passed and got nothing.
 *
 * Called by TrainingController::complete() AFTER TrainingService::completeSession() returned,
 * i.e. after the session's completed_at is committed, so evaluate() sees the finished exam.
 * It reads the session itself and acts only on a counting exam: mode='exam', exam_kind IS NULL,
 * started from a course (course_id set), completed — in a course with cert_enabled whose member
 * (or instructor) the learner still is. Training sessions, practice exams and exams started
 * outside a course return null. An instructor of the course gets the status block, but evaluate()
 * issues nothing to them (PassResult::ISSUANCE_INSTRUCTOR_OF_COURSE) — the student view's exam is
 * a preview.
 *
 * Never throws: the exam result must reach the learner whatever happens here. A compliance
 * audit failure (the issuance was rolled back) is logged at error level, anything else at warning
 * level; GET /pass-status stays the retry path. evaluate() is idempotent (union guard + CAS slot),
 * so a repeated complete call only re-reads the status.
 */
class CertificationTrigger {
    public function __construct(
        private readonly IDBConnection $db,
        private readonly CourseMapper $courseMapper,
        private readonly CourseMemberMapper $courseMemberMapper,
        private readonly PassCriteriaService $passCriteriaService,
        private readonly ExamAttemptPolicy $examAttemptPolicy,
        private readonly LoggerInterface $logger,
        private readonly ?ExamAttemptLimitService $examAttemptLimit = null,
    ) {}

    /**
     * @return array<string, mixed>|null the certification block for the completion response:
     *   the pass status (PassResult::toArray()) plus course_id, pool_id, exam_score, exam_passed
     *   (this attempt reached the course pass mark), counts_for_requirement (this attempt's pool
     *   is one the certificate needs — always true without required pools), certificate_issued_now
 *   (the valid certificate was issued since this session started, GitHub #92) and the enforced
     *   retake rules. When the check itself failed: {course_id, error:'evaluation_failed'} plus
     *   threshold, exam_score and exam_passed, so the result screen still judges the attempt by
     *   the course pass mark. null when the session is not a counting exam of a certifying course.
     */
    public function afterSessionCompleted(int $sessionId, string $userId): ?array {
        $courseId = null;
        $attempt = [];
        try {
            $session = $this->loadSession($sessionId, $userId);
            if ($session === null || !$this->isCountingExam($session)) {
                return null;
            }
            $courseId = (int)$session['course_id'];
            $course = $this->courseMapper->findById($courseId);
            if (!($course->getCertEnabled() ?? false)) {
                return null;
            }
            if ($course->getInstructorId() !== $userId && !$this->isMember($courseId, $userId)) {
                return null;
            }

            // Judged before evaluate(), so a failing evaluation still reports the attempt against
            // the course pass mark. Same default and rounding as the pass rule.
            $threshold = $course->getCertPassPercent() ?? 80;
            $total = (int)$session['total_questions'];
            $examScore = $total > 0 ? (int) round((int)$session['correct_answers'] * 100 / $total) : 0;
            $attempt = [
                'threshold' => $threshold,
                'exam_score' => $examScore,
                'exam_passed' => $total > 0 && $examScore >= $threshold,
            ];

            $result = $this->passCriteriaService->evaluate($userId, $courseId);
            $poolId = (int)$session['pool_id'];
            $countsForRequirement = $result->getRequirement() !== PassResult::REQUIREMENT_REQUIRED_POOLS
                || in_array($poolId, array_map(static fn(array $p): int => (int)$p['pool_id'], $result->getPools()), true);

            // GitHub #87: tell the instructors when this was the last attempt of the cycle, and
            // show the learner the course's total limit next to the daily one.
            $attemptPolicy = $this->examAttemptPolicy->toArray();
            if ($this->examAttemptLimit !== null) {
                $this->examAttemptLimit->afterExamCompleted($userId, $courseId, $poolId);
                $attemptPolicy += $this->examAttemptLimit->policyFor($userId, $course);
            }
            // GitHub #92: the result screen counts down to the next possible start on this pool.
            $attemptPolicy['next_start_at'] = $this->nextStartAt($userId, $courseId, $poolId);

            // GitHub #92: certificate_issued means "a valid certificate exists" — also an old one,
            // next to a failed attempt. Issued by this completion is a certificate issued since
            // this session started (the pass of this attempt, or a deferred issuance the
            // evaluation above caught up on).
            $certificate = $result->getCertificate();
            $startedAt = (int)($session['started_at'] ?? 0);
            $issuedNow = $result->isCertificateIssued() && $certificate !== null
                && $startedAt > 0 && (int)$certificate['issued_at'] >= $startedAt;

            return array_merge($result->toArray(), $attempt, [
                'certificate_issued_now' => $issuedNow,
                'course_id' => $courseId,
                'pool_id' => $poolId,
                'counts_for_requirement' => $countsForRequirement,
                'attempt_policy' => $attemptPolicy,
            ]);
        } catch (ComplianceAuditException $e) {
            $this->logger->error('Certification after exam completion failed (compliance audit) for user {user} session {session}: {msg}', [
                'user' => $userId, 'session' => $sessionId, 'msg' => $e->getMessage(), 'app' => 'learning',
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Certification after exam completion failed for user {user} session {session}: {msg}', [
                'user' => $userId, 'session' => $sessionId, 'msg' => $e->getMessage(), 'app' => 'learning',
            ]);
        }
        return $courseId !== null ? array_merge(['course_id' => $courseId, 'error' => 'evaluation_failed'], $attempt) : null;
    }

    /**
     * GitHub #92: the earliest start of the next counting exam on this pool in this course
     * (ExamAttemptPolicy::nextStartAt over the same attempts TrainingService counts), or null
     * when one may start now. The course's total limit is not part of it: attempt_policy carries
     * that separately (used_by_pool, total_limit).
     */
    private function nextStartAt(string $userId, int $courseId, int $poolId): ?int {
        $now = time();
        $qb = $this->db->getQueryBuilder();
        $qb->select('started_at')
            ->from('learning_sessions')
            ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('mode', $qb->createNamedParameter('exam')))
            ->andWhere($qb->expr()->isNull('exam_kind'))
            ->andWhere($qb->expr()->gte('started_at', $qb->createNamedParameter($now - 86400, IQueryBuilder::PARAM_INT)))
            ->orderBy('started_at', 'DESC')
            ->setMaxResults(max($this->examAttemptPolicy->limitPerDay(), 1));
        $result = $qb->executeQuery();
        $starts = [];
        while (($row = $result->fetch()) !== false) {
            if (is_array($row) && isset($row['started_at'])) {
                $starts[] = (int)$row['started_at'];
            }
        }
        $result->closeCursor();
        return $this->examAttemptPolicy->nextStartAt($starts, $now);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadSession(int $sessionId, string $userId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'pool_id', 'course_id', 'mode', 'exam_kind', 'started_at', 'completed_at', 'total_questions', 'correct_answers')
            ->from('learning_sessions')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($sessionId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $session
     */
    private function isCountingExam(array $session): bool {
        return ($session['mode'] ?? null) === 'exam'
            && ($session['exam_kind'] ?? null) === null
            && ($session['course_id'] ?? null) !== null
            && ($session['completed_at'] ?? null) !== null;
    }

    private function isMember(int $courseId, string $userId): bool {
        try {
            $this->courseMemberMapper->findByCourseAndUser($courseId, $userId);
            return true;
        } catch (DoesNotExistException $e) {
            return false;
        }
    }
}
