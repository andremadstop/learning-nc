<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMember;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Service\CertificationTrigger;
use OCA\Learning\Service\ComplianceAuditException;
use OCA\Learning\Service\ExamAttemptPolicy;
use OCA\Learning\Service\PassCriteriaService;
use OCA\Learning\Service\PassResult;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #35: the certificate is issued right after a counting exam completes — before, only
 * opening the completion tab issued it. The trigger acts on counting exams of certifying courses
 * only and never lets a certification problem reach the exam result.
 */
#[AllowMockObjectsWithoutExpectations]
class CertificationTriggerTest extends TestCase {

    private const USER = 'alice';
    private const COURSE_ID = 7;
    private const SESSION_ID = 42;

    private function sessionRow(array $overrides = []): array {
        return array_merge([
            'id' => (string)self::SESSION_ID,
            'pool_id' => '3',
            'course_id' => (string)self::COURSE_ID,
            'mode' => 'exam',
            'exam_kind' => null,
            'completed_at' => '1700000000',
            'total_questions' => '20',
            'correct_answers' => '17',
        ], $overrides);
    }

    private function makeCourse(bool $certEnabled = true, string $instructor = 'teacher'): Course {
        $course = new Course();
        $course->setId(self::COURSE_ID);
        $course->setInstructorId($instructor);
        $course->setCertEnabled($certEnabled);
        return $course;
    }

    private function makeTrigger(
        array|false $row,
        PassCriteriaService $pass,
        ?Course $course = null,
        bool $member = true,
        ?LoggerInterface $logger = null,
        ?FakeQueryBuilder &$builder = null,
    ): CertificationTrigger {
        $builder = new FakeQueryBuilder(new FakeResult(fetchQueue: $row === false ? [] : [$row]));
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnArgument(2);
        $courseMapper = $this->createMock(CourseMapper::class);
        $courseMapper->method('findById')->willReturn($course ?? $this->makeCourse());
        $members = $this->createMock(CourseMemberMapper::class);
        if ($member) {
            $members->method('findByCourseAndUser')->willReturn(new CourseMember());
        } else {
            $members->method('findByCourseAndUser')->willThrowException(new DoesNotExistException('none'));
        }
        return new CertificationTrigger(
            new FakeDbConnection([$builder]),
            $courseMapper,
            $members,
            $pass,
            new ExamAttemptPolicy($config),
            $logger ?? $this->createMock(LoggerInterface::class),
        );
    }

    public function testCountingExamEvaluatesOnceAndReturnsTheCertification(): void {
        $pass = $this->createMock(PassCriteriaService::class);
        $pass->expects($this->once())->method('evaluate')->with(self::USER, self::COURSE_ID)
            ->willReturn(new PassResult(true, 85, 80, true, 1700000000, true, PassResult::REQUIREMENT_ANY_EXAM, [],
                ['verification_id' => 'vid-1', 'issued_at' => 1700000000, 'expires_at' => null, 'expired' => false, 'revoked' => false]));

        $builder = null;
        $block = $this->makeTrigger($this->sessionRow(), $pass, builder: $builder)
            ->afterSessionCompleted(self::SESSION_ID, self::USER);

        $this->assertNotNull($block);
        $this->assertTrue($block['certificate_issued']);
        $this->assertSame('vid-1', $block['certificate']['verification_id']);
        $this->assertSame(self::COURSE_ID, $block['course_id']);
        $this->assertSame(3, $block['pool_id']);
        $this->assertSame(85, $block['exam_score']);
        $this->assertTrue($block['exam_passed']);
        $this->assertTrue($block['counts_for_requirement'], 'without required pools every counting exam counts');
        $this->assertSame(['limit_per_day' => 5, 'cooldown_minutes' => 10, 'scope' => 'pool'], $block['attempt_policy']);
        // The session is read for this user only — another user's session id must not count.
        $this->assertContains(['type' => 'eq', 'left' => 'user_id', 'right' => ['value' => self::USER, 'type' => null]], $builder->andWhereCalls);
    }

    public function testFailedAttemptReportsThePassMark(): void {
        $pass = $this->createMock(PassCriteriaService::class);
        $pass->method('evaluate')->willReturn(new PassResult(false, 60, 80, true, null));

        $block = $this->makeTrigger($this->sessionRow(['correct_answers' => '12']), $pass)
            ->afterSessionCompleted(self::SESSION_ID, self::USER);

        $this->assertSame(60, $block['exam_score']);
        $this->assertFalse($block['exam_passed']);
        $this->assertSame(80, $block['threshold']);
        $this->assertFalse($block['certificate_issued']);
    }

