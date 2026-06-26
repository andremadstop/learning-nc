<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\CourseSummaryService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use PHPUnit\Framework\TestCase;

/**
 * Test stubs for CourseSummaryService::getExamScore() — un-skipped in 154-03-PLAN.md.
 *
 * getExamScore() contract:
 * - Queries learning_sessions WHERE mode='exam' AND completed_at IS NOT NULL AND course_id=?
 * - Computes score per row: (int) round(correct_answers * 100 / total_questions)
 * - Returns the BEST score across all qualifying rows (PHP loop, not SQL MAX with division)
 * - Returns null when no completed exam sessions exist for the user+course
 */
class CourseSummaryServiceTest extends TestCase {

    // Returns null when no completed exam sessions
    public function testGetExamScoreReturnsNullWithNoExamSessions(): void {
        $this->markTestSkipped('Pending 154-03: no exam rows → getExamScore returns null');
    }

    // Returns best score across multiple exam sessions (PHP round, not SQL integer division)
    public function testGetExamScoreReturnsBestScore(): void {
        $this->markTestSkipped('Pending 154-03: multiple exam rows → returns max of PHP-rounded scores');
    }

    // Only counts mode=exam AND completed_at IS NOT NULL rows
    public function testGetExamScoreIgnoresTrainingAndIncompleteExams(): void {
        $this->markTestSkipped('Pending 154-03: training sessions and incomplete exams excluded');
    }
}
