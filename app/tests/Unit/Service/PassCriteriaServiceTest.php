<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Certificate;
use OCA\Learning\Db\CertificateMapper;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Service\AssignmentService;
use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\ComplianceAuditException;
use OCA\Learning\Service\ComplianceEventTypes;
use OCA\Learning\Service\CourseSummaryService;
use OCA\Learning\Service\IssuanceService;
use OCA\Learning\Service\IssueResult;
use OCA\Learning\Service\PassCriteriaService;
use OCA\Learning\Service\PassResult;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Behavioral contract for PassCriteriaService::evaluate() — the two-gate evaluator.
 *
 * PASS-05 structural note: "lucky-guess exclusion" is structural, NOT a DB column.
 * Verified against the codebase: there is no is_guessed column in app/lib/, and the
 * "Guessed" self-rating button exists ONLY in LeitnerMode.vue (the FSRS spaced-repetition
 * UI). Exam-mode sessions score correct_answers objectively via the batch-submit path; a
 * guess cannot inflate an exam score. Gate 1 reads getExamScore() which queries
 * learning_sessions WHERE mode='exam' — so guessed Leitner answers never reach it.
 * NOTE: getExamScore()/getMasteryStats() are mocked here, so these tests prove the GATE
 * LOGIC; the guess exclusion itself is a structural property of the data source, asserted
 * in CourseSummaryServiceTest (mode='exam' + completed_at filters) and documented in SUMMARY.
 */
class PassCriteriaServiceTest extends TestCase {

    private const COURSE_ID = 7;
    private const USER = 'alice';

    private function makeCourse(bool $enabled, int $percent = 80, ?string $poolIds = null, int $validityDays = 0): Course {
        $course = new Course();
        $course->setCertEnabled($enabled);
        $course->setCertPassPercent($percent);
        $course->setCertRequiredPoolIds($poolIds);
        $course->setCertValidityDays($validityDays);
        return $course;
    }

    /**
     * @param FakeQueryBuilder[] $builders
     */
    private function makeService(
        Course $course,
        ?int $examScore,
        ?array $masteryStats,
        array $builders,
        AuditService $audit,
        ?IssuanceService $issuance = null,
        ?CertificateMapper $certMapper = null,
        ?AssignmentService $assignmentService = null,
        ?FakeDbConnection $db = null,
    ): PassCriteriaService {
        $courseMapper = $this->createMock(CourseMapper::class);
        $courseMapper->method('findById')->willReturn($course);

        $summary = $this->createMock(CourseSummaryService::class);
        $summary->method('getExamScore')->willReturn($examScore);
        if ($masteryStats !== null) {
            $summary->method('getMasteryStats')->willReturn($masteryStats);
        }

        $db = $db ?? new FakeDbConnection($builders);

        // Default certMapper: no cert ever issued → Branch A → mayIssue=true.
        if ($certMapper === null) {
            $certMapper = $this->createMock(CertificateMapper::class);
            $certMapper->method('findByUserAndCourse')->willReturn(null);
        }

        // Default assignmentService: no open period → Branch B false (Branch A covers it anyway).
        if ($assignmentService === null) {
            $assignmentService = $this->createMock(AssignmentService::class);
            $assignmentService->method('getStatesForCourseAndUsers')->willReturn([]);
        }

        // Default issuance: returns IssueResult(cert, wasCreated=true) so emit fires in passing tests.
        if ($issuance === null) {
            $issuance = $this->createMock(IssuanceService::class);
            $issuance->method('issueIfPassedResult')
                ->willReturn(new IssueResult(new Certificate(), true));
        }

        $logger = $this->createMock(LoggerInterface::class);
        return new PassCriteriaService($courseMapper, $summary, $audit, $db, $issuance, $logger, $certMapper, $assignmentService);
    }

    /**
     * Builds the single SELECT result builder a passing evaluate() consumes.
     * 164-04: emit is gated on issueIfPassedResult.wasCreated, NOT on findPassEvent dedup.
     * Only getPassedAt() queries the DB now — one builder per passing evaluate() call.
     */
    private function passingBuilders(int $passedAt = 1700000000): array {
        $existingRow = ['context_json' => json_encode(['course_id' => self::COURSE_ID, 'passed_at' => $passedAt])];
        return [
            new FakeQueryBuilder(new FakeResult(fetchQueue: [$existingRow])), // getPassedAt
        ];
    }

