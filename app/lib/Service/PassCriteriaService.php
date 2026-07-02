<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

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
            // PASS-07: emit on first qualification only (idempotency guard inside).
            $this->emitPassEventIfFirst($userId, $courseId, (int)$score, $threshold);
            $passedAt = $this->getPassedAt($userId, $courseId);
        }

        $result = new PassResult($passed, $score, $threshold, $poolsMastered, $passedAt);

        if ($passed) {
            // CERT-05: auto-issue the signed credential immediately after the first-pass audit
            // event. Issuance is a SIDE-EFFECT of this read path (GET /pass-status) — an ordinary
            // PROVISIONING error (e.g. issuer key not yet initialised at 155-07, or the
            // learning_certificates table missing) must NEVER break it: swallow + log.
            //
            // FIX-3 (non-swallow of compliance failures): a ComplianceAuditException is NOT a
            // provisioning error — it means the tamper-evident audit append failed, so the cert
            // INSERT was rolled back (fail-closed, IssuanceService FIX-2). That MUST surface (HTTP 500),
            // never be logged-and-ignored. Re-throw it and only swallow genuinely non-compliance errors.
            // IssuanceService owns its own idempotency guard, so repeated GETs issue exactly once.
            try {
                $this->issuanceService->issueIfPassed($userId, $courseId, $result);
            } catch (ComplianceAuditException $e) {
                throw $e; // compliance-audit failure — fail closed, do not swallow
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Certificate issuance failed for user {user} course {course}: {msg}',
                    ['user' => $userId, 'course' => $courseId, 'msg' => $e->getMessage()]
                );
            }
        }

        return $result;
    }

    /**
     * Writes a course.passed audit event ONLY if no prior event exists for this user+course.
     * Dedup: query event_key='course.passed' AND user_id; PHP-decode context_json to match
     * course_id. NO LIKE pattern (brittle). NO DB UNIQUE constraint (audit table is
     * append-only by design).
     *
     * O(N) scan note: loads all 'course.passed' events for this user. In practice N equals
     * the number of distinct courses this user has passed — typically < 10, not a concern.
     *
     * Race condition (SELECT->INSERT is not atomic): two concurrent GET /pass-status requests
     * that both find no existing event may both INSERT, producing a duplicate course.passed
     * row. Probability is low (requires two simultaneous qualifying GETs in the same window).
     * The proper fix — a course_id column on the audit table with a UNIQUE(event_key, user_id,
     * course_id) index — is deferred to a future migration. The audit table is append-only;
     * worst case is a minor data-quality issue, not a functional or security problem.
     */
    private function emitPassEventIfFirst(string $userId, int $courseId, int $score, int $threshold): void {
        if ($this->findPassEvent($userId, $courseId) !== null) {
            return; // already emitted — idempotency guard
        }

        $this->auditService->logComplianceEvent(ComplianceEventTypes::COURSE_PASSED, $userId, [
            'course_id' => $courseId,
            'score'     => $score,
            'threshold' => $threshold,
            'passed_at' => time(),
        ]);
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
     * Union-guard seam (RECERT-05) — frozen in Wave 2 (164-02), implemented in Wave 4 (164-04).
     *
     * A pass may emit + issue iff EITHER:
     *   (a) NO cert has ever been issued for (user, course) [ASSIGN-04: self-learner, no assignment row]
     *   (b) An assignment row exists with active_period_key IS NOT NULL AND status != 'passed'
     *       [open obligation period — student has not yet cleared this cycle]
     *
     * SC2: after a punitive revoke (revoked=true, active_idem_key=NULL) with NO open period this
     * MUST return false — do NOT auto-reissue after a punitive revoke.
     *
     * Not yet called from evaluate()/emitPassEventIfFirst — wired in 164-04.
     *
     * @throws \LogicException until 164-04 implements the guard
     */
    private function mayIssue(string $userId, int $courseId): bool {
        throw new \LogicException('not implemented — 164-04');
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
