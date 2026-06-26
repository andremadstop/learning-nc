<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\PassCriteriaService;
use OCA\Learning\Service\PassResult;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use PHPUnit\Framework\TestCase;

/**
 * Test stubs for PassCriteriaService — un-skipped in 154-03-PLAN.md.
 * Each method documents the behavioral contract it will assert.
 *
 * PASS-05 structural note: exam-mode sessions have no guess button in the UI;
 * LeitnerService::mapRatingToBox() maps rating=1 to box=1 (never box=5).
 * "Guess exclusion" is structural — no is_guessed DB column needed.
 * Tests assert this by constructing exam sessions without guessed data.
 */
class PassCriteriaServiceTest extends TestCase {

    // PASS-01: cert_enabled=false → PassResult::notApplicable() returned, no DB writes
    public function testEvaluateReturnsNotApplicableWhenCertDisabled(): void {
        $this->markTestSkipped('Pending 154-03: implement evaluate() — cert_enabled=false path');
    }

    // PASS-02 + PASS-05: exam score >= threshold → Gate 1 passes
    public function testEvaluatePassesWhenExamScoreMeetsThreshold(): void {
        $this->markTestSkipped('Pending 154-03: exam score >= cert_pass_percent → passed=true');
    }

    // PASS-02 + PASS-05: exam score < threshold → Gate 1 fails, passed=false
    public function testEvaluateFailsWhenExamScoreBelowThreshold(): void {
        $this->markTestSkipped('Pending 154-03: exam score < cert_pass_percent → passed=false');
    }

    // PASS-03: required_pool_ids=[]: poolsMastered=true (trivially satisfied)
    public function testEvaluatePoolsMasteredTrueWhenNoRequiredPools(): void {
        $this->markTestSkipped('Pending 154-03: empty required_pool_ids → poolsMastered=true');
    }

    // PASS-03: pool mastery_rate < threshold → Gate 2 fails, passed=false
    public function testEvaluateFailsWhenPoolMasteryBelowThreshold(): void {
        $this->markTestSkipped('Pending 154-03: mastery_rate < cert_pass_percent → passed=false');
    }

    // PASS-05: structural — ReadinessService is never referenced in PassCriteriaService
    public function testPassCriteriaServiceDoesNotReferenceReadinessService(): void {
        $this->markTestSkipped('Pending 154-03: grep assert — no ReadinessService in PassCriteriaService.php');
    }

    // PASS-07: first qualification writes exactly one audit event; second evaluate() does not duplicate it
    public function testEmitsPassEventOnlyOnFirstQualification(): void {
        $this->markTestSkipped('Pending 154-03: double-evaluate produces exactly 1 course.passed audit row');
    }

    // PASS-04: cert_validity_days is stored but NOT evaluated in Phase 154 (expiry logic is Phase 155)
    public function testCertValidityDaysIsNotEvaluatedByPassCriteria(): void {
        $this->markTestSkipped('Pending 154-03: grep assert — validity_days not referenced in PassCriteriaService::evaluate()');
    }
}
