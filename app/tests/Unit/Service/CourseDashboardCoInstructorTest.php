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
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Fresh-eyes review (GitHub #36): the only Nextcloud admin of a care association was a
 * co-instructor of the mandatory training (course_members, role instructor). The course list
 * showed the course, the instructor dashboard said "0 courses, 0 students, 0 pools": it read
 * only the owner column courses.instructor_id.
 */
#[AllowMockObjectsWithoutExpectations]
class CourseDashboardCoInstructorTest extends TestCase {
    private CourseMapper $courseMapper;
    private CourseMemberMapper $courseMemberMapper;
    private CoursePoolMapper $coursePoolMapper;
    private CourseService $service;

    /** @var array<int, Course> */
    private array $courses = [];
    /** @var array<int, list<CourseMember>> */
    private array $members = [];

    protected function setUp(): void {
        $this->courseMapper = $this->createMock(CourseMapper::class);
        $this->coursePoolMapper = $this->createMock(CoursePoolMapper::class);
        $this->courseMemberMapper = $this->createMock(CourseMemberMapper::class);
        $roleService = $this->createMock(RoleService::class);
        $roleService->method('isInstructor')->willReturn(true);

        $this->courseMapper->method('findById')->willReturnCallback(fn (int $id): Course => $this->courses[$id]);
        $this->courseMapper->method('findByInstructor')->willReturnCallback(
            fn (string $uid): array => array_values(array_filter($this->courses, fn (Course $c): bool => $c->getInstructorId() === $uid))
        );
        $this->courseMemberMapper->method('findByCourse')->willReturnCallback(fn (int $id): array => $this->members[$id] ?? []);
        $this->courseMemberMapper->method('findByUser')->willReturnCallback(function (string $uid): array {
            $out = [];
            foreach ($this->members as $list) {
                foreach ($list as $m) {
                    if ($m->getUserId() === $uid) {
                        $out[] = $m;
                    }
                }
            }
            return $out;
        });
        $this->courseMemberMapper->method('countStudentsByCourse')->willReturnCallback(
            fn (int $id): int => count(array_filter($this->members[$id] ?? [], fn (CourseMember $m): bool => $m->getRole() === 'student'))
        );
        $this->coursePoolMapper->method('findByCourse')->willReturnCallback(
            fn (int $id): array => $id === 7 ? [new CoursePool(), new CoursePool()] : []
        );

        $this->service = new CourseService(
            $this->courseMapper,
            $this->coursePoolMapper,
            $this->courseMemberMapper,
            $roleService,
            $this->createMock(IDBConnection::class),
            $this->createMock(IGroupManager::class),
            $this->createMock(IUserManager::class),
            $this->createMock(XpService::class),
            $this->createMock(BadgeService::class),
            $this->createMock(StreakService::class),
            $this->createMock(CurriculumScopeMapper::class),
            $this->createMock(FeedService::class)
        );
    }

    private function course(int $id, string $owner, int $createdAt): void {
        $c = new Course();
        $c->setId($id);
        $c->setInstructorId($owner);
        $c->setTitle('Course ' . $id);
        $c->setStatus('active');
        $c->setCreatedAt($createdAt);
        $this->courses[$id] = $c;
    }

    private function member(int $courseId, string $uid, string $role): void {
        $m = new CourseMember();
        $m->setCourseId($courseId);
        $m->setUserId($uid);
        $m->setRole($role);
        $this->members[$courseId][] = $m;
    }

    public function testCoInstructorSeesTheCourseItsPoolsAndStudents(): void {
        $this->course(7, 'trainer', 100);
        $this->member(7, 'admin', 'instructor');
        $this->member(7, 'learner1', 'student');
        $this->member(7, 'learner2', 'student');

        $result = $this->service->getDashboard('admin');

        $this->assertSame([7], array_column($result['courses'], 'id'));
        $this->assertSame(2, $result['courses'][0]['pool_count']);
        $this->assertSame(2, $result['courses'][0]['student_count']);
        $this->assertSame(2, $result['unique_student_count']);
    }

    public function testOwnAndCoInstructedCoursesAreMergedNewestFirstWithoutDuplicates(): void {
        $this->course(1, 'admin', 100);
        $this->course(2, 'trainer', 300);
        $this->course(3, 'admin', 200);
        // the owner may also carry an instructor membership row: counted once
        $this->member(1, 'admin', 'instructor');
        $this->member(2, 'admin', 'instructor');
        $this->member(1, 'learner1', 'student');
        $this->member(2, 'learner1', 'student');
        $this->member(2, 'learner2', 'student');

        $result = $this->service->getDashboard('admin');

        $this->assertSame([2, 3, 1], array_column($result['courses'], 'id'));
        // learner1 is in two of the courses: one person
        $this->assertSame(2, $result['unique_student_count']);
    }

    public function testStudentMembershipDoesNotPutACourseOnTheDashboard(): void {
        $this->course(4, 'trainer', 100);
        $this->member(4, 'admin', 'student');
        $this->member(4, 'learner1', 'student');

        $result = $this->service->getDashboard('admin');

        $this->assertSame([], $result['courses']);
        $this->assertSame(0, $result['unique_student_count']);
    }
}
