<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMember;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CoursePool;
use OCA\Learning\Db\CoursePoolMapper;
use OCA\Learning\Db\CurriculumScopeMapper;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\FeedService;
use OCA\Learning\Service\RoleService;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\XpService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Audit #154: the at-risk reasons and the "new pool" feed title were German sentences, rendered
 * as they were for an English or Ukrainian instructor. The service now also hands out what the
 * frontend needs to say it in the reader's language: reason codes with their number, and the
 * pool name in the feed item's meta.
 */
#[AllowMockObjectsWithoutExpectations]
class CourseServiceLocalizableTextTest extends TestCase {
    private function service(FakeDbConnection $db, CourseMapper $courses, CourseMemberMapper $members, CoursePoolMapper $pools, FeedService $feed): CourseService {
        return new CourseService(
            $courses,
            $pools,
            $members,
            $this->createMock(RoleService::class),
            $db,
            $this->createMock(IGroupManager::class),
            $this->createMock(IUserManager::class),
            $this->createMock(XpService::class),
            $this->createMock(BadgeService::class),
            $this->createMock(StreakService::class),
            $this->createMock(CurriculumScopeMapper::class),
            $feed
        );
    }

    private function course(): CourseMapper {
        $course = new Course();
        $course->setId(7);
        $course->setInstructorId('teacher');
        $courses = $this->createMock(CourseMapper::class);
        $courses->method('findById')->willReturn($course);
        return $courses;
    }

    public function testAtRiskReasonsCarryTranslatableCodes(): void {
        $student = new CourseMember();
        $student->setCourseId(7);
        $student->setUserId('learner');
        $student->setRole('student');
        $members = $this->createMock(CourseMemberMapper::class);
        $members->method('findByCourse')->willReturn([$student]);
        $pools = $this->createMock(CoursePoolMapper::class);
        $pools->method('findByCourse')->willReturn([]);

        $lastActive = (new \DateTime('today', new \DateTimeZone('UTC')))->modify('-12 days')->format('Y-m-d');
        $userStats = new FakeQueryBuilder(new FakeResult([[
            'user_id' => 'learner', 'total_xp' => 0, 'current_level' => 1, 'current_streak' => 4,
            'total_sessions' => 2, 'total_mastered' => 0, 'last_activity_date' => $lastActive,
        ]]));
        $db = new FakeDbConnection([$userStats]);

        $result = $this->service($db, $this->course(), $members, $pools, $this->createMock(FeedService::class))
            ->getAtRiskStudents(7, 'teacher');

        $this->assertCount(1, $result['at_risk']);
        $entry = $result['at_risk'][0];
        $this->assertSame([
            ['code' => 'inactive_days', 'n' => 12],
            ['code' => 'few_sessions', 'n' => 0],
        ], $entry['risk_reason_codes']);
        // the German sentences stay for the CSV export and older clients
        $this->assertSame(['Inaktiv seit 12 Tagen', 'Nur 0 Sessions in 14 Tagen'], $entry['risk_reasons']);
    }

    public function testNewPoolFeedItemCarriesThePoolName(): void {
        $access = new FakeQueryBuilder(FakeResult::fromFetchOne(1));
        $name = new FakeQueryBuilder(FakeResult::fromFetchOne('Subnetting'));
        $db = new FakeDbConnection([$access, $name]);
        $pools = $this->createMock(CoursePoolMapper::class);
        $pools->method('insert')->willReturnArgument(0);
        $feed = $this->createMock(FeedService::class);
        $feed->expects($this->once())->method('createAutoItem')->with(
            7,
            'pool_added',
            'Neuer Fragenpool: Subnetting',
            null,
            'teacher',
            ['pool_id' => 42, 'pool_name' => 'Subnetting']
        );

        $inserted = $this->service($db, $this->course(), $this->createMock(CourseMemberMapper::class), $pools, $feed)
            ->addPool(7, 42, 1, false, 'teacher');

        $this->assertInstanceOf(CoursePool::class, $inserted);
    }
}
