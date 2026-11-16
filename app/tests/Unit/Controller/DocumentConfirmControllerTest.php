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
    private function controller(RequiredDocumentService $required): VideoProgressController {
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
        // a confirmation of last year in the old table
        $old = new VideoProgress();
        $old->setCompletedAt(1_700_000_000);
        $progress = $this->createMock(VideoProgressMapper::class);
        $progress->method('findByUserAndContent')->willReturn($old);

        return new VideoProgressController(
            'learning',
            $this->createMock(IRequest::class),
            $this->createMock(VideoProgressService::class),
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
}