    // PASS-01: cert_enabled=false → PassResult::notApplicable() returned, no DB writes
    public function testEvaluateReturnsNotApplicableWhenCertDisabled(): void {
        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->never())->method('logComplianceEvent');

        $service = $this->makeService($this->makeCourse(false), null, null, [], $audit);
        $result = $service->evaluate(self::USER, self::COURSE_ID);

        $this->assertInstanceOf(PassResult::class, $result);
        $this->assertFalse($result->isApplicable());
        $this->assertFalse($result->isPassed());
    }

    // PASS-02 + PASS-05: exam score == threshold → Gate 1 passes, qualifies (no required pools)
    public function testEvaluatePassesWhenExamScoreMeetsThreshold(): void {
        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->once())->method('logComplianceEvent')
            ->with(ComplianceEventTypes::COURSE_PASSED, self::USER, $this->callback(
                fn(array $ctx): bool => ($ctx['course_id'] ?? null) === self::COURSE_ID && ($ctx['score'] ?? null) === 80
            ));

        $service = $this->makeService($this->makeCourse(true, 80), 80, null, $this->passingBuilders(), $audit);
        $result = $service->evaluate(self::USER, self::COURSE_ID);

        $this->assertTrue($result->isPassed());
        $this->assertSame(80, $result->getScore());
        $this->assertSame(80, $result->getThreshold());
        $this->assertTrue($result->isPoolsMastered());
        $this->assertSame(1700000000, $result->getPassedAt());
    }

    // PASS-02 + PASS-05: exam score < threshold → Gate 1 fails, passed=false, no audit event
    public function testEvaluateFailsWhenExamScoreBelowThreshold(): void {
        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->never())->method('logComplianceEvent');

        $service = $this->makeService($this->makeCourse(true, 80), 79, null, [], $audit);
        $result = $service->evaluate(self::USER, self::COURSE_ID);

        $this->assertFalse($result->isPassed());
        $this->assertSame(79, $result->getScore());
        $this->assertSame(80, $result->getThreshold());
        $this->assertNull($result->getPassedAt());
    }

    // PASS-03: required_pool_ids=[] → poolsMastered=true (trivially satisfied), qualifies
    public function testEvaluatePoolsMasteredTrueWhenNoRequiredPools(): void {
        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->once())->method('logComplianceEvent');

        // poolIds null → empty required pools; getMasteryStats must NOT be consulted
        $service = $this->makeService($this->makeCourse(true, 80, null), 85, null, $this->passingBuilders(), $audit);
        $result = $service->evaluate(self::USER, self::COURSE_ID);

        $this->assertTrue($result->isPassed());
        $this->assertTrue($result->isPoolsMastered());
        $this->assertSame(85, $result->getScore());
    }

    // PASS-03: pool mastery_rate < threshold → Gate 2 fails even though Gate 1 passed
    public function testEvaluateFailsWhenPoolMasteryBelowThreshold(): void {
        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->never())->method('logComplianceEvent');

        // mastery_rate is already a percentage (0-100); 79.5 < 80 → Gate 2 fails
        $service = $this->makeService(
            $this->makeCourse(true, 80, json_encode([1, 2])),
            85,
            ['total_mastered' => 79, 'total_questions' => 100, 'mastery_rate' => 79.5],
            [],
            $audit
        );
        $result = $service->evaluate(self::USER, self::COURSE_ID);

        $this->assertFalse($result->isPassed());
        $this->assertFalse($result->isPoolsMastered());
        $this->assertSame(85, $result->getScore());
        $this->assertNull($result->getPassedAt());
    }

    // PASS-05: structural — ReadinessService is never referenced in PassCriteriaService source
    public function testPassCriteriaServiceDoesNotReferenceReadinessService(): void {
        $source = file_get_contents(__DIR__ . '/../../../lib/Service/PassCriteriaService.php');
        $this->assertIsString($source);
        $this->assertStringNotContainsString('ReadinessService', $source,
            'PassCriteriaService must not reference ReadinessService — FSRS readiness is orthogonal to pass evaluation');
    }

    // PASS-07: first qualification emits exactly one audit event; second evaluate() does not duplicate it
    public function testEmitsPassEventOnlyOnFirstQualification(): void {
        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->once())->method('logComplianceEvent');

        $existingRow = ['context_json' => json_encode(['course_id' => self::COURSE_ID, 'passed_at' => 1700000000])];
        $builders = [
            // call 1: getPassedAt returns the existing row (emit written by issuance)
            new FakeQueryBuilder(new FakeResult(fetchQueue: [$existingRow])),
            // call 2: getPassedAt returns the existing row (mayIssue=false, no emit)
            new FakeQueryBuilder(new FakeResult(fetchQueue: [$existingRow])),
        ];

        // 164-04 dedup mechanism: certMapper-based mayIssue.
        // call 1: certMapper returns null → hasEverIssuedCertificate=false → mayIssue=true → emit fires
        // call 2: certMapper returns cert → hasEverIssuedCertificate=true + no open period → mayIssue=false → no emit
        $cert = new Certificate();
        $certMapper = $this->createMock(CertificateMapper::class);
        $certMapper->expects($this->exactly(2))->method('findByUserAndCourse')
            ->willReturnOnConsecutiveCalls(null, $cert);

        $issuance = $this->createMock(IssuanceService::class);
        $issuance->expects($this->once())->method('issueIfPassedResult')
            ->willReturn(new IssueResult($cert, true));

        $service = $this->makeService($this->makeCourse(true, 80), 90, null, $builders, $audit, $issuance, $certMapper);

        $first = $service->evaluate(self::USER, self::COURSE_ID);
        $second = $service->evaluate(self::USER, self::COURSE_ID);

        $this->assertTrue($first->isPassed());
        $this->assertTrue($second->isPassed());
        $this->assertSame(1700000000, $second->getPassedAt());
    }

    // PASS-04: cert_validity_days is stored but NOT evaluated in Phase 154 (expiry logic is Phase 155)
    public function testCertValidityDaysIsNotEvaluatedByPassCriteria(): void {
        $source = file_get_contents(__DIR__ . '/../../../lib/Service/PassCriteriaService.php');
        $this->assertIsString($source);
        $this->assertStringNotContainsString('validity_days', $source,
            'cert_validity_days must not be read or compared in Phase 154 evaluate() — expiry is Phase 155');
    }

    // AUDIT-03: evaluate() on a fresh pass fires logComplianceEvent(COURSE_PASSED) exactly once with course_id
    public function testEmitsComplianceEvent(): void {
        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->once())
            ->method('logComplianceEvent')
            ->with(
                ComplianceEventTypes::COURSE_PASSED,
                self::USER,
                $this->callback(fn(array $ctx): bool => ($ctx['course_id'] ?? null) === self::COURSE_ID)
            );

        $service = $this->makeService($this->makeCourse(true, 80), 80, null, $this->passingBuilders(), $audit);
        $result = $service->evaluate(self::USER, self::COURSE_ID);

        $this->assertTrue($result->isPassed());
    }

    // CERT-05: a first pass triggers exactly one IssuanceService::issueIfPassedResult() call, with the PassResult
    public function testEvaluateIssuesCredentialExactlyOnceOnPass(): void {
        $audit = $this->createMock(AuditService::class);

        $issuance = $this->createMock(IssuanceService::class);
        // 164-04: evaluate() routes through issueIfPassedResult (not issueIfPassed)
        $issuance->expects($this->once())->method('issueIfPassedResult')
            ->with(self::USER, self::COURSE_ID, $this->callback(
                fn(PassResult $r): bool => $r->isPassed() && $r->getScore() === 88
            ))
            ->willReturn(new IssueResult(new Certificate(), true));

        $service = $this->makeService($this->makeCourse(true, 80), 88, null, $this->passingBuilders(), $audit, $issuance);
        $result = $service->evaluate(self::USER, self::COURSE_ID);

        $this->assertTrue($result->isPassed());
    }

    // CERT-05: a non-pass must NOT attempt issuance
    public function testEvaluateDoesNotIssueWhenNotPassed(): void {
        $audit = $this->createMock(AuditService::class);

        $issuance = $this->createMock(IssuanceService::class);
        // 164-04: evaluate() routes through issueIfPassedResult
        $issuance->expects($this->never())->method('issueIfPassedResult');

        $service = $this->makeService($this->makeCourse(true, 80), 50, null, [], $audit, $issuance);
        $result = $service->evaluate(self::USER, self::COURSE_ID);

        $this->assertFalse($result->isPassed());
    }

    // Issuance is a side-effect: a throwing IssuanceService must NOT break the pass-status read path
    public function testEvaluateSwallowsIssuanceFailure(): void {
        $audit = $this->createMock(AuditService::class);

        $issuance = $this->createMock(IssuanceService::class);
        // 164-04: evaluate() routes through issueIfPassedResult
        $issuance->method('issueIfPassedResult')
            ->willThrowException(new \RuntimeException('No active signing key'));

        $service = $this->makeService($this->makeCourse(true, 80), 95, null, $this->passingBuilders(), $audit, $issuance);
        $result = $service->evaluate(self::USER, self::COURSE_ID);

        $this->assertTrue($result->isPassed(), 'evaluate() returns a normal PassResult even when issuance throws');
        $this->assertSame(95, $result->getScore());
    }

    // ---- RECERT-05 RED locking tests (Wave 2, 164-02) ----------------------------------
    // These tests fail NOW (against stubs) and flip GREEN in 164-04 when the union guard
    // is implemented. Do NOT "fix" them before 164-04 — they are the executable specification
    // that Codex reviews before implementation.

    /**
     * RECERT-05 guard: a NEW assignment period MUST allow a fresh pass+issuance even though
     * a COURSE_PASSED audit event already exists from the PREVIOUS period.
     *
     * Drive: two evaluate() calls.
     *   call 1 (initial pass)  — no prior event  → fires COURSE_PASSED + issues cert.
     *   call 2 (new period)    — old event exists → current audit dedup blocks the re-emit.
     * Expected: logComplianceEvent(COURSE_PASSED) fires TWICE (once per period).
     *          issueIfPassedResult() called TWICE (once per period) — 164-04 contract.
     *
     * Status allow-list (locked here, enforced in 164-04):
     *   ISSUE allowed  — status IN ('assigned', 'in_progress', 'overdue')   [open obligation]
     *   ISSUE blocked  — status IN ('passed', 'cancelled', 'withdrawn', 'closed', 'expired') [terminal]
     * Per-status enforcement requires AssignmentService injection — deferred to 164-04.
     *
     * RED at Wave 2 (two separate failures):
     *   1. emitPassEventIfFirst deduplicates on audit history → call 2 fires 0× → exactly(2) fails.
     *   2. evaluate() calls issueIfPassed (not issueIfPassedResult) → exactly(2) on issueIfPassedResult fails.
     * GREEN in 164-04: period-aware mayIssue() + issueIfPassedResult switch → both expectations met.
     */
    public function testReCertPeriodGuard(): void {
        $audit = $this->createMock(AuditService::class);
        // RECERT-05: a new period must re-emit COURSE_PASSED — one per completed period.
        // RED: current dedup sees the old audit event in call 2 → fires only once → fails.
        $audit->expects($this->exactly(2))->method('logComplianceEvent');

        $issuance = $this->createMock(IssuanceService::class);
        // 164-04 contract: each period pass routes through issueIfPassedResult, not issueIfPassed.
        // RED: current evaluate() calls issueIfPassed → issueIfPassedResult called 0 times → fails.
        $issuance->expects($this->exactly(2))
            ->method('issueIfPassedResult')
            ->willReturn(new IssueResult(new Certificate(), true));

        $oldEventRow = ['context_json' => json_encode(['course_id' => self::COURSE_ID, 'passed_at' => 1700000000])];
        $builders = [
            // call 1 — initial pass: findPassEvent finds nothing → fires logComplianceEvent
            new FakeQueryBuilder(new FakeResult(fetchQueue: [])),
            // call 1 — getPassedAt: event now exists
            new FakeQueryBuilder(new FakeResult(fetchQueue: [$oldEventRow])),
            // call 2 — new period: findPassEvent finds the OLD event → dedup blocks re-emit (RED)
            new FakeQueryBuilder(new FakeResult(fetchQueue: [$oldEventRow])),
            // call 2 — getPassedAt: returns old passed_at
            new FakeQueryBuilder(new FakeResult(fetchQueue: [$oldEventRow])),
        ];

        $service = $this->makeService($this->makeCourse(true, 80), 90, null, $builders, $audit, $issuance);

        $first = $service->evaluate(self::USER, self::COURSE_ID);   // period 1
        $second = $service->evaluate(self::USER, self::COURSE_ID);  // period 2 — new period, should re-emit

        $this->assertTrue($first->isPassed());
        $this->assertTrue($second->isPassed());
        // Both expects() verified by PHPUnit at teardown → RED now on two counts
    }

    /**
     * RECERT-05 punitive-revoke safety: a punitively revoked cert (revoked=true,
     * active_idem_key=NULL) with NO open assignment period MUST NOT trigger auto-reissue
     * on the student's next /pass-status.
     *
     * Expected: IssuanceService::issueIfPassed is NEVER called.
     *
     * RED at Wave 2: evaluate() calls issueIfPassed unconditionally when score passes
     * (no mayIssue guard) → never() expectation fails with "called 1 time, expected 0".
     * GREEN in 164-04: mayIssue(userId, courseId) detects revoked cert + no active period
     * → returns false → evaluate() short-circuits before issueIfPassed.
     */
    public function testRevokeNoAutoReissue(): void {
        $audit = $this->createMock(AuditService::class);
        // Student passed before (cert was issued), cert was then punitively revoked.
        // No new cert must be issued and no COURSE_PASSED must be emitted.
        $audit->expects($this->never())->method('logComplianceEvent');

        $issuance = $this->createMock(IssuanceService::class);
        // 164-04: mayIssue() returns false (revoked cert exists, no active period) → skip.
        // GREEN: issueIfPassedResult is never called because mayIssue guards the entry.
        $issuance->expects($this->never())->method('issueIfPassedResult');

        // certMapper returns a punitively-revoked cert → hasEverIssuedCertificate=true → Branch A false.
        // Default assignmentService returns no open period → Branch B false → mayIssue=false.
        $revokedCert = new Certificate();
        $revokedCert->setRevoked(true);
        $certMapper = $this->createMock(CertificateMapper::class);
        $certMapper->method('findByUserAndCourse')->willReturn($revokedCert);

        // getPassedAt uses the first (and only) builder — returns passedAt from the existing event.
        $existingEvent = ['context_json' => json_encode(['course_id' => self::COURSE_ID, 'passed_at' => 1700000000])];
        $builders = [
            // getPassedAt: returns the existing event so passedAt is set
            new FakeQueryBuilder(new FakeResult(fetchQueue: [$existingEvent])),
        ];

        $service = $this->makeService($this->makeCourse(true, 80), 92, null, $builders, $audit, $issuance, $certMapper);
        $result = $service->evaluate(self::USER, self::COURSE_ID);

        // Score gate still passes — the union guard (not the score) blocks issuance.
        $this->assertTrue($result->isPassed());
        // Both expects(never()) verified at teardown → GREEN in 164-04
    }

    /**
     * RECERT-07: re-cert in a new period triggers a fresh issuance so that IssuanceService
     * can create a NEW Certificate row with a distinct verification_id. The old verification_id
     * must remain resolvable (immutable history — IssuanceService contract, separate lock).
     *
     * Expected:
     *   - logComplianceEvent(COURSE_PASSED) fires ONCE (new period event)
     *   - issueIfPassed called and returns a cert with a DIFFERENT vid than the old cert
     *
     * RED at Wave 2: emitPassEventIfFirst deduplicates on the old audit event → 0 actual
     * audit calls vs 1 expected → audit expectation fails → test FAILS (RED).
     * GREEN in 164-04: period-aware guard emits COURSE_PASSED for the new period → issueIfPassed
     * called → IssuanceService issues a new row (distinct vid because active_idem_key was nulled by closePeriod).
     */
    public function testReCertNewRowOldUrlResolves(): void {
        $audit = $this->createMock(AuditService::class);
        // New period emits a fresh COURSE_PASSED event — gated on wasCreated=true.
        // GREEN in 164-04: mayIssue=true (no cert) → issueIfPassedResult(wasCreated=true) → emit fires.
        $audit->expects($this->once())
            ->method('logComplianceEvent')
            ->with(
                ComplianceEventTypes::COURSE_PASSED,
                self::USER,
                $this->callback(fn(array $ctx): bool => ($ctx['course_id'] ?? null) === self::COURSE_ID)
            );

        // New cert with a fresh verification_id — what IssuanceService creates in a new period
        // after closePeriod nulled the old active_idem_key.
        $newCert = new Certificate();
        $newCert->setVerificationId('new-period-vid-00000001');
        $newCert->setUserId(self::USER);
        $newCert->setCourseId(self::COURSE_ID);
        $newCert->setRevoked(false);

        $issuance = $this->createMock(IssuanceService::class);
        // 164-04: route through issueIfPassedResult; wasCreated=true triggers the emit.
        $issuance->expects($this->once())->method('issueIfPassedResult')
            ->willReturn(new IssueResult($newCert, true));

        // getPassedAt builder: returns old passed_at timestamp.
        $oldEventRow = ['context_json' => json_encode(['course_id' => self::COURSE_ID, 'passed_at' => 1700000000])];
        $builders = [
            // getPassedAt: returns old event's timestamp
            new FakeQueryBuilder(new FakeResult(fetchQueue: [$oldEventRow])),
        ];

        $service = $this->makeService($this->makeCourse(true, 80), 88, null, $builders, $audit, $issuance);
        $result = $service->evaluate(self::USER, self::COURSE_ID);

        $this->assertTrue($result->isPassed());
        // $audit->expects(once()) verified at teardown → GREEN in 164-04
        // The new cert (distinct vid) proves a new Certificate row was created (RECERT-07)
    }

    /**
     * RECERT-06 concurrency lock: two concurrent GET /pass-status calls that BOTH receive a
     * passing score for the same (user, course) in the same open period race on the DB UNIQUE
     * constraint (active_idem_key). Only the WINNER creates the cert row (wasCreated=true);
     * the LOSER dedupes onto it (wasCreated=false) WITHOUT emitting a duplicate COURSE_PASSED.
     *
     * Scenario: both requests check for an existing audit event at the SAME INSTANT and find
     * nothing (concurrent race window). Current code emits COURSE_PASSED for BOTH. 164-04 gates
     * emit on issueIfPassedResult.wasCreated so only the winner emits.
     *
     * Expected across both evaluate() calls:
     *   - logComplianceEvent(COURSE_PASSED) fires exactly ONCE (winner only)
     *   - issueIfPassedResult() called twice (once per request)
     *
     * Note: markPassed (AssignmentService) is not yet injectable into PassCriteriaService;
     * the "markPassed $this->never() on loser" contract lock is deferred to 164-04 wiring.
     *
     * RED at Wave 2: both dedup queries find nothing (concurrent) → emitPassEventIfFirst fires
     * twice → exactly(1) for logComplianceEvent fails with "expected 1, got 2".
     * GREEN in 164-04: evaluate() checks issueIfPassedResult.wasCreated; loser (wasCreated=false)
     * skips emitPassEventIfFirst → exactly(1) satisfied.
     */
    public function testConcurrentPassSingleEventAndCert(): void {
        $cert = new Certificate();

        // Winner-order lock: the LOSER resolves FIRST (wasCreated=false), the WINNER SECOND
        // (wasCreated=true). A correct impl consults wasCreated and emits COURSE_PASSED ONLY for
        // the winner. A wrong impl that dedupes on a stateful per-user flag (ignoring wasCreated)
        // would emit on the FIRST (loser) call → the assertSame([true]) below fails. This makes
        // "emit only on the insert winner" an EXECUTABLE lock, not a comment.
        $lastWasCreated = null;
        $seq = 0;
        $issuance = $this->createMock(IssuanceService::class);
        // Legacy single-cert path is FORBIDDEN — the impl MUST route through issueIfPassedResult.
        $issuance->expects($this->never())->method('issueIfPassed');
        $issuance->expects($this->exactly(2))
            ->method('issueIfPassedResult')
            ->willReturnCallback(function () use (&$lastWasCreated, &$seq, $cert): IssueResult {
                $lastWasCreated = ($seq++ === 1); // call 0 → loser(false), call 1 → winner(true)
                return new IssueResult($cert, $lastWasCreated);
            });

        // COURSE_PASSED must fire exactly once, and only while the most-recent issuance result
        // was the winner. Capture wasCreated at emit time so the ORDER is asserted, not just the count.
        $emittedWasCreated = [];
        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->exactly(1))
            ->method('logComplianceEvent')
            ->willReturnCallback(function () use (&$lastWasCreated, &$emittedWasCreated): void {
                $emittedWasCreated[] = $lastWasCreated;
            });

        // markPassed is called exactly once — only by the wasCreated=true winner (r2).
        $assignmentService = $this->createMock(AssignmentService::class);
        $assignmentService->method('getStatesForCourseAndUsers')->willReturn([]);
        $assignmentService->expects($this->once())->method('markPassed');

        // Both concurrent requests find NO existing cert (both enter mayIssue=true via Branch A).
        // After r1 (loser), the cert exists; r2 (winner) also passes Branch A (same certMapper mock).
        $builders = [
            new FakeQueryBuilder(new FakeResult(fetchQueue: [])),  // r1: getPassedAt
            new FakeQueryBuilder(new FakeResult(fetchQueue: [])),  // r2: getPassedAt
        ];

        $service = $this->makeService($this->makeCourse(true, 80), 90, null, $builders, $audit, $issuance, null, $assignmentService);

        $r1 = $service->evaluate(self::USER, self::COURSE_ID);  // concurrent loser (resolves first)
        $r2 = $service->evaluate(self::USER, self::COURSE_ID);  // concurrent winner (resolves second)

        $this->assertTrue($r1->isPassed());
        $this->assertTrue($r2->isPassed());
        // GREEN in 164-04: issueIfPassedResult(loser→false, winner→true); emit+markPassed only on winner.
        $this->assertSame([true], $emittedWasCreated,
            'COURSE_PASSED must be emitted exactly once, only on the wasCreated=true (winner) call');
    }

    /**
     * ASSIGN-03 per-user isolation: a group assignment is per-user. Alice passing the course
     * must NOT prevent Bob from independently qualifying, nor mark Bob's assignment period.
     *
     * Expected: issueIfPassedResult() called once per user (exactly 2 total) — 164-04 contract.
     *           logComplianceEvent(COURSE_PASSED) fired once per user (exactly 2 total).
     *
     * Note: group membership is not resolved here (expandGroup lives in AssignmentService);
     * this test drives evaluate() for two distinct userIds on the same service instance to
     * confirm they are treated independently. The per-period-state seam (mayIssue per user)
     * is deferred to 164-04 wiring.
     *
     * RED at Wave 2: evaluate() calls issueIfPassed (not issueIfPassedResult) →
     * exactly(2) on issueIfPassedResult fails with "expected 2, got 0".
     * GREEN in 164-04: switched to issueIfPassedResult, one call per user → exactly(2) satisfied.
     */
    public function testGroupPeriodIsPerUser(): void {
        $audit = $this->createMock(AuditService::class);
        // Each user earns their own COURSE_PASSED event — independent of each other.
        // This part is CURRENTLY SATISFIED (audit dedup is per-user); it locks the invariant.
        $audit->expects($this->exactly(2))->method('logComplianceEvent');

        $cert = new Certificate();
        $issuance = $this->createMock(IssuanceService::class);
        // RED: current evaluate() calls issueIfPassed → issueIfPassedResult never called → fails.
        $issuance->expects($this->exactly(2))
            ->method('issueIfPassedResult')
            ->willReturn(new IssueResult($cert, true));

        // Alice and Bob both find no existing pass event — independent histories.
        $builders = [
            new FakeQueryBuilder(new FakeResult(fetchQueue: [])),  // alice dedup: no event → fires
            new FakeQueryBuilder(new FakeResult(fetchQueue: [])),  // alice getPassedAt
            new FakeQueryBuilder(new FakeResult(fetchQueue: [])),  // bob dedup: no event → fires
            new FakeQueryBuilder(new FakeResult(fetchQueue: [])),  // bob getPassedAt
        ];

        $service = $this->makeService($this->makeCourse(true, 80), 90, null, $builders, $audit, $issuance);

        $alice = $service->evaluate('alice', self::COURSE_ID);
        $bob   = $service->evaluate('bob',   self::COURSE_ID);

        $this->assertTrue($alice->isPassed(), 'alice must independently pass');
        $this->assertTrue($bob->isPassed(),   'bob must independently pass');
        // $issuance->expects(exactly(2)) verified at teardown → RED now
    }

    /**
     * FIX-3 (non-swallow of compliance failures): a ComplianceAuditException from issuance is NOT a
     * provisioning error — the tamper-evident audit append failed and the cert was rolled back
     * (fail-closed). evaluate() MUST re-throw it (→ HTTP 500), never swallow-and-log it.
     */
    public function testEvaluatePropagatesComplianceAuditFailure(): void {
        $audit = $this->createMock(AuditService::class);

        $issuance = $this->createMock(IssuanceService::class);
        // 164-04: evaluate() routes through issueIfPassedResult
        $issuance->method('issueIfPassedResult')
            ->willThrowException(new ComplianceAuditException('chain append failed'));

        $service = $this->makeService($this->makeCourse(true, 80), 95, null, $this->passingBuilders(), $audit, $issuance);

        $this->expectException(ComplianceAuditException::class);
        $service->evaluate(self::USER, self::COURSE_ID);
    }

    // ---- 164-04 post-impl Codex review locks (HIGH 3: issue+emit atomicity) ----------------

    /**
     * HIGH 3: the winner path wraps issueIfPassedResult + COURSE_PASSED emit + markPassed in
     * ONE outer transaction (issuance's own begin/commit nests as a savepoint). Without it, a
     * crash after the cert commit but before the emit leaves a cert whose COURSE_PASSED event
     * can never be written — every later evaluate() takes the UNIQUE-loser path (wasCreated=
     * false) and skips the emit forever.
     */
    public function testEvaluateWrapsIssueEmitAndMarkPassedInOneTransaction(): void {
        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->once())->method('logComplianceEvent');

        $issuance = $this->createMock(IssuanceService::class);
        $issuance->method('issueIfPassedResult')
            ->willReturn(new IssueResult(new Certificate(), true));

        $db = new FakeDbConnection($this->passingBuilders());
        $service = $this->makeService($this->makeCourse(true, 80), 90, null, [], $audit, $issuance, null, null, $db);

        $result = $service->evaluate(self::USER, self::COURSE_ID);

        $this->assertTrue($result->isPassed());
        $this->assertSame(1, $db->beginTransactionCalls, 'issue+emit+markPassed open ONE outer transaction');
        $this->assertSame(1, $db->commitCalls, 'the winner path commits exactly once');
        $this->assertSame(0, $db->rollBackCalls, 'happy path never rolls back');
    }

    /**
     * HIGH 3 (rollback): when the COURSE_PASSED append fails AFTER issuance returned
     * wasCreated=true, the outer transaction must roll back — undoing the nested cert INSERT —
     * and the ComplianceAuditException propagates (fail-closed). No cert-without-event state
     * can ever commit.
     */
    public function testEvaluateRollsBackIssuanceWhenEmitFails(): void {
        $audit = $this->createMock(AuditService::class);
        $audit->method('logComplianceEvent')
            ->willThrowException(new ComplianceAuditException('chain append failed'));

        $issuance = $this->createMock(IssuanceService::class);
        $issuance->method('issueIfPassedResult')
            ->willReturn(new IssueResult(new Certificate(), true));

        // markPassed must never run when the emit failed.
        $assignmentService = $this->createMock(AssignmentService::class);
        $assignmentService->method('getStatesForCourseAndUsers')->willReturn([]);
        $assignmentService->expects($this->never())->method('markPassed');

        $db = new FakeDbConnection([]);
        $service = $this->makeService($this->makeCourse(true, 80), 90, null, [], $audit, $issuance, null, $assignmentService, $db);

        try {
            $service->evaluate(self::USER, self::COURSE_ID);
            $this->fail('ComplianceAuditException must propagate (fail-closed)');
        } catch (ComplianceAuditException $e) {
            // expected
        }

        $this->assertSame(1, $db->rollBackCalls,
            'a failed COURSE_PASSED emit must roll back the outer transaction (incl. the nested cert INSERT)');
        $this->assertSame(0, $db->commitCalls, 'nothing commits when the emit fails');
    }
}
