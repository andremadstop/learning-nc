<?php
declare(strict_types=1);
namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\CourseController;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Service\CourseArchiveService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\PassCriteriaService;
use OCA\Learning\Service\RoleService;
use OCA\Learning\Service\ScheduleService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Codeberg #9: instructors configure a course's practice exam. Only the course's instructor may,
 * and out-of-range values are refused instead of being clamped silently.
 */
class PracticeConfigTest extends TestCase {

    private Course $course;
    /** @var Course[] */
    private array $updated = [];

    private function makeController(string $userId): CourseController {
        $this->course = new Course();
        $this->course->setId(7);
        $this->course->setInstructorId('teacher');
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
            $this->createMock(CourseService::class),
            $this->createMock(RoleService::class),
            $this->createMock(ScheduleService::class),
            $this->createMock(PassCriteriaService::class),
            $this->createMock(LoggerInterface::class),
            $userId
        );
    }

    public function testInstructorSavesConfigIncludingUntimed(): void {
        $response = $this->makeController('teacher')->updatePracticeConfig(7, true, 30, 0, 70);

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(['practice_enabled' => true, 'practice_questions' => 30, 'practice_minutes' => 0, 'practice_pass_percent' => 70, 'practice_required_only' => false], $response->getData());
        $this->assertCount(1, $this->updated);
    }

    /** Codeberg #9 follow-up: the flag is saved on its own and read back in the response. */
    public function testInstructorLimitsDrawToRequiredPools(): void {
        $response = $this->makeController('teacher')->updatePracticeConfig(7, null, null, null, null, true);

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($response->getData()['practice_required_only']);
        $this->assertCount(1, $this->updated);
    }

    public function testLearnerCannotConfigure(): void {
        $response = $this->makeController('learner')->updatePracticeConfig(7, true);

        $this->assertSame(403, $response->getStatus());
        $this->assertSame([], $this->updated);
    }

    /** @dataProvider invalidValues */
    public function testOutOfRangeIsRefused(?int $questions, ?int $minutes, ?int $pass): void {
        $response = $this->makeController('teacher')->updatePracticeConfig(7, null, $questions, $minutes, $pass);

        $this->assertSame(400, $response->getStatus());
        $this->assertSame([], $this->updated, 'nothing is saved when one field is invalid');
    }

    /** @return array<string, array{?int, ?int, ?int}> */
    public static function invalidValues(): array {
        return [
            'zero questions'     => [0, null, null],
            'too many questions' => [501, null, null],
            'negative minutes'   => [null, -1, null],
            'over 10 hours'      => [null, 601, null],
            'zero percent'       => [null, null, 0],
            'over 100 percent'   => [null, null, 101],
        ];
    }
}
