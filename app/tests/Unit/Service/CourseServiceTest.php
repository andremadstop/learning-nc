<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMember;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CoursePoolMapper;
use OCA\Learning\Db\CurriculumScopeMapper;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\FeedService;
use OCA\Learning\Service\RoleService;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\XpService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class CourseServiceTest extends TestCase {
    private CourseMapper $courseMapper;
    private CourseMemberMapper $courseMemberMapper;
    private CourseService $service;

    protected function setUp(): void {
        $this->courseMapper = $this->createMock(CourseMapper::class);
        $coursePoolMapper = $this->createMock(CoursePoolMapper::class);
        $this->courseMemberMapper = $this->createMock(CourseMemberMapper::class);
        $roleService = $this->createMock(RoleService::class);
        $db = $this->createMock(IDBConnection::class);
        $groupManager = $this->createMock(IGroupManager::class);
        $userManager = $this->createMock(IUserManager::class);
        $xpService = $this->createMock(XpService::class);
        $badgeService = $this->createMock(BadgeService::class);
        $streakService = $this->createMock(StreakService::class);
        $curriculumScopeMapper = $this->createMock(CurriculumScopeMapper::class);
        $feedService = $this->createMock(FeedService::class);

        $this->service = new CourseService(
            $this->courseMapper,
            $coursePoolMapper,
            $this->courseMemberMapper,
            $roleService,
            $db,
            $groupManager,
            $userManager,
            $xpService,
            $badgeService,
            $streakService,
            $curriculumScopeMapper,
            $feedService
        );
    }

    public function testCanManageCourseReturnsTrueForPrimaryInstructor(): void {
        $course = $this->makeCourse(12, 'alice');

        $this->courseMapper->expects($this->once())
            ->method('findById')
            ->with(12)
            ->willReturn($course);

        $this->courseMemberMapper->expects($this->never())
            ->method('findByCourseAndUser');

        $this->assertTrue($this->service->canManageCourse(12, 'alice'));
    }

    public function testCanManageCourseReturnsTrueForCoInstructorMember(): void {
        $course = $this->makeCourse(12, 'owner');
        $member = new CourseMember();
        $member->setCourseId(12);
        $member->setUserId('alice');
        $member->setRole('instructor');

        $this->courseMapper->method('findById')->with(12)->willReturn($course);
        $this->courseMemberMapper->expects($this->once())
            ->method('findByCourseAndUser')
            ->with(12, 'alice')
            ->willReturn($member);

        $this->assertTrue($this->service->canManageCourse(12, 'alice'));
    }

    public function testCanManageCourseReturnsFalseForStudentMember(): void {
        $course = $this->makeCourse(12, 'owner');
        $member = new CourseMember();
        $member->setCourseId(12);
        $member->setUserId('alice');
        $member->setRole('student');

        $this->courseMapper->method('findById')->with(12)->willReturn($course);
        $this->courseMemberMapper->expects($this->once())
            ->method('findByCourseAndUser')
            ->with(12, 'alice')
            ->willReturn($member);

        $this->assertFalse($this->service->canManageCourse(12, 'alice'));
    }

    public function testCanManageCourseReturnsFalseWhenCourseLookupFails(): void {
        $this->courseMapper->expects($this->once())
            ->method('findById')
            ->with(404)
            ->willThrowException(new DoesNotExistException('missing'));

        $this->assertFalse($this->service->canManageCourse(404, 'alice'));
    }

    private function makeCourse(int $id, string $instructorId): Course {
        $course = new Course();
        $course->setId($id);
        $course->setInstructorId($instructorId);
        return $course;
    }
}
