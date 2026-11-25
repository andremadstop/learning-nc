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
use OCA\Learning\Service\MaterialShareService;
use OCA\Learning\Service\RoleService;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\XpService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #110: the read share of the material folder exists for a membership and ends with it.
 *
 * The share is what lets a member open a Markdown material in the Text viewer (/f/{fileid}),
 * so a member who is removed must not keep it. The revoke runs after the membership is
 * committed (a failed removal changes nothing) and is best effort: MaterialShareService logs
 * its own failures, the membership removal never fails because of it.
 */
#[AllowMockObjectsWithoutExpectations]
class CourseRemoveMemberShare110Test extends TestCase {
    private FakeDbConnection $db;

    /** @var MaterialShareService&MockObject */
    private $materialShares;

    private function service(bool $deleteFails = false): CourseService {
        $course = new Course();
        $course->setId(7);
        $course->setInstructorId('teacher');
        $course->setMaterialFolder('/Kurs/Material');
        $courses = $this->createMock(CourseMapper::class);
        $courses->method('findById')->willReturn($course);

        $member = new CourseMember();
        $member->setId(31);
        $member->setCourseId(7);
        $member->setUserId('anna');
        $member->setRole('student');
        $members = $this->createMock(CourseMemberMapper::class);
        $members->method('findById')->willReturn($member);
        $members->method('findByCourseAndUser')->willReturn($member);
        if ($deleteFails) {
            $members->method('delete')->willThrowException(new \RuntimeException('db down'));
        } else {
            $members->method('delete')->willReturnArgument(0);
        }

        $this->materialShares = $this->createMock(MaterialShareService::class);
        $this->db = new FakeDbConnection();
        return new CourseService(
            $courses,
            $this->createMock(CoursePoolMapper::class),
            $members,
            $this->createMock(RoleService::class),
            $this->db,
            $this->createMock(IGroupManager::class),
            $this->createMock(IUserManager::class),
            $this->createMock(XpService::class),
            $this->createMock(BadgeService::class),
            $this->createMock(StreakService::class),
            $this->createMock(CurriculumScopeMapper::class),
            $this->createMock(FeedService::class),
            null,
            null,
            $this->materialShares
        );
    }

    public function testRemovingAMemberRevokesTheirMaterialShare(): void {
        $service = $this->service();
        $this->materialShares->expects($this->once())->method('revokeLearnerAccess')
            ->with($this->callback(static fn (Course $course): bool => $course->getId() === 7), 'anna');

        $service->removeMember(7, '31', 'teacher');

        $this->assertSame(1, $this->db->commitCalls, 'the share goes after the membership is committed');
    }

    public function testAFailedRemovalLeavesTheShareInPlace(): void {
        $service = $this->service(true);
        $this->materialShares->expects($this->never())->method('revokeLearnerAccess');

        try {
            $service->removeMember(7, '31', 'teacher');
            $this->fail('the failure is not swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('db down', $e->getMessage());
        }
        $this->assertSame(0, $this->db->commitCalls);
    }
}
