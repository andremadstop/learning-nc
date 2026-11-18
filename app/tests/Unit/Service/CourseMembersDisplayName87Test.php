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
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #87 (review): the instructors' "attempts used up" notification names the learner by
 * display name and links to the member list, which showed only uids — on LDAP/SSO instances
 * GUIDs or personnel numbers, so the instructor could not tell who was meant. The member rows
 * now carry display_name; an account the user backend no longer knows keeps its uid.
 */
#[AllowMockObjectsWithoutExpectations]
class CourseMembersDisplayName87Test extends TestCase {
    private function member(int $id, string $uid): CourseMember {
        $m = new CourseMember();
        $m->setId($id);
        $m->setCourseId(7);
        $m->setUserId($uid);
        $m->setRole('student');
        $m->setEnrolledAt(1);
        return $m;
    }

    public function testEachMemberRowCarriesTheDisplayName(): void {
        $course = new Course();
        $course->setId(7);
        $course->setInstructorId('teacher');
        $courses = $this->createMock(CourseMapper::class);
        $courses->method('findById')->willReturn($course);
        $members = $this->createMock(CourseMemberMapper::class);
        $members->method('findByCourse')->willReturn([$this->member(31, 'a3f9c1e0'), $this->member(32, 'gone')]);
        $users = $this->createMock(IUserManager::class);
        $max = $this->createMock(IUser::class);
        $max->method('getDisplayName')->willReturn('Max Mustermann');
        $users->method('get')->willReturnMap([['a3f9c1e0', $max], ['gone', null]]);

        $service = new CourseService(
            $courses,
            $this->createMock(CoursePoolMapper::class),
            $members,
            $this->createMock(RoleService::class),
            new FakeDbConnection(),
            $this->createMock(IGroupManager::class),
            $users,
            $this->createMock(XpService::class),
            $this->createMock(BadgeService::class),
            $this->createMock(StreakService::class),
            $this->createMock(CurriculumScopeMapper::class),
            $this->createMock(FeedService::class)
        );

        $rows = $service->getMembers(7, 'teacher');
        $this->assertSame(['a3f9c1e0', 'gone'], array_column($rows, 'user_id'));
        $this->assertSame(['Max Mustermann', 'gone'], array_column($rows, 'display_name'));
        $this->assertSame([31, 32], array_column($rows, 'id'));
    }
}
