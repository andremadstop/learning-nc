<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\RagChunkMapper;
use OCA\Learning\Db\UserTelosMapper;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\CourseSummaryService;
use OCA\Learning\Service\GeminiService;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CourseSummaryService::getExamScore().
 *
 * getExamScore() contract:
 * - Queries learning_sessions WHERE mode='exam' AND completed_at IS NOT NULL AND course_id=?
 * - Computes score per row: (int) round(correct_answers * 100 / total_questions)
 * - Returns the BEST score across all qualifying rows (PHP loop, not SQL MAX with division)
 * - Returns null when no completed exam sessions exist for the user+course
 *
 * The mode='exam' + completed_at filters are the structural guess exclusion (PASS-05):
 * guessed answers only exist in the Leitner/FSRS flow, never in exam-mode sessions.
 */
class CourseSummaryServiceTest extends TestCase {

    private const COURSE_ID = 7;
    private const USER = 'alice';

    private function makeService(FakeDbConnection $db): CourseSummaryService {
        return new CourseSummaryService(
            $db,
            $this->createMock(CourseMemberMapper::class),
            $this->createMock(BadgeService::class),
            $this->createMock(StreakService::class),
            $this->createMock(RagChunkMapper::class),
            $this->createMock(GeminiService::class),
            $this->createMock(UserTelosMapper::class),
        );
    }

    // Returns null when no completed exam sessions
    public function testGetExamScoreReturnsNullWithNoExamSessions(): void {
        $builder = new FakeQueryBuilder(new FakeResult(fetchQueue: []));
        $service = $this->makeService(new FakeDbConnection([$builder]));

        $this->assertNull($service->getExamScore(self::USER, self::COURSE_ID));
    }

    // Returns best score across multiple exam sessions (PHP round, not SQL integer division)
    public function testGetExamScoreReturnsBestScore(): void {
        $builder = new FakeQueryBuilder(new FakeResult(fetchQueue: [
            ['correct_answers' => '8', 'total_questions' => '10'], // 80
            ['correct_answers' => '9', 'total_questions' => '10'], // 90 (best)
            ['correct_answers' => '7', 'total_questions' => '10'], // 70
        ]));
        $service = $this->makeService(new FakeDbConnection([$builder]));

        $this->assertSame(90, $service->getExamScore(self::USER, self::COURSE_ID));
    }

    // Only counts mode=exam AND completed_at IS NOT NULL rows (structural guess/training exclusion)
    public function testGetExamScoreIgnoresTrainingAndIncompleteExams(): void {
        $builder = new FakeQueryBuilder(new FakeResult(fetchQueue: [
            ['correct_answers' => '7', 'total_questions' => '10'], // 70
        ]));
        $service = $this->makeService(new FakeDbConnection([$builder]));

        $this->assertSame(70, $service->getExamScore(self::USER, self::COURSE_ID));

        // Prove the query restricts to mode='exam' and excludes incomplete sessions.
        $values = array_column($builder->namedParameters, 'value');
        $this->assertContains('exam', $values, 'getExamScore must filter mode=exam (excludes training)');

        $hasCompletedFilter = false;
        foreach (array_merge($builder->whereCalls, $builder->andWhereCalls) as $cond) {
            if (is_array($cond) && ($cond['type'] ?? '') === 'isNotNull' && ($cond['field'] ?? '') === 'completed_at') {
                $hasCompletedFilter = true;
            }
        }
        $this->assertTrue($hasCompletedFilter, 'getExamScore must exclude incomplete exams (completed_at IS NOT NULL)');
    }
}
