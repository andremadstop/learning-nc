<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
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
 * GitHub #35: the course timeline (now visible to learners) matches each schedule entry's
 * chapter_ref against the pools' progress. my-progress carried only pool_id and pool_name, so a
 * chapter_ref set to the pool's chapter key, the value the schedule editor suggests, never
 * showed any progress.
 */
#[AllowMockObjectsWithoutExpectations]
class CourseProgressChapterKeyTest extends TestCase {
    public function testMyProgressCarriesEachPoolsChapterKey(): void {
        $course = new Course();
        $course->setId(7);
        $course->setInstructorId('learner');
        $courseMapper = $this->createMock(CourseMapper::class);
        $courseMapper->method('findById')->willReturn($course);

        $pools = [];
        foreach ([11, 12] as $poolId) {
            $cp = new CoursePool();
            $cp->setCourseId(7);
            $cp->setPoolId($poolId);
            $pools[] = $cp;
        }
        $coursePools = $this->createMock(CoursePoolMapper::class);
        $coursePools->method('findByCourse')->willReturn($pools);

        // Order of getMyCourseProgress: names, chapter keys, question counts, mastery, sessions.
        $db = new FakeDbConnection([
            new FakeQueryBuilder(new FakeResult([['id' => 11, 'name' => 'Switching'], ['id' => 12, 'name' => 'Routing']])),
            new FakeQueryBuilder(new FakeResult([['id' => 11, 'chapter_key' => 'chapter-03'], ['id' => 12, 'chapter_key' => '']])),
            new FakeQueryBuilder(new FakeResult([['pool_id' => 11, 'cnt' => 10], ['pool_id' => 12, 'cnt' => 4]])),
            new FakeQueryBuilder(new FakeResult([['user_id' => 'learner', 'pool_id' => 11, 'cnt' => 5]])),
            new FakeQueryBuilder(new FakeResult([])),
        ]);

        $service = new CourseService(
            $courseMapper,
            $coursePools,
            $this->createMock(CourseMemberMapper::class),
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

        $result = $service->getMyCourseProgress(7, 'learner')['pools'];

        $this->assertSame('chapter-03', $result[0]['chapter_key']);
        $this->assertSame(5, $result[0]['mastered']);
        $this->assertSame(10, $result[0]['total_questions']);
        $this->assertNull($result[1]['chapter_key'], 'an empty chapter key is no key');
        $this->assertSame('learning_pools', $db->issuedBuilders[1]->from['table']);
    }
}
