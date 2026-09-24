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
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Codeberg #9 follow-up: a practice exam can be limited to the pools marked "Required", so
 * supplementary pools stay available for self-study without entering the exam simulation.
 */
class PracticeRequiredOnlyTest extends TestCase {
    /** @var list<FakeQueryBuilder> */
    private array $builders = [];

    private function makeService(Course $course, array $coursePools): CourseService {
        $courseMapper = $this->createMock(CourseMapper::class);
        $courseMapper->method('findById')->willReturn($course);
        $coursePoolMapper = $this->createMock(CoursePoolMapper::class);
        $coursePoolMapper->method('findByCourse')->willReturn($coursePools);
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnCallback(function () {
            $builder = new FakeQueryBuilder(new FakeResult());
            $this->builders[] = $builder;
            return $builder;
        });

        return new CourseService(
            $courseMapper,
            $coursePoolMapper,
            $this->createMock(CourseMemberMapper::class),
            $this->createMock(RoleService::class),
            $db,
            $this->createMock(IGroupManager::class),
            $this->createMock(IUserManager::class),
            $this->createMock(XpService::class),
            $this->createMock(BadgeService::class),
            $this->createMock(StreakService::class),
            $this->createMock(CurriculumScopeMapper::class),
            $this->createMock(FeedService::class),
        );
    }

    private function course(?bool $requiredOnly): Course {
        $course = new Course();
        $course->setId(7);
        $course->setInstructorId('teacher');
        if ($requiredOnly !== null) {
            $course->setPracticeRequiredOnly($requiredOnly);
        }
        return $course;
    }

    private function pool(int $poolId, bool $required): CoursePool {
        $cp = new CoursePool();
        $cp->setCourseId(7);
        $cp->setPoolId($poolId);
        // Stored as integer by the entity, exactly as it comes back from the database.
        $cp->setRequired($required);
        $cp->setRequiredEnforced(false);
        return $cp;
    }

    /** Pool ids whose questions were actually queried. */
    private function queriedPoolIds(): array {
        $ids = [];
        foreach ($this->builders as $builder) {
            if (($builder->from['table'] ?? null) === 'learning_questions') {
                $ids[] = (int)$builder->namedParameters[0]['value'];
            }
        }
        sort($ids);
        return $ids;
    }

    public function testDrawsFromEveryPoolByDefault(): void {
        $service = $this->makeService($this->course(null), [$this->pool(11, true), $this->pool(12, false)]);

        $context = $service->resolveCoursePracticeContext(7, 'teacher');

        $this->assertSame([11, 12], $context['pool_ids']);
        $this->assertFalse($context['required_only']);
        $this->assertSame([11, 12], $this->queriedPoolIds());
    }

    public function testLeavesSupplementaryPoolsOutWhenLimitedToRequired(): void {
        $service = $this->makeService(
            $this->course(true),
            [$this->pool(11, true), $this->pool(12, false), $this->pool(13, true)]
        );

        $context = $service->resolveCoursePracticeContext(7, 'teacher');

        $this->assertSame([11, 13], $context['pool_ids']);
        $this->assertTrue($context['required_only']);
        // Not merely dropped from the list: the supplementary pool's questions are never read.
        $this->assertSame([11, 13], $this->queriedPoolIds());
    }

    public function testNoPoolsWhenLimitedToRequiredButNoneIsMarked(): void {
        $service = $this->makeService($this->course(true), [$this->pool(11, false), $this->pool(12, false)]);

        $context = $service->resolveCoursePracticeContext(7, 'teacher');

        $this->assertSame([], $context['pool_ids']);
        $this->assertSame([], $context['question_ids']);
        $this->assertTrue($context['required_only']);
    }

    public function testSerialisesTheFlagForTheFrontend(): void {
        $this->assertFalse($this->course(null)->jsonSerialize()['practice_required_only']);
        $this->assertTrue($this->course(true)->jsonSerialize()['practice_required_only']);
    }
}
