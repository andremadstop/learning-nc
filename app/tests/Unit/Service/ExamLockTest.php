<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\AnswerMapper;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\PoolShareMapper;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Service\ExamLock;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\TrainingService;
use OCA\Learning\Service\TranslationService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Audit #56: outside TrainingService the exam lock only checked completed_at, so a timed exam
 * whose tab was closed locked the pool (no explanations, no is_correct, AI explain 403) until a
 * TrainingService path happened to close it. ExamLock is the one deadline-aware, read-only check.
 */
#[AllowMockObjectsWithoutExpectations]
class ExamLockTest extends TestCase {
    private const NOW = 1_800_000_000;

    private function lockFor(array ...$openExams): array {
        $query = new FakeQueryBuilder(new FakeResult($openExams));
        $db = new FakeDbConnection([$query]);
        return [ExamLock::isActiveOnPool($db, 42, 'alice', self::NOW), $query, $db];
    }

    public function testNoOpenExamDoesNotLock(): void {
        [$locked] = $this->lockFor();
        $this->assertFalse($locked);
    }

    public function testARunningTimedExamLocks(): void {
        [$locked] = $this->lockFor(['id' => 1, 'started_at' => self::NOW - 600, 'time_limit_seconds' => 1800]);
        $this->assertTrue($locked);
    }

    public function testAnExpiredTimedExamNoLongerLocks(): void {
        [$locked] = $this->lockFor(['id' => 1, 'started_at' => self::NOW - 7200, 'time_limit_seconds' => 1800]);
        $this->assertFalse($locked);
    }

    public function testTheGracePeriodForTheFinalBatchStillLocks(): void {
        $startedAt = self::NOW - 1800 - TrainingService::EXAM_SUBMIT_GRACE_SECONDS;
        [$locked] = $this->lockFor(['id' => 1, 'started_at' => $startedAt, 'time_limit_seconds' => 1800]);
        $this->assertTrue($locked, 'the last second of the grace period still locks');
    }

    public function testAnUntimedExamLocksUntilCompleted(): void {
        [$locked] = $this->lockFor(['id' => 1, 'started_at' => self::NOW - 86400 * 30, 'time_limit_seconds' => 0]);
        $this->assertTrue($locked);
    }

    public function testOneRunningExamAmongExpiredOnesLocks(): void {
        [$locked] = $this->lockFor(
            ['id' => 1, 'started_at' => self::NOW - 7200, 'time_limit_seconds' => 1800],
            ['id' => 2, 'started_at' => self::NOW - 60, 'time_limit_seconds' => 1800],
        );
        $this->assertTrue($locked);
    }

    public function testTheCheckIsReadOnlyAndSkipsPracticeAttempts(): void {
        [, $query, $db] = $this->lockFor(['id' => 1, 'started_at' => self::NOW - 7200, 'time_limit_seconds' => 1800]);
        $this->assertCount(1, $db->issuedBuilders, 'no UPDATE closes the session from a read path');
        $this->assertNotSame('update', $query->operation);
        $this->assertContains(['type' => 'isNull', 'field' => 'exam_kind'], $query->andWhereCalls);
        $this->assertContains(['type' => 'isNull', 'field' => 'completed_at'], $query->andWhereCalls);
    }

    /** The QuestionService read paths use it: an expired exam no longer withholds anything. */
    public function testQuestionServiceIgnoresAnExpiredExam(): void {
        $db = new FakeDbConnection([new FakeQueryBuilder(new FakeResult([
            ['id' => 1, 'started_at' => time() - 7200, 'time_limit_seconds' => 1800],
        ]))]);
        $service = new QuestionService(
            $this->createMock(QuestionMapper::class),
            $this->createMock(AnswerMapper::class),
            $this->createMock(PoolShareMapper::class),
            $this->createMock(PoolMapper::class),
            $db,
            $this->createMock(TranslationService::class),
            $this->createMock(LoggerInterface::class)
        );

        $this->assertFalse($service->isExamActiveOnPool(42, 'alice'));
    }

    public function testQuestionServiceStillLocksDuringARunningExam(): void {
        $db = new FakeDbConnection([new FakeQueryBuilder(new FakeResult([
            ['id' => 1, 'started_at' => time() - 60, 'time_limit_seconds' => 1800],
        ]))]);
        $service = new QuestionService(
            $this->createMock(QuestionMapper::class),
            $this->createMock(AnswerMapper::class),
            $this->createMock(PoolShareMapper::class),
            $this->createMock(PoolMapper::class),
            $db,
            $this->createMock(TranslationService::class),
            $this->createMock(LoggerInterface::class)
        );

        $this->assertTrue($service->isExamActiveOnPool(42, 'alice'));
    }
}
