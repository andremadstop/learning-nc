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
    /** @var \ArrayObject<string, string> learner preferences (the "already notified" markers) */
    private \ArrayObject $prefs;

    protected function setUp(): void {
        $this->course = new Course();
        $this->course->setId(self::COURSE);
        $this->course->setTitle('Safety');
        $this->course->setInstructorId('teacher');
        $this->course->setCertEnabled(true);
        $this->course->setExamAttemptLimit(3);
        $this->members = $this->createMock(CourseMemberMapper::class);
        $this->members->method('findByCourseAndUser')->willReturnCallback(function (int $c, string $uid): CourseMember {
            if ($uid === 'anna') {
                return self::member('anna', 'student', 31);
            }
            if ($uid !== 'co') {
                throw new DoesNotExistException('no');
            }
            return self::member('co', 'instructor');
        });
        $this->members->method('findByCourse')->willReturn([self::member('anna', 'student'), self::member('co', 'instructor')]);
        $this->pass = $this->createMock(PassCriteriaService::class);
        $this->pass->method('attemptCycleStart')->willReturn(1500);
        $this->sent = new \ArrayObject();
        $this->prefs = new \ArrayObject();
        $this->notifications = $this->createMock(INotificationManager::class);
        $this->notifications->method('createNotification')->willReturnCallback(fn () => CertificationCycleServiceTest::fakeNotification($this));
        $this->notifications->method('notify')->willReturnCallback(function (INotification $n): void { $this->sent[] = $n; });
    }

    private static function member(string $uid, string $role, int $id = 0): CourseMember {
        $m = new CourseMember();
        $m->setId($id);
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
        $summary->method('getCoursePoolIds')->willReturn([self::POOL]);
        $time->method('getTime')->willReturn(1700000000);
        $config = $this->createMock(\OCP\IConfig::class);
        $prefs = $this->prefs;
        $config->method('getUserValue')->willReturnCallback(fn (string $u, string $a, string $k, $d = '') => $prefs[$u . '/' . $k] ?? $d);
        $config->method('setUserValue')->willReturnCallback(function (string $u, string $a, string $k, $v) use ($prefs): void { $prefs[$u . '/' . $k] = (string)$v; });
        $config->method('deleteUserValue')->willReturnCallback(function (string $u, string $a, string $k) use ($prefs): void { unset($prefs[$u . '/' . $k]); });
        return new ExamAttemptLimitService($db, $courses, $this->members, $this->pass, $summary, $this->notifications, $time, $this->createMock(LoggerInterface::class), $config);
    }

    private static function usedOn(int $poolId, int $n): FakeQueryBuilder {
        return new FakeQueryBuilder(new FakeResult(fetchQueue: [['pool_id' => (string)$poolId, 'n' => (string)$n]]));
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
        // Review C9: nor in the parameters — the member row instead, which goes with the account
        self::assertSame(31, $this->sent[0]->getSubjectParameters()['member_id']);
        self::assertStringNotContainsString('anna', (string)json_encode($this->sent[0]->getSubjectParameters()));
    }

    /**
     * Review C9: a pool passed on its last attempt, or one the certificate does not require, blocks
     * nothing. Listed as used up, the member list offered "open a new cycle" for it — which would
     * have thrown the pass away.
     */
    public function testAPassedOrNotRequiredPoolIsNotExhausted(): void {
        $this->pass->method('examStandings')->willReturn([
            'required_pool_ids' => [self::POOL, 4],
            'users' => ['anna' => ['passed' => false, 'pools' => [self::POOL => ['best_score' => 90, 'passed' => true], 4 => ['best_score' => null, 'passed' => false]]]],
        ]);
        $three = static fn (array $pools): FakeQueryBuilder => new FakeQueryBuilder(new FakeResult(fetchQueue: array_map(
            static fn (int $p): array => ['pool_id' => (string)$p, 'n' => '3'], $pools)));
        self::assertSame([], $this->service([$three([self::POOL])])->exhaustedPools('anna', $this->course), 'passed on the last attempt');
        self::assertSame([], $this->service([$three([9])])->exhaustedPools('anna', $this->course), 'not a required pool');
        self::assertSame([4], $this->service([$three([self::POOL, 4, 9])])->exhaustedPools('anna', $this->course));

        // and the instructors are not told about a pool that counts for nothing
        try {
            $this->service([self::usedOn(9, 3)])->assertCanStart('anna', self::COURSE, 9);
        } catch (ExamAttemptLimitException $e) {
        }
        self::assertCount(0, $this->sent);
    }

    /**
     * Review C9: the member list reads the attempts of all learners in one query, each counted
     * from their own cycle start, and the pass rule only for those at the limit.
     */
    public function testTheMemberListCountsAllLearnersInOneQuery(): void {
        $this->pass->method('attemptCycleStartOf')->willReturnCallback(
            static fn ($cert, ?array $state): ?int => $state['assigned_at'] ?? null
        );
        $standingsFor = [];
        $this->pass->method('examStandings')->willReturnCallback(function (int $c, array $uids) use (&$standingsFor): array {
            $standingsFor[] = $uids;
            return ['required_pool_ids' => [], 'users' => array_fill_keys($uids, ['passed' => false, 'pools' => []])];
        });
        $rows = new FakeQueryBuilder(new FakeResult(fetchQueue: [
            // anna: cycle from 1000 — two of her three attempts are older
            ['user_id' => 'anna', 'pool_id' => '3', 'started_at' => '500'],
            ['user_id' => 'anna', 'pool_id' => '3', 'started_at' => '900'],
            ['user_id' => 'anna', 'pool_id' => '3', 'started_at' => '1100'],
            // ben: no cycle, all count
            ['user_id' => 'ben', 'pool_id' => '3', 'started_at' => '100'],
            ['user_id' => 'ben', 'pool_id' => '3', 'started_at' => '200'],
            ['user_id' => 'ben', 'pool_id' => '3', 'started_at' => '300'],
        ]));
        $period = ['status' => 'assigned', 'due_date' => null, 'assigned_at' => 1000, 'reopen_reason' => 'attempts'];
        $out = $this->service([$rows])->exhaustedPoolsForLearners($this->course, [
            'anna' => ['certificate' => null, 'state' => $period],
            'ben' => ['certificate' => null, 'state' => null],
            'cleo' => ['certificate' => null, 'state' => null],
        ]);
        self::assertSame(['ben' => [self::POOL]], $out);
        self::assertSame([['ben']], $standingsFor, 'the pass rule only for learners at the limit');
        self::assertStringContainsString('exam_kind', (string)json_encode($rows->andWhereCalls));
    }

    /**
     * GitHub #87 (review): a learner without a cycle (self-enrolled, enrolled by hand) whose failed
     * attempts from before already reached a newly set limit was locked out at once — there never
     * was a cycle in which those attempts were available. The count starts when the limit was
     * switched on, or with the cycle when that is later.
     */
    public function testTheCountStartsWhenTheLimitWasSwitchedOnIfThatIsLater(): void {
        $this->course->setExamAttemptLimitSince(2000);
        $count = self::used(1);
        $this->service([$count])->assertCanStart('anna', self::COURSE, self::POOL);
        self::assertContains(2000, array_column($count->namedParameters, 'value'), 'counted from the switch-on (2000), not the cycle (1500)');
        self::assertNotContains(1500, array_column($count->namedParameters, 'value'));

        self::assertSame(2000, ExamAttemptLimitService::countStart($this->course, null), 'no cycle: from the switch-on');
        self::assertSame(2500, ExamAttemptLimitService::countStart($this->course, 2500), 'a later cycle wins');
        $this->course->setExamAttemptLimitSince(null);
        self::assertNull(ExamAttemptLimitService::countStart($this->course, null), 'a limit from before the column: every attempt counts');
    }

    public function testTheMemberListCountsFromTheSwitchOnForLearnersWithoutACycle(): void {
        $this->course->setExamAttemptLimitSince(1000);
        $this->pass->method('attemptCycleStartOf')->willReturn(null);
        $this->pass->method('examStandings')->willReturnCallback(fn (int $c, array $uids): array => ['required_pool_ids' => [], 'users' => array_fill_keys($uids, ['passed' => false, 'pools' => []])]);
        $rows = new FakeQueryBuilder(new FakeResult(fetchQueue: [
            // ben failed four times before the limit of 3 was set at 1000, once after
            ['user_id' => 'ben', 'pool_id' => '3', 'started_at' => '100'],
            ['user_id' => 'ben', 'pool_id' => '3', 'started_at' => '200'],
            ['user_id' => 'ben', 'pool_id' => '3', 'started_at' => '300'],
            ['user_id' => 'ben', 'pool_id' => '3', 'started_at' => '400'],
            ['user_id' => 'ben', 'pool_id' => '3', 'started_at' => '1100'],
        ]));
        self::assertSame([], $this->service([$rows])->exhaustedPoolsForLearners($this->course, ['ben' => ['certificate' => null, 'state' => null]]));
    }

    /** Only the step from unlimited to a number sets the moment; another number keeps it, off clears it. */
    public function testApplyLimitRecordsWhenTheLimitWasSwitchedOn(): void {
        $course = new Course();
        ExamAttemptLimitService::applyLimit($course, 3, 1000);
        self::assertSame([3, 1000], [$course->getExamAttemptLimit(), $course->getExamAttemptLimitSince()]);
        ExamAttemptLimitService::applyLimit($course, 5, 2000);
        self::assertSame([5, 1000], [$course->getExamAttemptLimit(), $course->getExamAttemptLimitSince()], 'raising it keeps the start');
        ExamAttemptLimitService::applyLimit($course, null, 3000);
        self::assertSame([null, null], [$course->getExamAttemptLimit(), $course->getExamAttemptLimitSince()]);
        ExamAttemptLimitService::applyLimit($course, 2, 4000);
        self::assertSame([2, 4000], [$course->getExamAttemptLimit(), $course->getExamAttemptLimitSince()], 'on again: counted from now');
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

    /**
     * Once per learner, pool and cycle — also after the instructors dismissed it (getCount is 0
     * again then), so a learner retrying the refused start does not flood them. A new cycle
     * clears the marker, and the next used-up cycle is reported again.
     */
    public function testTheLastAttemptNotifiesOncePerCycle(): void {
        $this->pass->method('examStandings')->willReturn(['users' => ['anna' => ['passed' => false, 'pools' => []]]]);
        $this->notifications->method('getCount')->willReturn(0);
        $this->service([self::used(3)])->afterExamCompleted('anna', self::COURSE, self::POOL);
        try {
            $this->service([self::used(3)])->assertCanStart('anna', self::COURSE, self::POOL);
        } catch (ExamAttemptLimitException $e) {
        }
        self::assertCount(2, $this->sent, 'one per instructor, the refused start adds none');

        $this->service([])->clearNotifications('anna', self::COURSE, 1500);
        self::assertCount(0, $this->prefs, 'the new cycle removes the marker');
    }

    public function testThePolicyShowsTheLimitAndTheUsedAttempts(): void {
        self::assertSame(['total_limit' => 3, 'used_by_pool' => [self::POOL => 2]], $this->service([self::used(2)])->policyFor('anna', $this->course));
        $this->course->setExamAttemptLimit(null);
        self::assertSame(['total_limit' => null, 'used_by_pool' => []], $this->service([])->policyFor('anna', $this->course));
    }
}
