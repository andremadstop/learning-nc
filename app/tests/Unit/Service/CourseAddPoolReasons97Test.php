<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CoursePoolMapper;
use OCA\Learning\Db\CurriculumScopeMapper;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\FeedService;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\NotFoundException;
use OCA\Learning\Service\PoolViewOnlyException;
use OCA\Learning\Service\RoleService;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\XpService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #97: adding a pool to a course failed with one bare "Failed to add pool" for every
 * reason. The service now tells the reasons apart, without weakening FIX-HI-2: only a user who
 * holds a read share of the pool learns that it is "view only"; for anyone else the pool stays
 * "not found", whether it exists or not.
 */
#[AllowMockObjectsWithoutExpectations]
class CourseAddPoolReasons97Test extends TestCase {
    private function service(FakeDbConnection $db, ?CourseMapper $courses = null, ?CourseMemberMapper $members = null): CourseService {
        return new CourseService(
            $courses ?? $this->course(),
            $this->createMock(CoursePoolMapper::class),
            $members ?? $this->createMock(CourseMemberMapper::class),
            $this->createMock(RoleService::class),
            $db,
            $this->createMock(IGroupManager::class),
            $this->createMock(IUserManager::class),
            $this->createMock(XpService::class),
            $this->createMock(BadgeService::class),
            $this->createMock(StreakService::class),
            $this->createMock(CurriculumScopeMapper::class),
            $this->createMock(FeedService::class)
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

    /** owner count, edit-share count, read-share count — the three lookups in this order */
    private function db(int $owner, int $edit, int $read): FakeDbConnection {
        return new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetchOne($owner)),
            new FakeQueryBuilder(FakeResult::fromFetchOne($edit)),
            new FakeQueryBuilder(FakeResult::fromFetchOne($read)),
        ]);
    }

    public function testAReadShareIsToldThePoolIsViewOnly(): void {
        $this->expectException(PoolViewOnlyException::class);
        $this->service($this->db(0, 0, 1))->addPool(7, 42, 0, true, 'teacher');
    }

    public function testAPoolWithoutAnyShareStaysNotFound(): void {
        // a foreign pool (or one that does not exist): no hint that it is there
        try {
            $this->service($this->db(0, 0, 0))->addPool(7, 99, 0, true, 'teacher');
            $this->fail('expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertNotInstanceOf(PoolViewOnlyException::class, $e);
        }
    }

    public function testTheReadShareLookupAsksForThisUsersNonEditShare(): void {
        $lookup = new FakeQueryBuilder(FakeResult::fromFetchOne(1));
        $db = new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetchOne(0)),
            new FakeQueryBuilder(FakeResult::fromFetchOne(0)),
            $lookup,
        ]);
        try {
            $this->service($db)->addPool(7, 42, 0, true, 'teacher');
            $this->fail('expected PoolViewOnlyException');
        } catch (PoolViewOnlyException $e) {
        }
        $this->assertSame('learning_pool_shares', $lookup->from['table'] ?? null);
        $values = array_column($lookup->namedParameters, 'value');
        $this->assertContains(42, $values);
        $this->assertContains('teacher', $values);
        $this->assertContains('user', $values);
        $this->assertContains('edit', $values);
    }

    public function testSomeoneWhoDoesNotLeadTheCourseIsRefused(): void {
        $members = $this->createMock(CourseMemberMapper::class);
        $members->method('findByCourseAndUser')->willThrowException(new DoesNotExistException(''));
        $this->expectException(ForbiddenException::class);
        $this->service($this->db(1, 0, 0), null, $members)->addPool(7, 42, 0, true, 'stranger');
    }
}
