<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMember;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Service\CourseSummaryService;
use OCA\Learning\Service\ExamAttemptLimitException;
use OCA\Learning\Service\ExamAttemptLimitService;
use OCA\Learning\Service\PassCriteriaService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #87: before 5.16.0 the only limit was "attempts per 24 hours"; a learner could retake a
 * certifying exam forever until it passed. Now a course can cap the counting attempts per pool in
 * the current certification cycle, the server refuses the next start, and the instructors hear of it.
 */
#[AllowMockObjectsWithoutExpectations]
class ExamAttemptLimitServiceTest extends TestCase {
    private const COURSE = 7;
    private const POOL = 3;

    private Course $course;
    private CourseMemberMapper $members;
    private PassCriteriaService $pass;
    private INotificationManager $notifications;
    /** @var \ArrayObject<int, INotification> */
    private \ArrayObject $sent;

    protected function setUp(): void {
        $this->course = new Course();
        $this->course->setId(self::COURSE);
        $this->course->setTitle('Safety');
        $this->course->setInstructorId('teacher');
        $this->course->setCertEnabled(true);
        $this->course->setExamAttemptLimit(3);
        $this->members = $this->createMock(CourseMemberMapper::class);
        $this->members->method('findByCourseAndUser')->willReturnCallback(function (int $c, string $uid): CourseMember {
            if ($uid !== 'co') {
                throw new DoesNotExistException('no');
            }
            return self::member('co', 'instructor');
        });
        $this->members->method('findByCourse')->willReturn([self::member('anna', 'student'), self::member('co', 'instructor')]);
        $this->pass = $this->createMock(PassCriteriaService::class);
        $this->pass->method('attemptCycleStart')->willReturn(1500);
        $this->sent = new \ArrayObject();
        $this->notifications = $this->createMock(INotificationManager::class);
        $this->notifications->method('createNotification')->willReturnCallback(fn () => CertificationCycleServiceTest::fakeNotification($this));
        $this->notifications->method('notify')->willReturnCallback(function (INotification $n): void { $this->sent[] = $n; });
    }

    private static function member(string $uid, string $role): CourseMember {
        $m = new CourseMember();
        $m->setUserId($uid);
        $m->setRole($role);
        return $m;
    }

    /** @param list<FakeQueryBuilder> $builders */
    private function service(array $builders, ?FakeDbConnection &$db = null): ExamAttemptLimitService {
        $db = new FakeDbConnection($builders);
        $courses = $this->createMock(CourseMapper::class);
        $courses->method('findById')->willReturn($this->course);
        $summary = $this->createMock(CourseSummaryService::class);
        $summary->method('getPoolTitles')->willReturn([self::POOL => 'Fire safety']);
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getDateTime')->willReturn(new \DateTime('@1700000000'));
        return new ExamAttemptLimitService($db, $courses, $this->members, $this->pass, $summary, $this->notifications, $time, $this->createMock(LoggerInterface::class));
    }

    private static function used(int $n): FakeQueryBuilder {
        return new FakeQueryBuilder(new FakeResult(fetchQueue: [['pool_id' => (string)self::POOL, 'n' => (string)$n]]));
    }

    public function testTheStartBelowTheLimitPasses(): void {
        $this->service([self::used(2)])->assertCanStart('anna', self::COURSE, self::POOL);
        self::assertCount(0, $this->sent);
    }

    public function testTheStartAtTheLimitIsRefusedAndTheInstructorsHearOfIt(): void {
        $this->pass->method('examStandings')->willReturn(['users' => ['anna' => ['passed' => false, 'pools' => [self::POOL => ['best_score' => 40, 'passed' => false]]]]]);
        $count = self::used(3);
        try {
            $this->service([$count])->assertCanStart('anna', self::COURSE, self::POOL);
            self::fail('the fourth attempt must be refused');
        } catch (ExamAttemptLimitException $e) {
            self::assertSame('exam_attempts_exhausted', $e->getMessage());
        }
        // counted from the start of the cycle, counting exams of this course only
        self::assertContains(1500, array_column($count->namedParameters, 'value'));
        self::assertContains(self::COURSE, array_column($count->namedParameters, 'value'));
        self::assertStringContainsString('exam_kind', (string)json_encode($count->andWhereCalls));
        // owner and co-instructor, never the learner; the uid is not in the object id
        self::assertSame(['teacher', 'co'], array_map(static fn(INotification $n): string => $n->getUser(), $this->sent->getArrayCopy()));
        self::assertSame('exam_attempts_exhausted', $this->sent[0]->getSubject());
        self::assertSame('Fire safety', $this->sent[0]->getSubjectParameters()['pool_title']);
        self::assertStringNotContainsString('anna', $this->sent[0]->getObjectId());
    }

    public function testNoNotificationWhenTheRequirementIsMet(): void {
        $this->pass->method('examStandings')->willReturn(['users' => ['anna' => ['passed' => true, 'pools' => []]]]);
        $this->expectException(ExamAttemptLimitException::class);
        try {
            $this->service([self::used(5)])->assertCanStart('anna', self::COURSE, self::POOL);
        } finally {
            self::assertCount(0, $this->sent);
        }
    }

    public function testInstructorsOfTheCourseAreExempt(): void {
        $this->service([self::used(9)])->assertCanStart('co', self::COURSE, self::POOL);
        $this->service([self::used(9)])->assertCanStart('teacher', self::COURSE, self::POOL);
        $this->addToAssertionCount(1);
    }

    public function testNoLimitWithoutCertificationOrWhenEmpty(): void {
        $this->course->setCertEnabled(false);
        $this->service([self::used(9)])->assertCanStart('anna', self::COURSE, self::POOL);
        $this->course->setCertEnabled(true);
        $this->course->setExamAttemptLimit(null);
        $this->service([self::used(9)])->assertCanStart('anna', self::COURSE, self::POOL);
        self::assertNull(ExamAttemptLimitService::limitFor($this->course));
    }

    public function testTheLastAttemptNotifiesOnceAfterCompletion(): void {
        $this->pass->method('examStandings')->willReturn(['users' => ['anna' => ['passed' => false, 'pools' => []]]]);
        $this->notifications->method('getCount')->willReturnOnConsecutiveCalls(0, 0, 1, 1);
        $this->service([self::used(3)])->afterExamCompleted('anna', self::COURSE, self::POOL);
        $this->service([self::used(3)])->afterExamCompleted('anna', self::COURSE, self::POOL);
        self::assertCount(2, $this->sent, 'one per instructor, the second completion adds none');
    }

    public function testThePolicyShowsTheLimitAndTheUsedAttempts(): void {
        self::assertSame(['total_limit' => 3, 'used_by_pool' => [self::POOL => 2]], $this->service([self::used(2)])->policyFor('anna', $this->course));
        $this->course->setExamAttemptLimit(null);
        self::assertSame(['total_limit' => null, 'used_by_pool' => []], $this->service([])->policyFor('anna', $this->course));
    }
}