    /**
     * Training sessions, practice exams, exams started outside a course and unfinished sessions
     * never trigger an evaluation.
     */
    public function testNonCountingSessionsDoNotEvaluate(): void {
        $cases = [
            'training' => $this->sessionRow(['mode' => 'training']),
            'practice' => $this->sessionRow(['exam_kind' => 'practice']),
            'no course' => $this->sessionRow(['course_id' => null]),
            'not completed' => $this->sessionRow(['completed_at' => null]),
            'foreign or missing session' => false,
        ];
        foreach ($cases as $label => $row) {
            $pass = $this->createMock(PassCriteriaService::class);
            $pass->expects($this->never())->method('evaluate');
            $this->assertNull($this->makeTrigger($row, $pass)->afterSessionCompleted(self::SESSION_ID, self::USER), $label);
        }
    }

    public function testCourseWithoutCertificateDoesNotEvaluate(): void {
        $pass = $this->createMock(PassCriteriaService::class);
        $pass->expects($this->never())->method('evaluate');

        $this->assertNull($this->makeTrigger($this->sessionRow(), $pass, $this->makeCourse(false))
            ->afterSessionCompleted(self::SESSION_ID, self::USER));
    }

    // Same access rule as GET /pass-status: a learner removed from the course gets no certificate.
    public function testNonMemberDoesNotEvaluate(): void {
        $pass = $this->createMock(PassCriteriaService::class);
        $pass->expects($this->never())->method('evaluate');

        $this->assertNull($this->makeTrigger($this->sessionRow(), $pass, member: false)
            ->afterSessionCompleted(self::SESSION_ID, self::USER));
    }

    public function testComplianceAuditFailureIsLoggedAsErrorAndDoesNotThrow(): void {
        $pass = $this->createMock(PassCriteriaService::class);
        $pass->method('evaluate')->willThrowException(new ComplianceAuditException('chain broken'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');
        $logger->expects($this->never())->method('warning');

        $block = $this->makeTrigger($this->sessionRow(), $pass, logger: $logger)
            ->afterSessionCompleted(self::SESSION_ID, self::USER);

        $this->assertSame(['course_id' => self::COURSE_ID, 'error' => 'evaluation_failed', 'threshold' => 80, 'exam_score' => 85, 'exam_passed' => true], $block);
    }

    /**
     * GitHub #35 (review): when the evaluation fails, the attempt is still judged by the course
     * pass mark — the result screen otherwise fell back to the unrelated 720/900 verdict.
     */
    public function testFailedEvaluationStillReportsTheCoursePassMark(): void {
        $pass = $this->createMock(PassCriteriaService::class);
        $pass->method('evaluate')->willThrowException(new \RuntimeException('db gone'));
        $course = $this->makeCourse();
        $course->setCertPassPercent(70);

        $block = $this->makeTrigger($this->sessionRow(['correct_answers' => '15']), $pass, $course)
            ->afterSessionCompleted(self::SESSION_ID, self::USER);

        $this->assertSame('evaluation_failed', $block['error']);
        $this->assertSame(70, $block['threshold']);
        $this->assertSame(75, $block['exam_score']);
        $this->assertTrue($block['exam_passed']);
    }

    /**
     * GitHub #35 (review): an exam on a course pool that the certificate does not require counts
     * for nothing. The block says so, so the result screen does not suggest progress.
     */
    public function testExamOnPoolThatIsNotRequiredDoesNotCountForRequirement(): void {
        $pools = [
            ['pool_id' => 1, 'title' => 'Brandschutz', 'best_score' => null, 'passed' => false],
            ['pool_id' => 2, 'title' => 'Erste Hilfe', 'best_score' => null, 'passed' => false],
        ];
        $pass = $this->createMock(PassCriteriaService::class);
        $pass->method('evaluate')->willReturn(new PassResult(false, null, 80, false, null, true, PassResult::REQUIREMENT_REQUIRED_POOLS, $pools));

        $block = $this->makeTrigger($this->sessionRow(), $pass)->afterSessionCompleted(self::SESSION_ID, self::USER);

        $this->assertTrue($block['exam_passed'], 'the attempt itself reached the pass mark');
        $this->assertFalse($block['counts_for_requirement']);
    }

    public function testExamOnRequiredPoolCountsForRequirement(): void {
        $pools = [['pool_id' => 3, 'title' => 'Brandschutz', 'best_score' => 85, 'passed' => true]];
        $pass = $this->createMock(PassCriteriaService::class);
        $pass->method('evaluate')->willReturn(new PassResult(true, 85, 80, true, 1700000000, true, PassResult::REQUIREMENT_REQUIRED_POOLS, $pools));

        $block = $this->makeTrigger($this->sessionRow(), $pass)->afterSessionCompleted(self::SESSION_ID, self::USER);

        $this->assertTrue($block['counts_for_requirement']);
    }

    public function testOtherFailureIsLoggedAsWarningAndDoesNotThrow(): void {
        $pass = $this->createMock(PassCriteriaService::class);
        $pass->method('evaluate')->willThrowException(new \RuntimeException('db gone'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $block = $this->makeTrigger($this->sessionRow(), $pass, logger: $logger)
            ->afterSessionCompleted(self::SESSION_ID, self::USER);

        $this->assertSame('evaluation_failed', $block['error']);
        $this->assertStringNotContainsString('db gone', json_encode($block));
    }
}
