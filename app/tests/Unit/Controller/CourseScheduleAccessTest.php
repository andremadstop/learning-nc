<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\CourseController;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMember;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CourseSchedule;
use OCA\Learning\Db\CourseScheduleMapper;
use OCA\Learning\Db\CurriculumScopeMapper;
use OCA\Learning\Service\CourseArchiveService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\ExamAttemptPolicy;
use OCA\Learning\Service\PassCriteriaService;
use OCA\Learning\Service\RoleService;
use OCA\Learning\Service\ScheduleService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #35: the schedule endpoints with the real ScheduleService behind the controller.
 * GET is for every member of the course; PUT and DELETE stay with its instructors.
 */
#[AllowMockObjectsWithoutExpectations]
class CourseScheduleAccessTest extends TestCase {
    private int $writes = 0;

    private function controllerFor(string $userId): CourseController {
        $course = new Course();
        $course->setId(7);
        $course->setInstructorId('owner');
        $courseMapper = $this->createMock(CourseMapper::class);
        $courseMapper->method('findById')->willReturnCallback(function (int $id) use ($course): Course {
            if ($id !== 7) {
                throw new DoesNotExistException('no course');
            }
            return $course;
        });

        $members = $this->createMock(CourseMemberMapper::class);
        $members->method('findByCourseAndUser')->willReturnCallback(function (int $courseId, string $uid): CourseMember {
            if ($courseId !== 7 || $uid !== 'learner') {
                throw new DoesNotExistException('not a member');
            }
            $member = new CourseMember();
            $member->setCourseId(7);
            $member->setUserId('learner');
            $member->setRole('student');
            return $member;
        });

        $entry = new CourseSchedule();
        $entry->setCourseId(7);
        $entry->setChapterRef('chapter-01');
        $entry->setChapterTitle('Basics');
        $scheduleMapper = $this->createMock(CourseScheduleMapper::class);
        $scheduleMapper->method('findByCourse')->willReturn([$entry]);
        $scheduleMapper->method('deleteByCourse')->willReturnCallback(function (): void {
            $this->writes++;
        });
        $scheduleMapper->method('insert')->willReturnCallback(function (CourseSchedule $e): CourseSchedule {
            $this->writes++;
            return $e;
        });
        $scopes = $this->createMock(CurriculumScopeMapper::class);
        $scopes->method('findByCourse')->willReturn(null);

        $schedule = new ScheduleService($scheduleMapper, $courseMapper, $members, $scopes, new FakeDbConnection());

        return new CourseController(
            'learning',
            $this->createMock(IRequest::class),
            $courseMapper,
            $members,
            $this->createMock(CourseArchiveService::class),
            $this->createMock(CourseService::class),
            $this->createMock(RoleService::class),
            $schedule,
            $this->createMock(PassCriteriaService::class),
            $this->createMock(ExamAttemptPolicy::class),
            $this->createMock(\OCA\Learning\Service\CourseEnrollmentService::class),
            $this->createMock(LoggerInterface::class),
            $userId
        );
    }

    public function testStudentGetsTheSchedule(): void {
        $response = $this->controllerFor('learner')->getSchedule(7);

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('chapter-01', $response->getData()['schedule'][0]['chapter_ref']);
    }

    public function testNonMemberGets403(): void {
        $response = $this->controllerFor('stranger')->getSchedule(7);

        $this->assertSame(403, $response->getStatus());
        $this->assertArrayNotHasKey('schedule', $response->getData());
    }

    public function testUnknownCourseGets404(): void {
        $this->assertSame(404, $this->controllerFor('learner')->getSchedule(999)->getStatus());
    }

    public function testStudentCannotWriteOrDelete(): void {
        $controller = $this->controllerFor('learner');

        $this->assertSame(403, $controller->setSchedule(7, [['chapter_ref' => 'x', 'chapter_title' => 'X']])->getStatus());
        $this->assertSame(403, $controller->deleteSchedule(7)->getStatus());
        $this->assertSame(0, $this->writes);
    }

    /** The controller reads `entries`; the owner's write reaches the mapper. */
    public function testOwnerWritesEntries(): void {
        $response = $this->controllerFor('owner')->setSchedule(7, [['chapter_ref' => 'chapter-02', 'chapter_title' => 'Two']]);

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('chapter-02', $response->getData()['schedule'][0]['chapter_ref']);
        $this->assertSame(2, $this->writes, 'one delete, one insert');
    }

    /**
     * testOwnerWritesEntries calls setSchedule() positionally and cannot prove the controller
     * reads `entries`: Nextcloud maps JSON fields to arguments BY NAME. With a renamed parameter
     * the [] default would replace the schedule with nothing (the data loss that
     * requestPayloadContracts.test.js pins from the frontend side). `entries` is what
     * CourseTabVerwaltung sends.
     */
    public function testControllerParameterNamesMatchTheRequestFieldsTheFrontendSends(): void {
        $names = fn (string $method) => array_map(
            fn (\ReflectionParameter $p) => $p->getName(),
            (new \ReflectionMethod(CourseController::class, $method))->getParameters()
        );

        $this->assertSame(['courseId', 'entries'], $names('setSchedule'));
        $this->assertSame(['courseId'], $names('getSchedule'));
        $this->assertSame(['courseId'], $names('deleteSchedule'));
    }
}
