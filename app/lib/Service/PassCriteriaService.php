<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\CertificateMapper;
use OCA\Learning\Db\CourseMapper;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Evaluates whether a student has met the pass criteria for a certifying course.
 *
 * Two-gate evaluation (PASS-02 + PASS-03):
 *   Gate 1: best exam score >= cert_pass_percent
 *   Gate 2: mastery_rate of required pools >= cert_pass_percent
 *
 * PASS-07: First qualification emits a course.passed audit event (idempotent).
 * Trigger: lazy — called from GET /pass-status (154-04); the idempotency guard makes
 * repeated GET calls safe. The event is NOT emitted at exam-completion time.
 *
 * MUST NOT call the FSRS readiness service — it throws without exam_date and pass
 * evaluation is orthogonal to FSRS readiness. See 154-CONTEXT.md locked decisions
 * and the PASS-05 structural note.
 */
class PassCriteriaService {
    public function __construct(
        private readonly CourseMapper $courseMapper,
        private readonly CourseSummaryService $courseSummaryService,
        private readonly AuditService $auditService,
        private readonly IDBConnection $db,
        private readonly IssuanceService $issuanceService,
        private readonly LoggerInterface $logger,
        private readonly CertificateMapper $certificateMapper,
        private readonly AssignmentService $assignmentService,
    ) {}

    public function evaluate(string $userId, int $courseId): PassResult {
        $course = $this->courseMapper->findById($courseId);

        if (!($course->getCertEnabled() ?? false)) {
            return PassResult::notApplicable();
        }

        $threshold = $course->getCertPassPercent() ?? 80;

        // Gate 1: exam score (PASS-02, PASS-05).
        // getExamScore() queries learning_sessions mode='exam' completed_at IS NOT NULL.
        // Guess exclusion is structural: exam-mode sessions have no "Guessed" button (that
        // self-rating lives only in the Leitner/FSRS UI), so a guess can never inflate the
        // exam score. No is_guessed column is needed or queried here.
        $score = $this->courseSummaryService->getExamScore($userId, $courseId);
        $scoreMet = $score !== null && $score >= $threshold;

        // Gate 2: pool mastery (PASS-03).
        // mastery_rate from getMasteryStats() is ALREADY a percentage (0-100.0,
        // round($mastered/$total*100,1)). Compare directly: mastery_rate >= threshold (both
        // are percentage-points). Research Pattern 2 assumed a fractional rate and applied a
        // x100 multiplier — that is WRONG per the codebase; direct comparison is correct here.
        //
        // Semantics: getMasteryStats($requiredPoolIds) returns ONE aggregate rate over the
        // UNION of all required pools. A student strong on pools A+B but weak on C can still
        // clear the gate if the aggregate rate >= threshold. Intentional: single threshold,
        // single rate.
        $rawIds = $course->getCertRequiredPoolIds();
        $requiredPoolIds = $rawIds !== null ? (json_decode($rawIds, true) ?? []) : [];
        $poolsMastered = empty($requiredPoolIds);

        if (!empty($requiredPoolIds)) {
            $masteryStats = $this->courseSummaryService->getMasteryStats($userId, $requiredPoolIds);
            $poolsMastered = $masteryStats['mastery_rate'] >= $threshold;
        }

        $passed = $scoreMet && $poolsMastered;
        $passedAt = null;

        if ($passed) {
            // RECERT-05 union guard: only attempt issuance when the student is eligible.
            // Branch A: never issued before (ASSIGN-04: self-learner path, no assignment row).
            // Branch B: open per-user recertification period with an issuable status.
            if ($this->mayIssue($userId, $courseId)) {
                try {
                    // CERT-05: issue via issueIfPassedResult so the CAS winner/loser is known.
                    // Emit COURSE_PASSED and advance assignment status ONLY on the DB-insert winner
                    // (wasCreated=true). The concurrent loser dedupes silently to prevent duplicate
                    // audit rows (RECERT-06 concurrency guarantee).
                    $preResult = new PassResult($passed, $score, $threshold, $poolsMastered, null);
                    $issueResult = $this->issuanceService->issueIfPassedResult($userId, $courseId, $preResult);

                    if ($issueResult !== null && $issueResult->wasCreated) {
                        // FIX-3: ComplianceAuditException is NOT a provisioning error — it means the
                        // tamper-evident audit append failed and the cert was rolled back. Re-throw so
                        // the caller gets HTTP 500 (fail-closed). Only logComplianceEvent throws this.
                        $this->auditService->logComplianceEvent(ComplianceEventTypes::COURSE_PASSED, $userId, [
                            'course_id' => $courseId,
                            'score'     => (int)$score,
                            'threshold' => $threshold,
                            'passed_at' => time(),
                        ]);
                        // Advance assignment status to 'passed' for the active period (no-op for
                        // self-enrolled learners who have no assignment row).
                        $this->assignmentService->markPassed($userId, $courseId);
                    }
                } catch (ComplianceAuditException $e) {
                    throw $e; // compliance-audit failure — fail closed, do not swallow
                } catch (\Throwable $e) {
                    $this->logger->warning(
                        'Certificate issuance failed for user {user} course {course}: {msg}',
                        ['user' => $userId, 'course' => $courseId, 'msg' => $e->getMessage()]
                    );
                }
            }
            // PASS-07: read the first-qualification timestamp from the audit log, independent of
            // whether issuance ran this call (idempotent — always returns the earliest event).
            $passedAt = $this->getPassedAt($userId, $courseId);
        }

        return new PassResult($passed, $score, $threshold, $poolsMastered, $passedAt);
    }

