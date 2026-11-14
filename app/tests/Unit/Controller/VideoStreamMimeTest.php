<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\VideoStreamController;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseVideo;
use OCA\Learning\Db\CourseVideoMapper;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\NcFilesVideoAdapter;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Audit #15: the stream endpoint served any file an nc-files row pointed at in the course owner's
 * home, whatever its type, to every course member. Rows written before the registry check, or
 * edited in the database, must not get a document out either: only video/* is streamed.
 */
#[AllowMockObjectsWithoutExpectations]
class VideoStreamMimeTest extends TestCase {
    private function controller(string $mime, ?File &$file = null): VideoStreamController {
        $video = new CourseVideo();
        $video->setId(3);
        $video->setCourseId(5);
        $video->setSourceType('nc-files');
        $video->setVideoRef('Documents/payslip.pdf');
        $videos = $this->createMock(CourseVideoMapper::class);
        $videos->method('findById')->willReturn($video);

        $course = new Course();
        $course->setId(5);
        $course->setInstructorId('owner');
        $courses = $this->createMock(CourseService::class);
        $courses->method('assertEnrolledInCourse')->willReturn($course);

        $file = $this->createMock(File::class);
        $file->method('getMimeType')->willReturn($mime);
        $file->method('getSize')->willReturn(4);
        $file->method('fopen')->willReturnCallback(static fn () => fopen('php://memory', 'r'));
        $folder = $this->createMock(Folder::class);
        $folder->method('get')->willReturn($file);
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->with('owner')->willReturn($folder);

        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->willReturn('');

        return new VideoStreamController('learning', $request, $videos, $courses, new NcFilesVideoAdapter(),
            $root, $this->createMock(LoggerInterface::class), 'member');
    }

    public function testADocumentInTheOwnersHomeIsNotStreamed(): void {
        $file = null;
        $controller = $this->controller('application/pdf', $file);
        $file->expects($this->never())->method('fopen');

        $this->assertSame(Http::STATUS_NOT_FOUND, $controller->show(3)->getStatus());
    }

    public function testAVideoIsStreamed(): void {
        $response = $this->controller('video/mp4')->show(3);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('video/mp4', $response->getHeaders()['Content-Type']);
    }
}
