<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Service\AuditService;
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
        $audit->expects($this->never())->method('logEvent');

        $service = $this->makeService($this->makeCourse(false), null, null, [], $audit);
        $result = $service->evaluate(self::USER, self::COURSE_ID);

        $this->assertInstanceOf(PassResult::class, $result);
        $this->assertFalse($result->isApplicable());
        $this->assertFalse($result->isPassed());
    }

    // PASS-02 + PASS-05: exam score == threshold → Gate 1 passes, qualifies (no required pools)
    public function testEvaluatePassesWhenExamScoreMeetsThreshold(): void {
        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->once())->method('logEvent')
            ->with('course.passed', self::USER, $this->callback(
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
        $audit->expects($this->never())->method('logEvent');

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
        $audit->expects($this->once())->method('logEvent');

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
        $audit->expects($this->never())->method('logEvent');

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
        $audit->expects($this->once())->method('logEvent');

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
}
