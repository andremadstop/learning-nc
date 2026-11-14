<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\CourseController;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Service\CourseArchiveService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\ExamAttemptPolicy;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\PassCriteriaService;
use OCA\Learning\Service\RoleService;
use OCA\Learning\Service\ScheduleService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #40: PUT /api/courses/{courseId}/announcements/{announcementId} edits an announcement.
 * The service decides the rights; the controller maps them to 403/404/400 and never leaks an
 * internal message.
 */
#[AllowMockObjectsWithoutExpectations]
class CourseAnnouncementUpdateControllerTest extends TestCase {
    private function controller(CourseService $service, string $userId = 'teacher'): CourseController {
        return new CourseController(
            'learning',
            $this->createMock(IRequest::class),
            $this->createMock(CourseMapper::class),
            $this->createMock(CourseMemberMapper::class),
            $this->createMock(CourseArchiveService::class),
            $service,
            $this->createMock(RoleService::class),
            $this->createMock(ScheduleService::class),
            $this->createMock(PassCriteriaService::class),
            $this->createMock(ExamAttemptPolicy::class),
            $this->createMock(\OCA\Learning\Service\CourseEnrollmentService::class),
            $this->createMock(LoggerInterface::class),
            $userId
        );
    }

    public function testAnInstructorGetsTheEditedAnnouncement(): void {
        $service = $this->createMock(CourseService::class);
        $service->expects($this->once())->method('updateAnnouncement')
            ->with(12, 77, 'teacher', 'New title', 'New text')
            ->willReturn(['id' => 77, 'title' => 'New title', 'body' => 'New text', 'edited_at' => 1700005000]);

        $response = $this->controller($service)->updateAnnouncement(12, 77, 'New title', 'New text');

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('New title', $response->getData()['announcement']['title']);
    }

    public function testAStudentGetsForbidden(): void {
        $service = $this->createMock(CourseService::class);
        $service->method('updateAnnouncement')->willThrowException(new ForbiddenException('No permission'));

        $this->assertSame(403, $this->controller($service, 'learner')->updateAnnouncement(12, 77, 'x', 'y')->getStatus());
    }

    public function testAnAnnouncementOfAnotherCourseIsNotFound(): void {
        $service = $this->createMock(CourseService::class);
        $service->method('updateAnnouncement')->willThrowException(new DoesNotExistException('Announcement not found'));

        $this->assertSame(404, $this->controller($service)->updateAnnouncement(12, 999, 'x', 'y')->getStatus());
    }

    public function testAnEmptyTitleIsABadRequest(): void {
        $service = $this->createMock(CourseService::class);
        $service->method('updateAnnouncement')->willThrowException(new \InvalidArgumentException('Title is required'));

        $this->assertSame(400, $this->controller($service)->updateAnnouncement(12, 77, '', 'y')->getStatus());
    }

    public function testAnInternalErrorIsA500WithoutItsMessage(): void {
        $service = $this->createMock(CourseService::class);
        $service->method('updateAnnouncement')->willThrowException(new \RuntimeException('SQLSTATE secret'));

        $response = $this->controller($service)->updateAnnouncement(12, 77, 'x', 'y');
        $this->assertSame(500, $response->getStatus());
        $this->assertSame(['error' => 'Internal error'], $response->getData());
    }

    /** Nextcloud maps the JSON body BY NAME; CourseFeed sends {title, body}. */
    public function testParameterNamesMatchTheRequestFields(): void {
        $names = array_map(
            fn (\ReflectionParameter $p) => $p->getName(),
            (new \ReflectionMethod(CourseController::class, 'updateAnnouncement'))->getParameters()
        );
        $this->assertSame(['courseId', 'announcementId', 'title', 'body'], $names);
    }

    public function testThePutRouteExists(): void {
        $routes = require __DIR__ . '/../../../appinfo/routes.php';
        $match = array_filter($routes['routes'], static fn (array $r): bool =>
            $r['name'] === 'course#updateAnnouncement'
            && $r['url'] === '/api/courses/{courseId}/announcements/{announcementId}'
            && $r['verb'] === 'PUT');
        $this->assertCount(1, $match);
    }
}
