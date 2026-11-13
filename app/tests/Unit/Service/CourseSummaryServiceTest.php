<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\RagChunkMapper;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\CourseSummaryService;
use OCA\Learning\Service\GeminiService;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

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
#[AllowMockObjectsWithoutExpectations]
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
        );
    }

    // Returns null when no completed exam sessions
    public function testGetExamScoreReturnsNullWithNoExamSessions(): void {
        $builder = new FakeQueryBuilder(new FakeResult(fetchQueue: []));
        $service = $this->makeService(new FakeDbConnection([$builder]));

        $this->assertNull($service->getExamScore(self::USER, self::COURSE_ID));
    }

    /**
     * Codeberg #9 — practice exams never count towards a certificate. The certificate's pass
     * evaluation reads the best exam score through this method, so it must filter them out.
     */
    public function testGetExamScoreExcludesPracticeExams(): void {
        $builder = new FakeQueryBuilder(new FakeResult(fetchQueue: []));
        $service = $this->makeService(new FakeDbConnection([$builder]));

        $service->getExamScore(self::USER, self::COURSE_ID);

        $this->assertContains(['type' => 'isNull', 'field' => 'exam_kind'], $builder->andWhereCalls);
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

    /**
     * GitHub #35: the per-pool best score is the certificate's pass rule. It must count only
     * counting exams of THIS course: mode='exam', exam_kind IS NULL (a practice exam stores an
     * anchor pool_id and would otherwise satisfy that pool), completed, course_id filter.
     */
    public function testBestExamScoresByPoolCountsOnlyCountingExamsOfTheCourse(): void {
        $builder = new FakeQueryBuilder(new FakeResult(fetchQueue: []));
        $service = $this->makeService(new FakeDbConnection([$builder]));

        $service->getBestExamScoresByPool(self::USER, self::COURSE_ID, [3, 4]);

        // where() and andWhere() together: the user filter sits in where(). Without it every
        // learner would inherit the best exam of anyone in the same course and pool.
        $conds = array_merge($builder->whereCalls, $builder->andWhereCalls);
        $this->assertContains(['type' => 'isNull', 'field' => 'exam_kind'], $conds, 'practice exams must never count');
        $this->assertContains(['type' => 'isNotNull', 'field' => 'completed_at'], $conds);
        $byLeft = [];
        foreach ($conds as $c) {
            if (isset($c['left'])) {
                $byLeft[$c['left']] = $c['right']['value'] ?? null;
            }
        }
        $this->assertSame(self::USER, $byLeft['user_id'] ?? null, 'scores must be the learner\'s own');
        $this->assertSame(self::COURSE_ID, $byLeft['course_id'] ?? null);
        $this->assertSame('exam', $byLeft['mode'] ?? null);
        $this->assertSame([3, 4], $byLeft['pool_id'] ?? null);
    }

    // GitHub #35: best attempt per pool, same rounding as getExamScore(); zero-question rows skipped.
    public function testBestExamScoresByPoolPicksBestPerPool(): void {
        $builder = new FakeQueryBuilder(new FakeResult(fetchQueue: [
            ['pool_id' => '3', 'correct_answers' => '7', 'total_questions' => '10'],  // 70
            ['pool_id' => '3', 'correct_answers' => '17', 'total_questions' => '20'], // 85 (best for 3)
            ['pool_id' => '4', 'correct_answers' => '2', 'total_questions' => '3'],   // 66.7 -> 67
            ['pool_id' => '4', 'correct_answers' => '0', 'total_questions' => '0'],   // ignored
        ]));
        $service = $this->makeService(new FakeDbConnection([$builder]));

        $this->assertSame([3 => 85, 4 => 67], $service->getBestExamScoresByPool(self::USER, self::COURSE_ID, [3, 4]));
    }

    public function testBestExamScoresByPoolWithoutPoolsRunsNoQuery(): void {
        $db = new FakeDbConnection([]);
        $service = $this->makeService($db);

        $this->assertSame([], $service->getBestExamScoresByPool(self::USER, self::COURSE_ID, []));
        $this->assertSame([], $service->getPoolTitles([]));
    }

    public function testGetPoolTitlesMapsIdToName(): void {
        $builder = new FakeQueryBuilder(new FakeResult(fetchQueue: [
            ['id' => '3', 'name' => 'Brandschutz'],
        ]));
        $service = $this->makeService(new FakeDbConnection([$builder]));

        $this->assertSame([3 => 'Brandschutz'], $service->getPoolTitles([3, 9]));
        $this->assertSame('learning_pools', $builder->from['table'] ?? null);
        $idFilter = null;
        foreach (array_merge($builder->whereCalls, $builder->andWhereCalls) as $c) {
            if (($c['left'] ?? null) === 'id') {
                $idFilter = $c;
            }
        }
        $this->assertNotNull($idFilter, 'only the requested pools are read');
        $this->assertSame('in', $idFilter['type']);
        $this->assertSame([3, 9], $idFilter['right']['value'] ?? null);
    }
}
