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
                    // GitHub #43: the required pools travel with the result, so the certificate
                    // names the pools it was earned on (titles as they are at issuance).
                    $preResult = new PassResult($passed, $score, $threshold, $poolsMastered, null, true, $rule['requirement'], $rule['pools']);
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
            $passedAt = $this->getPassedAt($userId, $courseId, $certificate);
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
     * GitHub #75: since when a read confirmation of a required document counts in a course that
     * asks for it again in every certification cycle (one user; see confirmationCycleStarts()).
     */
    public function confirmationCycleStart(string $userId, int $courseId): ?int {
        return $this->confirmationCycleStarts($this->courseMapper->findById($courseId), [$userId])[$userId] ?? null;
    }

    /**
     * GitHub #75: the start of each user's current certification cycle — the bound a read
     * confirmation must not be older than in a course that asks for it again in every cycle.
     *
     * While issuance is possible it is the bound the exams have (issuanceWindow()'s
     * completed_after): the opening of the renewal window, or of an open recertification period.
     * After a renewal the cycle does NOT end with the window: the new certificate was earned in
     * it, so the cycle stays the one that issued it — max(predecessor issued_at + 1, opening of
     * the predecessor's renewal window), or the recertification period it was issued in. Without
     * that, the bound vanished once the renewed certificate was issued and last cycle's
     * confirmations counted again (learner view, coverage report and exam gate).
     * null = no bound: no certificate yet, a first certificate whose renewal window is not open
     * yet, a revoked newest certificate, or a course without certification.
     *
     * Batched: one certificate query and one assignment query for all users.
     *
     * @param list<string> $userIds
     * @return array<string, int|null> user => cycle start
     */
    public function confirmationCycleStarts(Course $course, array $userIds): array {
        $userIds = array_values(array_unique(array_map('strval', $userIds)));
        $out = array_fill_keys($userIds, null);
        if ($userIds === [] || !($course->getCertEnabled() ?? false)) {
            return $out;
        }
        $courseId = (int)$course->getId();
        $certificates = $this->certificateMapper->findNewestTwoByCourseForUsers($courseId, $userIds);
        $states = $this->assignmentService->getStatesForCourseAndUsers($courseId, array_keys($certificates));
        $now = time();
        foreach ($certificates as $userId => $certs) {
            $newest = $certs[0] ?? null;
            if ($newest === null) {
                continue;
            }
            $out[$userId] = self::cycleStartOf($newest, $certs[1] ?? null, $states[$userId] ?? null, $this->renewalOpensAt($newest), $now, $this->config);
        }
        return $out;
    }

    /**
     * The rule of confirmationCycleStarts() for one user (pure but for the reminder config).
     *
     * @param array{status: string, due_date: int|null, assigned_at: int}|null $state the open assignment period
     */
    private static function cycleStartOf(Certificate $newest, ?Certificate $previous, ?array $state, ?int $opensAt, int $now, IConfig $config): ?int {
        // Renewal window of the newest certificate open: the exams' bound (issuanceWindow()).
        if ($opensAt !== null && $now >= $opensAt) {
            return max((int)$newest->getIssuedAt() + 1, $opensAt);
        }
        $assignedAt = $state !== null ? (int)$state['assigned_at'] : null;
        // Open recertification period (issuanceWindow() Branch B).
        if ($state !== null && in_array($state['status'], ['assigned', 'in_progress', 'overdue'], true)) {
            return $assignedAt;
        }
        if ((bool)$newest->getRevoked() || $previous === null) {
            return null;
        }
        // The newest certificate is a renewal or a re-certification: its cycle is the one it was
        // issued in. A period that opened after the predecessor and before the newest one issued it.
        $previousIssuedAt = (int)$previous->getIssuedAt();
        if ($assignedAt !== null && $assignedAt > $previousIssuedAt && $assignedAt <= (int)$newest->getIssuedAt()) {
            return $assignedAt;
        }
        if ($previous->getExpiresAt() !== null && !(bool)$previous->getRevoked()) {
            return max($previousIssuedAt + 1, ConfigDefaults::renewalOpensAt($config, (int)$previous->getExpiresAt()));
        }
        return $previousIssuedAt + 1;
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
     * Read-only (Lückencheck B2, coverage report): how many required pools each learner has passed,
     * out of how many. The same sequence evaluate() runs before it issues anything — the issuance
     * window first, then the pass rule — so the count matches the pools the learner sees on the
     * completion tab, including a renewal window in which only the new exams count.
     *
     * Never issues, emits or writes. A course without certificates or without (still linked)
     * required pools gives total 0 for everyone.
     *
     * @param string[] $userIds
     * @return array<string, array{passed: int, total: int}> keyed by user id
     */
    public function requiredPoolProgress(int $courseId, array $userIds): array {
        $course = $this->courseMapper->findById($courseId);
        $requiredPoolIds = ($course->getCertEnabled() ?? false)
            ? $this->resolveRequiredPoolIds($courseId, $course->getCertRequiredPoolIds())
            : [];
        $threshold = $course->getCertPassPercent() ?? 80;

        $progress = [];
        foreach (array_values(array_unique($userIds)) as $userId) {
            if ($requiredPoolIds === []) {
                $progress[$userId] = ['passed' => 0, 'total' => 0];
                continue;
            }
            $certificate = $this->certificateMapper->findByUserAndCourse($userId, $courseId);
            $window = $this->issuanceWindow($userId, $courseId, $certificate);
            $rule = $this->assessRule($userId, $courseId, $requiredPoolIds, $threshold, [], $window['completed_after']);
            $progress[$userId] = [
                'passed' => count(array_filter($rule['pools'], static fn(array $p): bool => $p['passed'])),
                'total' => count($requiredPoolIds),
            ];
        }
        return $progress;
    }

    /**
     * Read-only (GitHub #68): the exam standing of each learner, for the instructor's progress tab
     * and at-risk list of a course without Leitner. The same sequence as requiredPoolProgress() —
     * issuance window first, then the pass rule — so "passed" and the best score per required pool
     * match the completion tab, including a renewal window in which only the new exams count.
     *
     * Never issues, emits or writes (evaluate() is the only path that does). A course without
     * certificates has no pass rule: applicable=false and no users.
     *
     * renewal_open: the current certificate's renewal window is open (from the first reminder on),
     * the same rule as evaluate() and the coverage report (ConfigDefaults::renewalOpensAt()).
     *
     * @param string[] $userIds
     * @return array{applicable: bool, threshold: int, requirement: string, required_pool_ids: list<int>, users: array<string, array{passed: bool, score: int|null, pools: array<int, array{best_score: int|null, passed: bool}>, certificate_valid: bool, certificate_expired: bool, renewal_open: bool}>}
     */
    public function examStandings(int $courseId, array $userIds): array {
        $course = $this->courseMapper->findById($courseId);
        $threshold = $course->getCertPassPercent() ?? 80;
        if (!($course->getCertEnabled() ?? false)) {
            return ['applicable' => false, 'threshold' => $threshold, 'requirement' => PassResult::REQUIREMENT_ANY_EXAM, 'required_pool_ids' => [], 'users' => []];
        }
        $requiredPoolIds = $this->resolveRequiredPoolIds($courseId, $course->getCertRequiredPoolIds());

        $users = [];
        foreach (array_values(array_unique(array_map('strval', $userIds))) as $userId) {
            $certificate = $this->certificateMapper->findByUserAndCourse($userId, $courseId);
            $window = $this->issuanceWindow($userId, $courseId, $certificate);
            $rule = $this->assessRule($userId, $courseId, $requiredPoolIds, $threshold, [], $window['completed_after']);
            $pools = [];
            foreach ($rule['pools'] as $pool) {
                $pools[(int)$pool['pool_id']] = ['best_score' => $pool['best_score'], 'passed' => $pool['passed']];
            }
            $described = $this->describeCertificate($certificate);
            $opensAt = $this->renewalOpensAt($certificate);
            $users[$userId] = [
                'passed' => $rule['passed'],
                'score' => $rule['score'],
                'pools' => $pools,
                'certificate_valid' => $described !== null && !$described['revoked'] && !$described['expired'],
                'certificate_expired' => $described !== null && !$described['revoked'] && $described['expired'],
                'renewal_open' => $opensAt !== null && time() >= $opensAt,
            ];
        }

        return [
            'applicable' => true,
            'threshold' => $threshold,
            'requirement' => $requiredPoolIds === [] ? PassResult::REQUIREMENT_ANY_EXAM : PassResult::REQUIREMENT_REQUIRED_POOLS,
            'required_pool_ids' => $requiredPoolIds,
            'users' => $users,
        ];
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
     * Returns the unix timestamp of the first course.passed event for this user+course.
     *
     * Retention (RetentionService::anonymizeExpired) nulls user_id on every audit row older than
     * retention_years, so after that the event can no longer be found by user (audit #166). A
     * learner still holding a valid certificate then reads the issue time of that certificate —
     * the newest pass the system can still attribute to them — instead of no date at all. A
     * revoked certificate gives none: it no longer stands for a pass.
     */
    private function getPassedAt(string $userId, int $courseId, ?Certificate $certificate): ?int {
        $ctx = $this->findPassEvent($userId, $courseId);
        if ($ctx !== null && isset($ctx['passed_at'])) {
            return (int)$ctx['passed_at'];
        }
        if ($certificate !== null && !$certificate->getRevoked() && (int)$certificate->getIssuedAt() > 0) {
            return (int)$certificate->getIssuedAt();
        }
        return null;
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
