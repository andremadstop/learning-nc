<?php
declare(strict_types=1);
namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\CourseController;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Service\CourseArchiveService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\ExamAttemptPolicy;
use OCA\Learning\Service\PassCriteriaService;
use OCA\Learning\Service\RoleService;
use OCA\Learning\Service\ScheduleService;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #47: the course default for the exam scope is saved with the certification settings.
 * Omitted = unchanged, 0 = no course default; only the course's instructor may change it.
 */
#[AllowMockObjectsWithoutExpectations]
class ExamScopeConfigTest extends TestCase {
    private Course $course;
    /** @var Course[] */
    private array $updated = [];
    private ?CourseService $courseService = null;

    private function makeController(string $userId): CourseController {
        $this->course = new Course();
        $this->course->setId(7);
        $this->course->setInstructorId('teacher');
        $this->course->setExamQuestions(40);
        $this->course->setExamMinutes(60);
        $mapper = $this->createMock(CourseMapper::class);
        $mapper->method('findById')->willReturn($this->course);
        $mapper->method('update')->willReturnCallback(function (Course $c): Course {
            $this->updated[] = $c;
            return $c;
        });
        $members = $this->createMock(CourseMemberMapper::class);
        $members->method('findByCourseAndUser')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('none'));

        return new CourseController(
            'learning',
            $this->createMock(IRequest::class),
            $mapper,
            $members,
            $this->createMock(CourseArchiveService::class),
            $this->courseService ?? $this->createMock(CourseService::class),
            $this->createMock(RoleService::class),
            $this->createMock(ScheduleService::class),
            $this->createMock(PassCriteriaService::class),
            $this->createMock(ExamAttemptPolicy::class),
            $this->createMock(\OCA\Learning\Service\CourseEnrollmentService::class),
            $this->createMock(LoggerInterface::class),
            $userId
        );
    }

    public function testInstructorSetsCourseDefault(): void {
        $response = $this->makeController('teacher')->updateCertConfig(7, null, null, null, null, null, 25, 30);

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(25, $response->getData()['examQuestions']);
        $this->assertSame(30, $response->getData()['examMinutes']);
        $this->assertSame(25, $this->course->jsonSerialize()['exam_questions']);
    }

    public function testZeroClearsAndOmittedKeepsTheDefault(): void {
        $response = $this->makeController('teacher')->updateCertConfig(7, null, null, null, null, null, 0, null);

        $this->assertSame(200, $response->getStatus());
        $this->assertNull($response->getData()['examQuestions'], '0 = no course default');
        $this->assertSame(60, $response->getData()['examMinutes'], 'omitted = unchanged');
    }

    public function testLearnerCannotSetCourseDefault(): void {
        $response = $this->makeController('learner')->updateCertConfig(7, null, null, null, null, null, 5, 5);

        $this->assertSame(403, $response->getStatus());
        $this->assertSame([], $this->updated);
        $this->assertSame(40, $this->course->getExamQuestions());
    }

    /** @return array<string, array{?int, ?int}> */
    public static function invalidValues(): array {
        return [
            'negative questions' => [-1, null],
            'too many questions' => [501, null],
            'negative minutes'   => [null, -5],
            'over 10 hours'      => [null, 601],
        ];
    }

    #[DataProvider('invalidValues')]
    public function testOutOfRangeIsRefused(?int $questions, ?int $minutes): void {
        $response = $this->makeController('teacher')->updateCertConfig(7, null, null, null, null, null, $questions, $minutes);

        $this->assertSame(400, $response->getStatus());
        $this->assertSame([], $this->updated);
    }

    /** The pool-rule endpoint passes both values on to the service, null included. */
    public function testUpdatePoolPassesExamScopeToService(): void {
        $this->courseService = $this->createMock(CourseService::class);
        $this->courseService->expects($this->once())->method('updatePoolRules')
            ->with(7, 42, true, false, null, null, null, 'teacher', true, 12, null)
            ->willReturn(new \OCA\Learning\Db\CoursePool());

        $response = $this->makeController('teacher')->updatePool(7, 42, true, false, null, null, null, true, 12, null);

        $this->assertSame(200, $response->getStatus());
    }

    public function testUpdatePoolReportsOutOfRangeAs400(): void {
        $this->courseService = $this->createMock(CourseService::class);
        $this->courseService->method('updatePoolRules')->willThrowException(new \InvalidArgumentException('exam_minutes must be 1–600'));

        $response = $this->makeController('teacher')->updatePool(7, 42, true, false, null, null, null, null, null, 0);

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('exam_minutes must be 1–600', $response->getData()['error']);
    }
}
