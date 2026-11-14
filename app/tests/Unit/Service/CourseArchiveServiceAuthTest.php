<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMember;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Service\CourseArchiveService;
use OCA\Learning\Service\DataMobilityService;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\RoleService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 2026-11 audit S00: archiving a course and reading its snapshots (members + learning stats) was
 * open to every member of the global instructor group, not just to this course's instructors.
 * Only a Nextcloud admin, the course owner or a co-instructor of THIS course may pass.
 */
#[AllowMockObjectsWithoutExpectations]
class CourseArchiveServiceAuthTest extends TestCase {
    private const COURSE_ID = 42;

    /**
     * The export is the first thing after the permission check; a sentinel exception there
     * proves the check let the caller through.
     */
    private function makeService(bool $groupInstructor, bool $ncAdmin, string $ownerId, ?string $memberRole): CourseArchiveService {
        $course = new Course();
        $course->setId(self::COURSE_ID);
        $course->setInstructorId($ownerId);
        $courseMapper = $this->createMock(CourseMapper::class);
        $courseMapper->method('findById')->willReturn($course);

        $memberMapper = $this->createMock(CourseMemberMapper::class);
        if ($memberRole === null) {
            $memberMapper->method('findByCourseAndUser')->willThrowException(new DoesNotExistException('none'));
        } else {
            $member = new CourseMember();
            $member->setRole($memberRole);
            $memberMapper->method('findByCourseAndUser')->willReturn($member);
        }

        $roles = $this->createMock(RoleService::class);
        $roles->method('isInstructor')->willReturn($groupInstructor || $ncAdmin);
        $roles->method('isAdmin')->willReturn($ncAdmin);

        $mobility = $this->createMock(DataMobilityService::class);
        $mobility->method('exportCourse')->willThrowException(new \RuntimeException('passed-auth'));

        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willThrowException(new \RuntimeException('passed-auth'));

        return new CourseArchiveService(
            $courseMapper,
            $memberMapper,
            $mobility,
            $roles,
            $db,
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testGroupInstructorOfAnotherCourseCannotArchive(): void {
        $service = $this->makeService(true, false, 'alice', null);
        $this->expectException(ForbiddenException::class);
        $service->archiveCourse(self::COURSE_ID, 'bob');
    }

    public function testGroupInstructorOfAnotherCourseCannotListSnapshots(): void {
        $service = $this->makeService(true, false, 'alice', null);
        $this->expectException(ForbiddenException::class);
        $service->listSnapshots(self::COURSE_ID, 'bob');
    }

    public function testStudentMemberCannotArchive(): void {
        $service = $this->makeService(false, false, 'alice', 'student');
        $this->expectException(ForbiddenException::class);
        $service->archiveCourse(self::COURSE_ID, 'bob');
    }

    public function testNextcloudAdminMayArchive(): void {
        $service = $this->makeService(false, true, 'alice', null);
        $this->expectExceptionMessage('passed-auth');
        $service->archiveCourse(self::COURSE_ID, 'admin');
    }

    public function testCourseOwnerMayArchive(): void {
        $service = $this->makeService(true, false, 'alice', null);
        $this->expectExceptionMessage('passed-auth');
        $service->archiveCourse(self::COURSE_ID, 'alice');
    }

    public function testCoInstructorMayListSnapshots(): void {
        $service = $this->makeService(false, false, 'alice', 'instructor');
        $this->expectExceptionMessage('passed-auth');
        $service->listSnapshots(self::COURSE_ID, 'carol');
    }

    /**
     * Audit #8: the occ path has no Nextcloud user and must not depend on one — even when no
     * user passes any role check, archiveCourseFromConsole reaches the snapshot export.
     */
    public function testSystemArchiveNeedsNoUser(): void {
        $service = $this->makeService(false, false, 'alice', null);
        $this->expectExceptionMessage('passed-auth');
        $service->archiveCourseFromConsole(self::COURSE_ID, CourseArchiveService::SYSTEM_ACTOR);
    }
}
