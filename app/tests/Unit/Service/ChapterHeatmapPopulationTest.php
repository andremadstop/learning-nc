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
use OCA\Learning\Service\RoleService;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\XpService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/** The worst-questions query uses having(), which the shared fake builder does not offer. */
final class HavingQueryBuilder extends FakeQueryBuilder {
    public array $havingCalls = [];

    public function having(mixed ...$conditions): self {
        $this->havingCalls[] = $conditions;
        return $this;
    }
}

/**
 * Audit #156: the chapter heatmap counted the students of the course (plus the never assigned
 * role 'co_instructor'), while the "worst questions" of the same response joined neither the
 * sessions nor the members and counted every answer to the pool's questions, also from other
 * courses sharing the pool and from instructors. Both queries now count the same population.
 */
#[AllowMockObjectsWithoutExpectations]
class ChapterHeatmapPopulationTest extends TestCase {
    private function runHeatmap(): FakeDbConnection {
        $course = new Course();
        $course->setId(7);
        $course->setInstructorId('teacher');
        $courseMapper = $this->createMock(CourseMapper::class);
        $courseMapper->method('findById')->willReturn($course);

        $db = new FakeDbConnection([new HavingQueryBuilder(), new HavingQueryBuilder()]);
        $service = new CourseService(
            $courseMapper,
            $this->createMock(CoursePoolMapper::class),
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
        $service->getChapterHeatmap(7, 'teacher');
        return $db;
    }

    /** @return list<mixed> */
    private function namedValues(FakeQueryBuilder $qb): array {
        return array_map(static fn (array $p): mixed => $p['value'], $qb->namedParameters);
    }

    public function testWorstQuestionsCountOnlyTheStudentsOfThisCourse(): void {
        $worst = $this->runHeatmap()->issuedBuilders[1];

        $joined = array_column($worst->joins, 'join', 'alias');
        $this->assertSame('learning_sessions', $joined['s'] ?? null);
        $this->assertSame('learning_course_members', $joined['cm'] ?? null);
        $this->assertContains('student', $this->namedValues($worst));
    }

    public function testChapterQueryFiltersOnTheAssignedStudentRoleOnly(): void {
        $chapters = $this->runHeatmap()->issuedBuilders[0];

        $values = $this->namedValues($chapters);
        $this->assertContains('student', $values);
        $this->assertNotContains(['student', 'co_instructor'], $values);
    }
}
