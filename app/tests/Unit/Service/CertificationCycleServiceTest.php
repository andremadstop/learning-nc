<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Certificate;
use OCA\Learning\Db\CertificateMapper;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMember;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Service\AssignmentService;
use OCA\Learning\Service\CertificationCycleService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\ExamAttemptLimitService;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\NothingToResetException;
use OCA\Learning\Service\PassCriteriaService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #84 / #87: "Open a new certification cycle". Before 5.16.0 a revoked certificate was a
 * permanent ban: nothing created the period issuanceWindow() Branch B waits for, and the
 * instructor had no tool ("Please contact your instructor" led nowhere).
 */
#[AllowMockObjectsWithoutExpectations]
class CertificationCycleServiceTest extends TestCase {
    private const NOW = 2_000_000_000;
    private const COURSE = 7;

    private AssignmentService $assignments;
    private CertificateMapper $certificates;
    private CourseMemberMapper $members;
    private CourseService $courses;
    private ExamAttemptLimitService $limits;
    private INotificationManager $notifications;
    private PassCriteriaService $pass;
    private Course $course;
    /** GitHub #85: set by a test that inspects the activities; a plain mock otherwise. */
    private ?\OCA\Learning\Activity\ActivityPublisher $activity = null;

    protected function setUp(): void {
        $this->course = new Course();
        $this->course->setId(self::COURSE);
        $this->course->setTitle('Safety');
        $this->course->setInstructorId('teacher');
        $this->course->setCertEnabled(true);
        $this->assignments = $this->createMock(AssignmentService::class);
        $this->certificates = $this->createMock(CertificateMapper::class);
        $this->members = $this->createMock(CourseMemberMapper::class);
        $this->courses = $this->createMock(CourseService::class);
        $this->courses->method('assertInstructorOfCourse')->willReturnCallback(function (int $courseId, string $uid): Course {
            if ($courseId !== self::COURSE || $uid !== 'teacher') {
                throw new ForbiddenException('No permission');
            }
            return $this->course;
        });
        $this->limits = $this->createMock(ExamAttemptLimitService::class);
        $this->notifications = $this->createMock(INotificationManager::class);
        $this->pass = $this->createMock(PassCriteriaService::class);
        $this->pass->method('renewalOpensAt')->willReturn(null);
    }