    /**
     * Returns the unix timestamp of the first course.passed event for this user+course,
     * or null if no such event exists.
     */
    private function getPassedAt(string $userId, int $courseId): ?int {
        $ctx = $this->findPassEvent($userId, $courseId);
        if ($ctx === null) {
            return null;
        }
        return isset($ctx['passed_at']) ? (int)$ctx['passed_at'] : null;
    }

    /**
     * Union-guard (RECERT-05) — implemented in Wave 4 (164-04).
     *
     * A pass may emit + issue iff EITHER:
     *   (a) NO cert has EVER been issued for (user, course) — UNFILTERED findByUserAndCourse()
     *       returns null. A revoked or expired row still counts as "ever issued" → no auto-reissue.
     *       Covers self-enrolled learners (ASSIGN-04: no assignment row) automatically.
     *   (b) An open per-user period exists: active_period_key IS NOT NULL AND
     *       status IN ('assigned','in_progress','overdue') — ALLOW-LIST (not != passed).
     *       Terminal states (cancelled/withdrawn/closed/expired/passed) block issuance.
     *
     * SC2: after a punitive revoke (revoked=true, active_idem_key=NULL) with NO open period
     * this returns false — do NOT auto-reissue after a punitive revoke.
     */
    private function mayIssue(string $userId, int $courseId): bool {
        // Branch A: student has no certificate history at all — always allow
        if (!$this->hasEverIssuedCertificate($userId, $courseId)) {
            return true;
        }
        // Branch B: cert exists, but there is an open recertification period
        $states = $this->assignmentService->getStatesForCourseAndUsers($courseId, [$userId]);
        $state = $states[$userId] ?? null;
        return $state !== null
            && in_array($state['status'], ['assigned', 'in_progress', 'overdue'], true);
    }

    /**
     * Returns true when a certificate row exists for (user, course) — revoked, expired, or valid.
     * UNFILTERED: a punitively-revoked cert still counts as "ever issued".
     */
    private function hasEverIssuedCertificate(string $userId, int $courseId): bool {
        return $this->certificateMapper->findByUserAndCourse($userId, $courseId) !== null;
    }

    /**
     * Returns the decoded context array of the first course.passed event matching
     * $userId + $courseId, or null if none exists.
     *
     * @return array<string, mixed>|null
     */
    private function findPassEvent(string $userId, int $courseId): ?array {
        $query = $this->db->getQueryBuilder();
        $query->select('context_json')
            ->from('learning_audit_events')
            ->where($query->expr()->eq('event_key', $query->createNamedParameter('course.passed')))
            ->andWhere($query->expr()->eq('user_id', $query->createNamedParameter($userId)));

        $result = $query->executeQuery();
        $match = null;
        while ($row = $result->fetch()) {
            $ctx = json_decode((string)$row['context_json'], true);
            if (is_array($ctx) && isset($ctx['course_id']) && (int)$ctx['course_id'] === $courseId) {
                $match = $ctx;
                break;
            }
        }
        $result->closeCursor();
        return $match;
    }
}
