<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\VideoProgressController;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseDocumentMapper;
use OCA\Learning\Db\CourseVideo;
use OCA\Learning\Db\CourseVideoMapper;
use OCA\Learning\Db\VideoProgress;
use OCA\Learning\Db\VideoProgressMapper;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\DocumentUnavailableException;
use OCA\Learning\Service\RequiredDocumentService;
use OCA\Learning\Service\VideoProgressService;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #75: the learner's status list and "Confirm I have read this" go through the
 * confirmation rule. Before, a document showed as done because of a progress row from any
 * earlier year, and a click wrote no file version.
 */
#[AllowMockObjectsWithoutExpectations]
class DocumentConfirmControllerTest extends TestCase {
    private function controller(RequiredDocumentService $required, bool $instructor = false, ?VideoProgressService $service = null): VideoProgressController {
        $document = new CourseVideo();
        $document->setId(31);
        $document->setCourseId(7);
        $document->setSourceType('document');
        $document->setVideoRef('12');
        $videos = $this->createMock(CourseVideoMapper::class);
        $videos->method('findById')->willReturn($document);
        $videos->method('findByCourse')->willReturn([$document]);
        $course = new Course();
        $course->setId(7);
        $courseService = $this->createMock(CourseService::class);
        $courseService->method('assertEnrolledInCourse')->willReturn($course);
        $courseService->method('canManageCourse')->with(7, 'anna')->willReturn($instructor);
        // a confirmation of last year in the old table
        $old = new VideoProgress();
        $old->setCompletedAt(1_700_000_000);
        $progress = $this->createMock(VideoProgressMapper::class);
        $progress->method('findByUserAndContent')->willReturn($old);

        return new VideoProgressController(
            'learning',
            $this->createMock(IRequest::class),
            $service ?? $this->createMock(VideoProgressService::class),
            $progress,
            $videos,
            $courseService,
            'anna',
            $this->createMock(CourseDocumentMapper::class),
            $required,
        );
    }

    public function testStatusShowsAChangedDocumentAsNotConfirmed(): void {
        $required = $this->createMock(RequiredDocumentService::class);
        $required->method('statesForUser')->willReturn([31 => ['confirmed' => false, 'updated' => true, 'again' => false, 'confirmed_at' => 1_700_000_000]]);

        $row = $this->controller($required)->courseStatus(7)->getData()['videos'][0];

        $this->assertFalse($row['completed']);
        $this->assertTrue($row['updated']);
        $this->assertFalse($row['confirm_again']);
    }

    public function testConfirmGoesThroughTheRule(): void {
        $required = $this->createMock(RequiredDocumentService::class);
        $required->expects($this->once())->method('confirm')->with($this->isInstanceOf(CourseVideo::class), 'anna')
            ->willReturn(['confirmed' => true, 'updated' => false, 'again' => false, 'confirmed_at' => 5]);

        $data = $this->controller($required)->documentRead(31)->getData();

        $this->assertTrue($data['completed']);
    }

    /** GitHub #75 (review M1): a file nobody can open is answered with 409 and a code the page reads. */
    public function testAnUnreadableFileIsAConflict(): void {
        $required = $this->createMock(RequiredDocumentService::class);
        $required->method('confirm')->willThrowException(new DocumentUnavailableException('gone'));

        $response = $this->controller($required)->documentRead(31);

        $this->assertSame(409, $response->getStatus());
        $this->assertSame('document_unavailable', $response->getData()['code']);
    }

    /**
     * Fresh-eyes C5: an instructor previewing his course in the student view confirmed a required
     * document, which wrote a confirmation and a compliance event under his name.
     */
    public function testAnInstructorOfTheCourseConfirmsNothing(): void {
        $required = $this->createMock(RequiredDocumentService::class);
        $required->expects($this->never())->method('confirm');

        $response = $this->controller($required, true)->documentRead(31);

        $this->assertSame(403, $response->getStatus());
    }

    public function testAnInstructorOfTheCourseRecordsNoVideoProgress(): void {
        $service = $this->createMock(VideoProgressService::class);
        $service->expects($this->never())->method('recordHeartbeat');
        $service->expects($this->never())->method('markComplete');
        $controller = $this->controller($this->createMock(RequiredDocumentService::class), true, $service);

        $this->assertSame(403, $controller->heartbeat(31, 0.0, 30.0)->getStatus());
        $this->assertSame(403, $controller->complete(31)->getStatus());
    }
}
