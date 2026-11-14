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
use OCA\Learning\Service\ExamScope;
use OCA\Learning\Service\FeedService;
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
 * GitHub #47: question count and time limit of a counting exam are set per pool in the course's
 * pool rules, with the course default as fallback. Each value falls back on its own; nothing set
 * keeps the exam as it was.
 */
#[AllowMockObjectsWithoutExpectations]
class ExamScopeTest extends TestCase {
    private function pool(?int $questions, ?int $minutes): CoursePool {
        $cp = new CoursePool();
        $cp->setCourseId(7);
        $cp->setPoolId(42);
        $cp->setExamQuestions($questions);
        $cp->setExamMinutes($minutes);
        return $cp;
    }

    private function course(?int $questions, ?int $minutes): Course {
        $course = new Course();
        $course->setId(7);
        $course->setInstructorId('teacher');
        $course->setExamQuestions($questions);
        $course->setExamMinutes($minutes);
        return $course;
    }

    public function testNothingSetKeepsTheOldRules(): void {
        $this->assertNull(ExamScope::resolve($this->pool(null, null), $this->course(null, null)));
        $this->assertNull(ExamScope::resolve(new CoursePool(), new Course()), 'rows from before the migration');
    }

    public function testPoolRuleOverridesCourseDefault(): void {
        $this->assertSame(['questions' => 15, 'minutes' => 20], ExamScope::resolve($this->pool(15, 20), $this->course(40, 60)));
    }

    public function testCourseDefaultFillsEachMissingValueOnItsOwn(): void {
        $this->assertSame(['questions' => 15, 'minutes' => 60], ExamScope::resolve($this->pool(15, null), $this->course(40, 60)));
        $this->assertSame(['questions' => 40, 'minutes' => 20], ExamScope::resolve($this->pool(null, 20), $this->course(40, 60)));
        $this->assertSame(['questions' => 40, 'minutes' => null], ExamScope::resolve($this->pool(null, null), $this->course(40, null)));
    }

    // --- Pool rules: saving --------------------------------------------------------------------

    /** @var CoursePool[] */
    private array $savedPools = [];

    private function service(string $owner, int $questionsInPool): CourseService {
        $course = $this->course(null, null);
        $course->setInstructorId($owner);
        $courseMapper = $this->createMock(CourseMapper::class);
        $courseMapper->method('findById')->willReturn($course);
        $coursePoolMapper = $this->createMock(CoursePoolMapper::class);
        $coursePoolMapper->method('findByCourseAndPool')->willReturn($this->pool(null, null));
        $coursePoolMapper->method('update')->willReturnCallback(function (CoursePool $cp): CoursePool {
            $this->savedPools[] = $cp;
            return $cp;
        });
        $members = $this->createMock(CourseMemberMapper::class);
        $members->method('findByCourseAndUser')->willThrowException(new DoesNotExistException('none'));
        $db = new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetchOne($questionsInPool)), // questions in the pool
        ]);
        return new CourseService(
            $courseMapper,
            $coursePoolMapper,
            $members,
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

    public function testInstructorSavesExamScopeInPoolRules(): void {
        $saved = $this->service('teacher', 30)->updatePoolRules(7, 42, true, false, null, null, null, 'teacher', null, 30, 45);

        $this->assertSame(30, $saved->getExamQuestions());
        $this->assertSame(45, $saved->getExamMinutes());
        $this->assertSame(30, $saved->jsonSerialize()['exam_questions']);
        $this->assertSame(45, $saved->jsonSerialize()['exam_minutes']);
    }

    public function testEmptyValuesMeanCourseDefault(): void {
        $saved = $this->service('teacher', 30)->updatePoolRules(7, 42, true, false, null, null, null, 'teacher', null, null, null);

        $this->assertNull($saved->getExamQuestions());
        $this->assertNull($saved->getExamMinutes());
    }

    public function testLearnerCannotSavePoolRules(): void {
        $this->expectExceptionMessage('No permission');
        try {
            $this->service('teacher', 30)->updatePoolRules(7, 42, true, false, null, null, null, 'learner', null, 5, 5);
        } finally {
            $this->assertSame([], $this->savedPools);
        }
    }

    /** @return array<string, array{?int, ?int}> */
    public static function invalidPoolValues(): array {
        return [
            'zero questions'          => [0, null],
            'more than the pool has'  => [31, null],
            'zero minutes'            => [null, 0],
            'over 10 hours'           => [null, 601],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPoolValues')]
    public function testOutOfRangePoolValuesAreRefused(?int $questions, ?int $minutes): void {
        try {
            $this->service('teacher', 30)->updatePoolRules(7, 42, true, false, null, null, null, 'teacher', null, $questions, $minutes);
            $this->fail('expected a refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringStartsWith('exam_', $e->getMessage());
        }
        $this->assertSame([], $this->savedPools);
    }
}
