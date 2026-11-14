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
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #49: the leaderboard took the first word of the display name as the first name, so a
 * learner whose display name is "Doe Jane" was listed as "Doe". It shows the whole display
 * name now, as the class register and the progress table do.
 */
#[AllowMockObjectsWithoutExpectations]
class LeaderboardDisplayNameTest extends TestCase {
    /** @param array<string, string> $names user id => display name */
    private function board(string $viewer, array $names): array {
        $course = new Course();
        $course->setId(7);
        $course->setInstructorId('teacher');
        $courses = $this->createMock(CourseMapper::class);
        $courses->method('findById')->willReturn($course);

        $member = new CourseMember();
        $member->setCourseId(7);
        $member->setUserId('learner');
        $member->setRole('student');
        $members = $this->createMock(CourseMemberMapper::class);
        $members->method('findByCourse')->willReturn([$member]);
        $members->method('findByCourseAndUser')->willReturn($member);

        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(function (string $uid) use ($names): ?IUser {
            if (!isset($names[$uid])) {
                return null;
            }
            $user = $this->createMock(IUser::class);
            $user->method('getDisplayName')->willReturn($names[$uid]);
            return $user;
        });

        $xp = $this->createMock(XpService::class);
        $xp->method('isGamificationEnabled')->willReturn(true);

        $rows = new FakeQueryBuilder(new FakeResult([
            ['user_id' => 'learner', 'total_xp' => 10, 'current_level' => 1, 'total_mastered' => 0, 'current_streak' => 0, 'total_sessions' => 1, 'last_activity_date' => null],
        ]));
        $db = new FakeDbConnection([new FakeQueryBuilder(FakeResult::fromFetchOne(1)), $rows]);

        $service = new CourseService(
            $courses,
            $this->createMock(CoursePoolMapper::class),
            $members,
            $this->createMock(RoleService::class),
            $db,
            $this->createMock(IGroupManager::class),
            $users,
            $xp,
            $this->createMock(BadgeService::class),
            $this->createMock(StreakService::class),
            $this->createMock(CurriculumScopeMapper::class),
            $this->createMock(FeedService::class)
        );
        return $service->getLeaderboard(7, $viewer, 25, 0, 'total_mastered', 'desc')['leaderboard'];
    }

    public function testALearnerSeesTheWholeDisplayName(): void {
        $board = $this->board('learner', ['learner' => 'Doe Jane']);
        $this->assertSame('Doe Jane', $board[0]['display_name']);
        $this->assertArrayNotHasKey('user_id', $board[0], 'learners still get no user ids');
    }

    public function testTheInstructorSeesTheWholeDisplayName(): void {
        $board = $this->board('teacher', ['learner' => 'Jane Doe']);
        $this->assertSame('Jane Doe', $board[0]['display_name']);
    }

    public function testAOneWordNameAndAMissingUserStayReadable(): void {
        $this->assertSame('Jane', $this->board('learner', ['learner' => 'Jane'])[0]['display_name']);
        $this->assertSame('learner', $this->board('learner', [])[0]['display_name'], 'a deleted account falls back to its id');
    }
}
