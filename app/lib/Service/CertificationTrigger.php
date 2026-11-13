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
    ) {}

    /**
     * @return array<string, mixed>|null the certification block for the completion response:
     *   the pass status (PassResult::toArray()) plus course_id, pool_id, exam_score, exam_passed
     *   (this attempt reached the course pass mark), counts_for_requirement (this attempt's pool
     *   is one the certificate needs — always true without required pools) and the enforced
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

            return array_merge($result->toArray(), $attempt, [
                'course_id' => $courseId,
                'pool_id' => $poolId,
                'counts_for_requirement' => $countsForRequirement,
                'attempt_policy' => $this->examAttemptPolicy->toArray(),
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
     * @return array<string, mixed>|null
     */
    private function loadSession(int $sessionId, string $userId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'pool_id', 'course_id', 'mode', 'exam_kind', 'completed_at', 'total_questions', 'correct_answers')
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
