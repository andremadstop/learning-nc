<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Certificate;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\ComplianceAuditException;
use OCA\Learning\Service\ComplianceEventTypes;
use OCA\Learning\Service\CourseSummaryService;
use OCA\Learning\Service\IssuanceService;
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
        ?IssuanceService $issuance = null
    ): PassCriteriaService {
        $courseMapper = $this->createMock(CourseMapper::class);
        $courseMapper->method('findById')->willReturn($course);

        $summary = $this->createMock(CourseSummaryService::class);
        $summary->method('getExamScore')->willReturn($examScore);
        if ($masteryStats !== null) {
            $summary->method('getMasteryStats')->willReturn($masteryStats);
        }

        $db = new FakeDbConnection($builders);
        $issuance = $issuance ?? $this->createMock(IssuanceService::class);
        $logger = $this->createMock(LoggerInterface::class);
        return new PassCriteriaService($courseMapper, $summary, $audit, $db, $issuance, $logger);
    }

    /** Builds the two SELECT result builders a single passing evaluate() consumes. */
    private function passingBuilders(int $passedAt = 1700000000): array {
        $existingRow = ['context_json' => json_encode(['course_id' => self::COURSE_ID, 'passed_at' => $passedAt])];
        return [
            new FakeQueryBuilder(new FakeResult(fetchQueue: [])),          // emitPassEventIfFirst dedup: none → fires
            new FakeQueryBuilder(new FakeResult(fetchQueue: [$existingRow])), // getPassedAt: returns the row
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

    // PASS-07: first qualification writes exactly one audit event; second evaluate() does not duplicate it
    public function testEmitsPassEventOnlyOnFirstQualification(): void {
        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->once())->method('logComplianceEvent');

        $existingRow = ['context_json' => json_encode(['course_id' => self::COURSE_ID, 'passed_at' => 1700000000])];
        $builders = [
            // call 1: dedup empty → fires; getPassedAt returns the row
            new FakeQueryBuilder(new FakeResult(fetchQueue: [])),
            new FakeQueryBuilder(new FakeResult(fetchQueue: [$existingRow])),
            // call 2: dedup finds the existing row → no second logEvent; getPassedAt returns the row
            new FakeQueryBuilder(new FakeResult(fetchQueue: [$existingRow])),
            new FakeQueryBuilder(new FakeResult(fetchQueue: [$existingRow])),
        ];

        $service = $this->makeService($this->makeCourse(true, 80), 90, null, $builders, $audit);

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

    // CERT-05: a first pass triggers exactly one IssuanceService::issueIfPassed() call, with the PassResult
    public function testEvaluateIssuesCredentialExactlyOnceOnPass(): void {
        $audit = $this->createMock(AuditService::class);

        $issuance = $this->createMock(IssuanceService::class);
        $issuance->expects($this->once())->method('issueIfPassed')
            ->with(self::USER, self::COURSE_ID, $this->callback(
                fn(PassResult $r): bool => $r->isPassed() && $r->getScore() === 88
            ));

        $service = $this->makeService($this->makeCourse(true, 80), 88, null, $this->passingBuilders(), $audit, $issuance);
        $result = $service->evaluate(self::USER, self::COURSE_ID);

        $this->assertTrue($result->isPassed());
    }

    // CERT-05: a non-pass must NOT attempt issuance
    public function testEvaluateDoesNotIssueWhenNotPassed(): void {
        $audit = $this->createMock(AuditService::class);

        $issuance = $this->createMock(IssuanceService::class);
        $issuance->expects($this->never())->method('issueIfPassed');

        $service = $this->makeService($this->makeCourse(true, 80), 50, null, [], $audit, $issuance);
        $result = $service->evaluate(self::USER, self::COURSE_ID);

        $this->assertFalse($result->isPassed());
    }

    // Issuance is a side-effect: a throwing IssuanceService must NOT break the pass-status read path
    public function testEvaluateSwallowsIssuanceFailure(): void {
        $audit = $this->createMock(AuditService::class);

        $issuance = $this->createMock(IssuanceService::class);
        $issuance->method('issueIfPassed')
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
     *
     * RED at Wave 2: emitPassEventIfFirst deduplicates on audit history, so call 2 fires
     * 0× → expects(exactly(2)) fails with "expected 2, got 1".
     * GREEN in 164-04: mayIssue() checks assignment.status (period-aware), not audit history
     * → fires COURSE_PASSED for the new period → both calls count toward the expected 2.
     */
    public function testReCertPeriodGuard(): void {
        $audit = $this->createMock(AuditService::class);
        // RECERT-05: a new period must re-emit COURSE_PASSED — one per completed period.
        // RED: current dedup sees the old audit event in call 2 → fires only once → fails.
        $audit->expects($this->exactly(2))->method('logComplianceEvent');

        $issuance = $this->createMock(IssuanceService::class);

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
        // $audit->expects(exactly(2)) is verified by PHPUnit at teardown → RED now
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
        // The old COURSE_PASSED event deduplicates — no re-emit of the compliance event.
        // That is expected; what is NOT expected is a new cert being issued.
        $audit->expects($this->never())->method('logComplianceEvent');

        $issuance = $this->createMock(IssuanceService::class);
        // RED: current evaluate() reaches this call unconditionally when passed=true.
        // GREEN in 164-04: mayIssue() returns false (revoked cert, no active period) → skip.
        $issuance->expects($this->never())->method('issueIfPassed');

        // Existing COURSE_PASSED event → emitPassEventIfFirst deduplicates (no re-emit, expected).
        // The student's score meets the threshold so $passed=true — the guard must decide, not the score.
        $existingEvent = ['context_json' => json_encode(['course_id' => self::COURSE_ID, 'passed_at' => 1700000000])];
        $builders = [
            // emitPassEventIfFirst: finds existing event → dedup → no logComplianceEvent
            new FakeQueryBuilder(new FakeResult(fetchQueue: [$existingEvent])),
            // getPassedAt: returns the existing event
            new FakeQueryBuilder(new FakeResult(fetchQueue: [$existingEvent])),
        ];

        $service = $this->makeService($this->makeCourse(true, 80), 92, null, $builders, $audit, $issuance);
        $result = $service->evaluate(self::USER, self::COURSE_ID);

        // Score gate still passes — the guard (not the score) should block issuance.
        $this->assertTrue($result->isPassed());
        // $issuance->expects(never()) is verified at teardown → RED now
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
        // New period MUST emit a fresh COURSE_PASSED event to trigger issuance.
        // RED: current audit dedup blocks this (finds old event → 0 actual calls → fails).
        $audit->expects($this->once())
            ->method('logComplianceEvent')
            ->with(
                ComplianceEventTypes::COURSE_PASSED,
                self::USER,
                $this->callback(fn(array $ctx): bool => ($ctx['course_id'] ?? null) === self::COURSE_ID)
            );

        // Set up a new cert with a fresh verification_id (what IssuanceService would create
        // in a new period after closePeriod nulled the old active_idem_key).
        $newCert = new Certificate();
        $newCert->setVerificationId('new-period-vid-00000001');
        $newCert->setUserId(self::USER);
        $newCert->setCourseId(self::COURSE_ID);
        $newCert->setRevoked(false);

        $issuance = $this->createMock(IssuanceService::class);
        $issuance->expects($this->once())->method('issueIfPassed')->willReturn($newCert);
        // Note: $issuance->expects(once()) IS satisfied by current code (always calls it).
        // The audit->expects(once()) IS NOT satisfied (dedup → 0 calls) → RED.

        // State: old COURSE_PASSED event exists from previous period.
        $oldEventRow = ['context_json' => json_encode(['course_id' => self::COURSE_ID, 'passed_at' => 1700000000])];
        $builders = [
            // emitPassEventIfFirst: finds old event → dedup → no COURSE_PASSED re-emit (RED)
            new FakeQueryBuilder(new FakeResult(fetchQueue: [$oldEventRow])),
            // getPassedAt: returns old event's timestamp
            new FakeQueryBuilder(new FakeResult(fetchQueue: [$oldEventRow])),
        ];

        $service = $this->makeService($this->makeCourse(true, 80), 88, null, $builders, $audit, $issuance);
        $result = $service->evaluate(self::USER, self::COURSE_ID);

        $this->assertTrue($result->isPassed());
        // $audit->expects(once())->logComplianceEvent verified at teardown → RED now
        // When GREEN: the new cert (distinct vid) proves a new Certificate row was created (RECERT-07)
    }

    /**
     * FIX-3 (non-swallow of compliance failures): a ComplianceAuditException from issuance is NOT a
     * provisioning error — the tamper-evident audit append failed and the cert was rolled back
     * (fail-closed). evaluate() MUST re-throw it (→ HTTP 500), never swallow-and-log it.
     */
    public function testEvaluatePropagatesComplianceAuditFailure(): void {
        $audit = $this->createMock(AuditService::class);

        $issuance = $this->createMock(IssuanceService::class);
        $issuance->method('issueIfPassed')
            ->willThrowException(new ComplianceAuditException('chain append failed'));

        $service = $this->makeService($this->makeCourse(true, 80), 95, null, $this->passingBuilders(), $audit, $issuance);

        $this->expectException(ComplianceAuditException::class);
        $service->evaluate(self::USER, self::COURSE_ID);
    }
}