    private function service(): CertificationCycleService {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);
        $time->method('getDateTime')->willReturn(new \DateTime('@' . self::NOW));
        $courseMapper = $this->createMock(CourseMapper::class);
        $courseMapper->method('findById')->willReturn($this->course);
        return new CertificationCycleService($this->courses, $courseMapper, $this->members, $this->certificates, $this->assignments,
            $this->pass, $this->limits, $this->notifications, $time, $this->createMock(LoggerInterface::class), $this->activity ?? $this->createMock(\OCA\Learning\Activity\ActivityPublisher::class));
    }

    private static function cert(bool $revoked, ?int $expiresAt = null, ?int $revokedAt = null): Certificate {
        $cert = new Certificate();
        $cert->setVerificationId('11111111-1111-4111-8111-111111111111');
        $cert->setUserId('anna');
        $cert->setCourseId(self::COURSE);
        $cert->setIssuedAt(self::NOW - 400 * 86400);
        $cert->setRevoked($revoked);
        $cert->setRevokedAt($revokedAt);
        $cert->setExpiresAt($expiresAt);
        $cert->setActiveIdemKey($revoked ? null : 'anna:' . self::COURSE);
        return $cert;
    }

    private static function member(int $id, int $courseId, string $uid, string $role = 'student'): CourseMember {
        $m = new CourseMember();
        $m->setId($id);
        $m->setCourseId($courseId);
        $m->setUserId($uid);
        $m->setRole($role);
        return $m;
    }

    // ---- who is stuck -------------------------------------------------------------------

    public function testARevokedCertificateWithoutANewCycleIsStuck(): void {
        $revoked = self::cert(true, null, self::NOW - 100);
        self::assertSame('revoked', $this->service()->reasonFor($revoked, null, [], self::NOW));
        // the 'passed' period that issued the revoked certificate is no new cycle
        self::assertSame('revoked', $this->service()->reasonFor($revoked, ['status' => 'passed', 'due_date' => null, 'assigned_at' => self::NOW - 500, 'reopen_reason' => null], [], self::NOW));
    }

    public function testAfterTheNewCycleNothingIsStuckUntilTheAttemptsRunOutAgain(): void {
        $revoked = self::cert(true, null, self::NOW - 100);
        $cycle = ['status' => 'assigned', 'due_date' => null, 'assigned_at' => self::NOW - 50, 'reopen_reason' => 'revoked'];
        self::assertNull($this->service()->reasonFor($revoked, $cycle, [], self::NOW));
        self::assertSame('revoked', $this->service()->reasonFor($revoked, $cycle, [3], self::NOW), 'a reset in that cycle stays one after a revoke');
    }

    public function testAnExpiredCertificateWithoutAnOpenPeriodIsStuck(): void {
        self::assertSame('expired', $this->service()->reasonFor(self::cert(false, self::NOW - 10), null, [], self::NOW));
        $open = ['status' => 'assigned', 'due_date' => null, 'assigned_at' => self::NOW - 5, 'reopen_reason' => null];
        self::assertNull($this->service()->reasonFor(self::cert(false, self::NOW - 10), $open, [], self::NOW), 'the recertification job opened a period');
    }

    public function testAValidCertificateHasNothingToReset(): void {
        self::assertNull($this->service()->reasonFor(self::cert(false, self::NOW + 400 * 86400), null, [4], self::NOW));
        self::assertNull($this->service()->reasonFor(self::cert(false, null), null, [], self::NOW));
    }

    public function testUsedUpAttemptsWithoutACertificate(): void {
        self::assertSame('attempts', $this->service()->reasonFor(null, null, [4], self::NOW));
        self::assertNull($this->service()->reasonFor(null, null, [], self::NOW), 'nothing to reset: the learner can still try');
    }

    // ---- opening ------------------------------------------------------------------------

    public function testOpenStartsTheCycleAndTellsTheLearner(): void {
        $revoked = self::cert(true, null, self::NOW - 100);
        $this->members->method('findById')->with(31)->willReturn(self::member(31, self::COURSE, 'anna'));
        $this->certificates->method('findByUserAndCourse')->willReturn($revoked);
        $this->assignments->method('getStatesForCourseAndUsers')->willReturn([]);
        $this->limits->method('exhaustedPools')->willReturn([]);
        $this->pass->method('attemptCycleStart')->willReturn(1234);
        $order = [];
        $this->limits->expects($this->once())->method('clearNotifications')->with('anna', self::COURSE, 1234)
            ->willReturnCallback(function () use (&$order): void { $order[] = 'clear'; });
        $this->assignments->expects($this->once())->method('reopenPeriod')
            ->with(self::COURSE, 'anna', 'revoked', 'teacher', '11111111-1111-4111-8111-111111111111')
            ->willReturnCallback(function () use (&$order): int { $order[] = 'reopen'; return self::NOW; });
        $sent = $this->captureNotifications();

        self::assertSame(['reason' => 'revoked', 'since' => self::NOW], $this->service()->open(self::COURSE, 31, 'teacher'));
        self::assertSame(['reopen', 'clear'], $order, 'the old cycle\'s notifications go only once the new cycle is committed');
        self::assertCount(1, $sent);
        self::assertSame('anna', $sent[0]->getUser());
        self::assertSame('certification_cycle_opened', $sent[0]->getSubject());
    }

    public function testNothingToResetIsRefused(): void {
        $this->members->method('findById')->willReturn(self::member(31, self::COURSE, 'anna'));
        $this->certificates->method('findByUserAndCourse')->willReturn(self::cert(false, null));
        $this->assignments->method('getStatesForCourseAndUsers')->willReturn([]);
        $this->limits->method('exhaustedPools')->willReturn([]);
        $this->assignments->expects($this->never())->method('reopenPeriod');

        $this->expectException(NothingToResetException::class);
        $this->service()->open(self::COURSE, 31, 'teacher');
    }

    /** IDOR: a member row of another course, or a co-instructor, is no student of this course. */
    public function testAMemberOfAnotherCourseIsNotFound(): void {
        $this->members->method('findById')->willReturn(self::member(31, 99, 'anna'));
        $this->assignments->expects($this->never())->method('reopenPeriod');
        $this->expectException(DoesNotExistException::class);
        $this->service()->open(self::COURSE, 31, 'teacher');
    }

    public function testAnInstructorMemberIsNotFound(): void {
        $this->members->method('findById')->willReturn(self::member(32, self::COURSE, 'co', 'instructor'));
        $this->assignments->expects($this->never())->method('reopenPeriod');
        $this->expectException(DoesNotExistException::class);
        $this->service()->open(self::COURSE, 32, 'teacher');
    }

    public function testSomeoneWhoIsNoInstructorOfTheCourseIsRefused(): void {
        $this->members->expects($this->never())->method('findById');
        $this->assignments->expects($this->never())->method('reopenPeriod');
        $this->expectException(ForbiddenException::class);
        $this->service()->open(self::COURSE, 31, 'anna');
    }

    public function testTheRevokeIsNotifiedToTheLearner(): void {
        $sent = $this->captureNotifications();
        $this->service()->notifyRevoked(self::cert(true, null, self::NOW));
        self::assertCount(1, $sent);
        self::assertSame('anna', $sent[0]->getUser());
        self::assertSame('certificate_revoked', $sent[0]->getSubject());
        self::assertSame(['course_title' => 'Safety', 'course_id' => self::COURSE], $sent[0]->getSubjectParameters());
    }

    /**
     * GitHub #85: the revoke becomes an activity once, next to the new bell; a revoke whose bell
     * is already there (a repeated call) publishes nothing more.
     */
    public function testTheRevokeIsAlsoAnActivityOnceOnly(): void {
        $activities = [];
        $this->activity = $this->createMock(\OCA\Learning\Activity\ActivityPublisher::class);
        $this->activity->method('toUser')->willReturnCallback(function (...$args) use (&$activities): void {
            $activities[] = $args;
        });
        $count = 0;
        $this->notifications->method('createNotification')->willReturnCallback(fn () => self::fakeNotification($this));
        $this->notifications->method('getCount')->willReturnCallback(function () use (&$count): int {
            return $count++;
        });

        $this->service()->notifyRevoked(self::cert(true, null, self::NOW));
        $this->service()->notifyRevoked(self::cert(true, null, self::NOW));

        self::assertSame([['certificate_revoked', 'anna', self::COURSE, 'Safety', []]], $activities);
    }

    public function testStatesListStudentsOnly(): void {
        $this->members->method('findByCourse')->willReturn([
            self::member(31, self::COURSE, 'anna'),
            self::member(32, self::COURSE, 'co', 'instructor'),
        ]);
        $this->certificates->method('findNewestTwoByCourseForUsers')->willReturn(['anna' => [self::cert(true, null, self::NOW - 100)]]);
        $this->assignments->method('getStatesForCourseAndUsers')->willReturn([]);
        $this->limits->method('exhaustedPoolsForLearners')->willReturn([]);

        $states = $this->service()->statesForCourse(self::COURSE, 'teacher');
        self::assertSame([31 => ['reason' => 'revoked', 'new_cycle' => null, 'exhausted_pool_ids' => []]], $states);
    }

    /**
     * Review C9: the member list read ~5 rows per learner (certificate, instructor check, cycle
     * start, attempts) on every open of the tab. Now one read per kind for the whole list.
     */
    public function testStatesAreReadOnceForAllLearners(): void {
        $this->members->method('findByCourse')->willReturn([
            self::member(31, self::COURSE, 'anna'),
            self::member(33, self::COURSE, 'ben'),
            self::member(34, self::COURSE, 'cleo'),
        ]);
        $this->certificates->expects($this->never())->method('findByUserAndCourse');
        $this->certificates->expects($this->once())->method('findNewestTwoByCourseForUsers')
            ->with(self::COURSE, ['anna', 'ben', 'cleo'])
            ->willReturn(['anna' => [self::cert(true, null, self::NOW - 100)]]);
        $open = ['status' => 'assigned', 'due_date' => null, 'assigned_at' => self::NOW - 50, 'reopen_reason' => 'attempts'];
        $this->assignments->expects($this->once())->method('getStatesForCourseAndUsers')->willReturn(['cleo' => $open]);
        $this->limits->expects($this->never())->method('exhaustedPools');
        $this->limits->expects($this->once())->method('exhaustedPoolsForLearners')
            ->with($this->course, [
                'anna' => ['certificate' => self::cert(true, null, self::NOW - 100), 'state' => null],
                'ben' => ['certificate' => null, 'state' => null],
                'cleo' => ['certificate' => null, 'state' => $open],
            ])
            ->willReturn(['ben' => [3]]);

        self::assertSame([
            31 => ['reason' => 'revoked', 'new_cycle' => null, 'exhausted_pool_ids' => []],
            33 => ['reason' => 'attempts', 'new_cycle' => null, 'exhausted_pool_ids' => [3]],
            34 => ['reason' => null, 'new_cycle' => ['reason' => 'attempts', 'since' => self::NOW - 50], 'exhausted_pool_ids' => []],
        ], $this->service()->statesForCourse(self::COURSE, 'teacher'));
    }

    /** @return \ArrayObject<int, INotification> */
    private function captureNotifications(): \ArrayObject {
        $sent = new \ArrayObject();
        $this->notifications->method('createNotification')->willReturnCallback(fn () => self::fakeNotification($this));
        $this->notifications->method('getCount')->willReturn(0);
        $this->notifications->method('notify')->willReturnCallback(function (INotification $n) use ($sent): void { $sent[] = $n; });
        return $sent;
    }

    /** A notification mock that keeps user, subject and parameters (shared with the limit tests). */
    public static function fakeNotification(TestCase $test): INotification {
        $n = (new \ReflectionMethod($test, 'createMock'))->invoke($test, INotification::class);
        $state = new \ArrayObject(['user' => '', 'subject' => '', 'params' => [], 'object' => ['', '']]);
        foreach (['setApp', 'setDateTime'] as $setter) {
            $n->method($setter)->willReturnSelf();
        }
        $n->method('setUser')->willReturnCallback(function (string $uid) use ($n, $state) { $state['user'] = $uid; return $n; });
        $n->method('setObject')->willReturnCallback(function (string $type, string $id) use ($n, $state) { $state['object'] = [$type, $id]; return $n; });
        $n->method('setSubject')->willReturnCallback(function (string $s, array $p = []) use ($n, $state) { $state['subject'] = $s; $state['params'] = $p; return $n; });
        $n->method('getUser')->willReturnCallback(fn () => $state['user']);
        $n->method('getSubject')->willReturnCallback(fn () => $state['subject']);
        $n->method('getSubjectParameters')->willReturnCallback(fn () => $state['params']);
        $n->method('getObjectType')->willReturnCallback(fn () => $state['object'][0]);
        $n->method('getObjectId')->willReturnCallback(fn () => $state['object'][1]);
        return $n;
    }
}
