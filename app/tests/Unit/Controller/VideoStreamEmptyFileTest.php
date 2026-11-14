<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\VideoStreamController;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseVideo;
use OCA\Learning\Db\CourseVideoMapper;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\NcFilesVideoAdapter;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 2026-11 audit #67: an empty NC-Files video was answered with Content-Length: 1 and no body,
 * because parseRange() returns [0, 0] for size 0 and show() computed end - start + 1.
 */
#[AllowMockObjectsWithoutExpectations]
class VideoStreamEmptyFileTest extends TestCase {
    private function show(int $size, string $range = ''): \OCP\AppFramework\Http\Response {
        $video = new CourseVideo();
        $video->setCourseId(4);
        $video->setSourceType(NcFilesVideoAdapter::SOURCE_TYPE);
        $video->setVideoRef('Videos/clip.mp4');
        $videos = $this->createMock(CourseVideoMapper::class);
        $videos->method('findById')->willReturn($video);

        $course = new Course();
        $course->setInstructorId('teacher');
        $courses = $this->createMock(CourseService::class);
        $courses->method('assertEnrolledInCourse')->willReturn($course);

        $file = $this->createMock(File::class);
        $file->method('getSize')->willReturn($size);
        $file->method('getMimeType')->willReturn('video/mp4');
        $file->method('fopen')->willReturnCallback(function () use ($size) {
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, str_repeat('x', $size));
            rewind($stream);
            return $stream;
        });
        $folder = $this->createMock(Folder::class);
        $folder->method('get')->willReturn($file);
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($folder);

        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->willReturn($range);

        $controller = new VideoStreamController(
            'learning', $request, $videos, $courses, new NcFilesVideoAdapter(), $root,
            $this->createMock(LoggerInterface::class), 'student',
        );
        return $controller->show(9);
    }

    public function testAnEmptyFileDeclaresZeroBytes(): void {
        $response = $this->show(0);

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('0', $response->getHeaders()['Content-Length']);
    }

    public function testANonEmptyFileStillDeclaresItsFullLength(): void {
        $this->assertSame('10', $this->show(10)->getHeaders()['Content-Length']);
        $this->assertSame('4', $this->show(10, 'bytes=2-5')->getHeaders()['Content-Length']);
    }
}
