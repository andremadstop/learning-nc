<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\AppInfo\ConfigDefaults;
use OCA\Learning\Db\Certificate;
use OCA\Learning\Db\CertificateMapper;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Evaluates whether a student has met the pass criteria for a certifying course.
 *
 * Pass rule (GitHub #35, replaces the two-gate PASS-02/PASS-03 rule):
 *   - cert_required_pool_ids non-empty → EVERY required pool needs a counting exam on that pool
 *     whose score >= cert_pass_percent (best attempt per pool counts);
 *   - no required pools → the best counting exam of the course >= cert_pass_percent.
 * A counting exam is a learning_sessions row with mode='exam', exam_kind IS NULL (practice exams
 * never count), course_id = this course, completed_at set.
 *
 * The former Gate 2 (Leitner box-5 mastery over the required pools) is gone from certification:
 * its denominator was the learner's own Leitner items, so a single box-5 card read as 100 %, and
 * exams never create Leitner items, so a learner who only took exams could never pass.
 * getMasteryStats() stays for the course summary display.
 *
 * Instructors of the course (owner or co-instructor member) are never certified there (GitHub #35):
 * the rule is still assessed and reported, but nothing is issued, emitted or marked passed, and the
 * result carries ISSUANCE_INSTRUCTOR_OF_COURSE. An instructor sees the questions and answers of the
 * course, so a certificate would attest nothing, and the student view lets an instructor take the
 * course's counting exam as a preview — that preview must not leave a certificate or an entry in
 * the tamper-evident compliance chain behind.
 *
 * Renewal (GitHub #35): from the first recertification reminder on, a pass with exams taken in
 * that window renews the current certificate (issuanceWindow(), IssuanceService `renewing`).
 *
 * PASS-07: First qualification emits a course.passed audit event (idempotent).
 * Triggers: CertificationTrigger right after a counting exam completes, and lazily
 * GET /pass-status (the retry path). Both go through evaluate(); the union guard plus the
 * UNIQUE CAS slot make repeated calls safe.
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
        private readonly CourseMemberMapper $courseMemberMapper,
        private readonly IConfig $config,
    ) {}

    public function evaluate(string $userId, int $courseId): PassResult {
        $course = $this->courseMapper->findById($courseId);

        if (!($course->getCertEnabled() ?? false)) {
            return PassResult::notApplicable();
        }

        $threshold = $course->getCertPassPercent() ?? 80;
        $requiredPoolIds = $this->resolveRequiredPoolIds($courseId, $course->getCertRequiredPoolIds());

        // Read once: feeds the union guard (Branch A) and the certificate shown to the learner.
        $certificate = $this->certificateMapper->findByUserAndCourse($userId, $courseId);
        // Read BEFORE the rule: in an open recertification period only that period's exams count.
        $window = $this->issuanceWindow($userId, $courseId, $certificate);

        $rule = $this->assessRule($userId, $courseId, $requiredPoolIds, $threshold, $this->courseSummaryService->getPoolTitles($requiredPoolIds), $window['completed_after']);
        $passed = $rule['passed'];
        $score = $rule['score'];
        $poolsMastered = $rule['pools_passed'];
        $issuanceError = null;
        $passedAt = null;

        if ($passed) {
            if ($this->isInstructorOfCourse($course, $courseId, $userId)) {
                // Read-only for instructors of the course: no certificate, no COURSE_PASSED, no
                // markPassed. Reported as a reason, so no screen can present it as issued.
                $issuanceError = PassResult::ISSUANCE_INSTRUCTOR_OF_COURSE;
            } elseif ($window['may_issue']) {
                // RECERT-05 union guard: only attempt issuance when the student is eligible.
                // Branch A: never issued before (ASSIGN-04: self-learner path, no assignment row).
                // Branch B: open per-user recertification period with an issuable status.
                // Renewal: the current certificate's renewal window is open (first reminder).
                try {
                    // CERT-05: issue via issueIfPassedResult so the CAS winner/loser is known.
                    // Emit COURSE_PASSED and advance assignment status ONLY on the DB-insert winner
                    // (wasCreated=true). The concurrent loser dedupes silently to prevent duplicate
                    // audit rows (RECERT-06 concurrency guarantee).
                    //
                    // POST-IMPL-REVIEW HIGH 3 (atomicity): the cert INSERT (inside
                    // issueIfPassedResult), the COURSE_PASSED append, and markPassed() commit or
                    // roll back TOGETHER via this OUTER transaction. issueIfPassedResult's own
                    // begin/commit nests as a savepoint (Connection::setNestTransactionsWithSavepoints,
                    // same pattern as the CERT_ISSUED append inside IssuanceService). Without this,
                    // a crash after the cert commit but before the emit would leave a cert whose
                    // COURSE_PASSED event can NEVER be written — every later evaluate() hits the
                    // UNIQUE loser path (wasCreated=false) and skips the emit forever.
                    $preResult = new PassResult($passed, $score, $threshold, $poolsMastered, null);
                    $this->db->beginTransaction();
                    try {
                        $issueResult = $this->issuanceService->issueIfPassedResult($userId, $courseId, $preResult, $window['renewing']);

                        if ($issueResult !== null && $issueResult->wasCreated) {
                            // FIX-3: ComplianceAuditException is NOT a provisioning error — it means the
                            // tamper-evident audit append failed; the outer rollback below also undoes
                            // the cert INSERT (fail-closed, no orphan cert). Re-throw → HTTP 500.
                            $this->auditService->logComplianceEvent(ComplianceEventTypes::COURSE_PASSED, $userId, [
                                'course_id' => $courseId,
                                'score'     => (int)$score,
                                'threshold' => $threshold,
                                'passed_at' => time(),
                            ]);
                            // Advance assignment status to 'passed' for the active period (no-op for
                            // self-enrolled learners who have no assignment row; on a renewal the
                            // period is usually 'passed' already and this rewrites the same value).
                            $this->assignmentService->markPassed($userId, $courseId);
                        }
                        $this->db->commit();
                    } catch (\Throwable $e) {
                        if ($this->db->inTransaction()) {
                            $this->db->rollBack();
                        }
                        throw $e;
                    }
                    if ($issueResult !== null) {
                        // Winner: the new row. CAS loser: the concurrent winner's row.
                        $certificate = $issueResult->certificate;
                    }
                } catch (ComplianceAuditException $e) {
                    throw $e; // compliance-audit failure — fail closed, do not swallow
                } catch (\Throwable $e) {
                    // GitHub #35: a passed learner without a certificate must not look like a
                    // complete success. The pass stands (it is a fact about the exams); the
                    // missing certificate is reported as a reason code — never the message,
                    // which can name key handling details. The next evaluate() retries.
                    $issuanceError = $e instanceof SigningUnavailableException
                        ? PassResult::ISSUANCE_SIGNING_UNAVAILABLE
                        : PassResult::ISSUANCE_FAILED;
                    $this->logger->error(
                        'Certificate issuance failed for user {user} course {course} ({reason}): {msg}',
                        ['user' => $userId, 'course' => $courseId, 'reason' => $issuanceError, 'msg' => $e->getMessage(), 'app' => 'learning']
                    );
                }
            }
            // PASS-07: read the first-qualification timestamp from the audit log, independent of
            // whether issuance ran this call (idempotent — always returns the earliest event).
            $passedAt = $this->getPassedAt($userId, $courseId);
        }

        $renewalOpensAt = $this->renewalOpensAt($certificate);
        $renewalOpen = $renewalOpensAt !== null && time() >= $renewalOpensAt;

        return new PassResult(
            $passed,
            $score,
            $threshold,
            $poolsMastered,
            $passedAt,
            true,
            $rule['requirement'],
            $rule['pools'],
            $this->describeCertificate($certificate),
            $issuanceError,
            $renewalOpen ? $renewalOpensAt : null,
            $renewalOpen ? null : $renewalOpensAt,
        );
    }

    /**
     * When the renewal window of $certificate opens (GitHub #35), or null when it has none:
     * only a certificate that is not revoked, still owns its idempotency slot (the current
     * certificate of its period — a closed or superseded one has released it) and expires.
     * The window opens with the first reminder (ConfigDefaults::renewalOpensAt()) and stays open
     * past expiry until the daily RecertPeriodCloseJob closes the period, so there is no gap
     * between renewal and recertification.
     */
    private function renewalOpensAt(?Certificate $certificate): ?int {
        if ($certificate === null
            || (bool)$certificate->getRevoked()
            || $certificate->getActiveIdemKey() === null
            || $certificate->getExpiresAt() === null) {
            return null;
        }
        return ConfigDefaults::renewalOpensAt($this->config, (int)$certificate->getExpiresAt());
    }

    /**
     * The pass rule alone, without any side effect (GitHub #35).
     *
     * Required pools: each needs a counting exam on that pool with score >= threshold. Only pools
     * still linked to the course count as required: an exam can only be started on a course pool
     * (resolveCoursePoolContext), so a pool removed from the course or deleted would block every
     * learner for good — and the instructor's certificate settings list only the course's current
     * pools, so the stale id could neither be seen nor unticked there. If none of the configured
     * pools is left, the course falls back to the no-required-pools rule. The score the pass is
     * based on is the LOWEST best-per-pool score — every pool reached at least that — and null
     * while any required pool has no counting exam yet.
     * No required pools: the best counting exam of the course decides.
     * $completedAfter (issuanceWindow()) limits both cases to exams completed at or after it.
     *
     * @param list<int> $requiredPoolIds from resolveRequiredPoolIds()
     * @param array<int, string> $titles pool id => name, for the listing only
     * @return array{passed: bool, score: int|null, pools_passed: bool, requirement: string, pools: list<array{pool_id: int, title: string|null, best_score: int|null, passed: bool}>}
     */
    private function assessRule(string $userId, int $courseId, array $requiredPoolIds, int $threshold, array $titles, ?int $completedAfter = null): array {
        if ($requiredPoolIds === []) {
            // Guess exclusion is structural: exam-mode sessions have no "Guessed" button (that
            // self-rating lives only in the Leitner/FSRS UI), so a guess can never inflate the
            // exam score. No is_guessed column is needed or queried here.
            $score = $this->courseSummaryService->getExamScore($userId, $courseId, $completedAfter);
            return [
                'passed' => $score !== null && $score >= $threshold,
                'score' => $score,
                'pools_passed' => true,
                'requirement' => PassResult::REQUIREMENT_ANY_EXAM,
                'pools' => [],
            ];
        }

        $best = $this->courseSummaryService->getBestExamScoresByPool($userId, $courseId, $requiredPoolIds, $completedAfter);
        $pools = [];
        $allPassed = true;
        $lowest = null;
        $allAttempted = true;
        foreach ($requiredPoolIds as $poolId) {
            $poolScore = isset($best[$poolId]) ? (int)$best[$poolId] : null;
            $poolPassed = $poolScore !== null && $poolScore >= $threshold;
            $allPassed = $allPassed && $poolPassed;
            if ($poolScore === null) {
                $allAttempted = false;
            } elseif ($lowest === null || $poolScore < $lowest) {
                $lowest = $poolScore;
            }
            $pools[] = [
                'pool_id' => $poolId,
                'title' => $titles[$poolId] ?? null,
                'best_score' => $poolScore,
                'passed' => $poolPassed,
            ];
        }

        return [
            'passed' => $allPassed,
            'score' => $allAttempted ? $lowest : null,
            'pools_passed' => $allPassed,
            'requirement' => PassResult::REQUIREMENT_REQUIRED_POOLS,
            'pools' => $pools,
        ];
    }

    /**
     * The configured required pools (cert_required_pool_ids) that are still linked to the course,
     * deduplicated, in configured order. See assessRule() for why unlinked pools are dropped.
     *
     * @return list<int>
     */
    private function resolveRequiredPoolIds(int $courseId, ?string $rawRequiredPoolIds): array {
        $decoded = $rawRequiredPoolIds !== null ? json_decode($rawRequiredPoolIds, true) : null;
        $requiredPoolIds = [];
        foreach (is_array($decoded) ? $decoded : [] as $id) {
            $id = (int)$id;
            if ($id > 0 && !in_array($id, $requiredPoolIds, true)) {
                $requiredPoolIds[] = $id;
            }
        }
        if ($requiredPoolIds === []) {
            return [];
        }
        $coursePoolIds = $this->courseSummaryService->getCoursePoolIds($courseId);
        return array_values(array_filter(
            $requiredPoolIds,
            static fn(int $id): bool => in_array($id, $coursePoolIds, true)
        ));
    }

    /**
     * Read-only (GitHub #35): which of $userIds meet the pass rule and may be issued a certificate
     * (union guard), yet hold no valid one — the learners who see "you passed, but the certificate
     * could not be issued yet". Lets the instructor see them too, whatever made issuance fail.
     *
     * Never issues, emits or writes: evaluate() is the only path that does. A revoked certificate
     * without an open recertification period is excluded by the union guard (no automatic
     * reissue after a revoke), so those learners are not listed as failures.
     *
     * @param string[] $userIds
     * @return list<string> the subset of $userIds, in input order
     */
    public function findPassedWithoutCertificate(int $courseId, array $userIds): array {
        $course = $this->courseMapper->findById($courseId);
        if (!($course->getCertEnabled() ?? false) || $userIds === []) {
            return [];
        }
        $threshold = $course->getCertPassPercent() ?? 80;
        $requiredPoolIds = $this->resolveRequiredPoolIds($courseId, $course->getCertRequiredPoolIds());

        $found = [];
        foreach (array_values(array_unique($userIds)) as $userId) {
            // evaluate() never issues to an instructor of the course — not a failed issuance.
            if ($this->isInstructorOfCourse($course, $courseId, $userId)) {
                continue;
            }
            // The window first, as in evaluate(): in an open recertification period an exam of
            // an earlier period does not make the learner "passed without certificate".
            $certificate = $this->certificateMapper->findByUserAndCourse($userId, $courseId);
            $window = $this->issuanceWindow($userId, $courseId, $certificate);
            if (!$window['may_issue']) {
                continue;
            }
            if (!$this->assessRule($userId, $courseId, $requiredPoolIds, $threshold, [], $window['completed_after'])['passed']) {
                continue;
            }
            $described = $this->describeCertificate($certificate);
            if ($described !== null && !$described['revoked'] && !$described['expired']) {
                continue; // holds a valid certificate (e.g. recertification not yet reissued)
            }
            $found[] = $userId;
        }
        return $found;
    }

    /**
     * Owner or co-instructor member of the course — the same rule as
     * CourseService::isInstructorOfCourse(), which is private there.
     */
    private function isInstructorOfCourse(Course $course, int $courseId, string $userId): bool {
        if ($course->getInstructorId() === $userId) {
            return true;
        }
        try {
            return $this->courseMemberMapper->findByCourseAndUser($courseId, $userId)->getRole() === 'instructor';
        } catch (DoesNotExistException $e) {
            return false;
        }
    }

    /**
     * The certificate as the learner and the UI see it. Expired and revoked stay distinguishable
     * from "none": a revoked learner must not be told issuance failed.
     *
     * @return array{verification_id: string, issued_at: int, expires_at: int|null, expired: bool, revoked: bool}|null
     */
    private function describeCertificate(?Certificate $certificate): ?array {
        if ($certificate === null) {
            return null;
        }
        $verificationId = (string)$certificate->getVerificationId();
        if ($verificationId === '') {
            return null;
        }
        $expiresAt = $certificate->getExpiresAt() !== null ? (int)$certificate->getExpiresAt() : null;
        return [
            'verification_id' => $verificationId,
            'issued_at' => (int)$certificate->getIssuedAt(),
            'expires_at' => $expiresAt,
            'expired' => $expiresAt !== null && $expiresAt <= time(),
            'revoked' => (bool)$certificate->getRevoked(),
        ];
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
     *
     * completed_after (GitHub #35): in Branch B only exams completed since the period opened
     * (assigned_at) count. Without it, the exam that earned the previous certificate renewed it:
     * once the daily RecertPeriodCloseJob opened the next period, merely opening the completion
     * tab issued a new certificate for another validity term, with no new exam. The same holds
     * for revoke-and-reassign: exams from before the revoke must not reissue. null = no limit
     * (Branch A, or no issuance possible at all).
     *
     * Renewal (GitHub #35): the reminders invite a renewal 30 and 7 days before expiry, but the
     * next period only opens after expiry + recert_grace_days. So, independent of the assignment
     * status (in the current period it is 'passed'), the window is also open while the newest
     * certificate is not revoked, still owns its slot, expires, and now >= renewalOpensAt()
     * (expiry minus the first reminder threshold) — which also covers the time after expiry until
     * the close job runs. Only exams completed at or after max(issued_at + 1, window start) count,
     * so the exam that earned the current certificate never renews it. The certificate is handed
     * to IssuanceService as `renewing`; it releases the slot by CAS. Checked before Branch B:
     * a slot-owning certificate can never be issued over by Branch B (its INSERT hits the UNIQUE
     * slot and dedupes), so this changes nothing there; once the period is closed the slot is
     * NULL and only Branch B applies. Outside the window Branch B behaves as before.
     *
     * @return array{may_issue: bool, completed_after: int|null, renewing: Certificate|null}
     */
    private function issuanceWindow(string $userId, int $courseId, ?Certificate $newestCertificate): array {
        // Branch A: student has no certificate history at all — always allow.
        // $newestCertificate is the UNFILTERED findByUserAndCourse() row read by evaluate().
        if ($newestCertificate === null) {
            return ['may_issue' => true, 'completed_after' => null, 'renewing' => null];
        }
        // Renewal: the current certificate's window is open (see above).
        $opensAt = $this->renewalOpensAt($newestCertificate);
        if ($opensAt !== null && time() >= $opensAt) {
            return [
                'may_issue' => true,
                'completed_after' => max((int)$newestCertificate->getIssuedAt() + 1, $opensAt),
                'renewing' => $newestCertificate,
            ];
        }
        // Branch B: cert exists, but there is an open recertification period
        $states = $this->assignmentService->getStatesForCourseAndUsers($courseId, [$userId]);
        $state = $states[$userId] ?? null;
        if ($state === null || !in_array($state['status'], ['assigned', 'in_progress', 'overdue'], true)) {
            return ['may_issue' => false, 'completed_after' => null, 'renewing' => null];
        }
        return ['may_issue' => true, 'completed_after' => (int)($state['assigned_at'] ?? 0), 'renewing' => null];
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
