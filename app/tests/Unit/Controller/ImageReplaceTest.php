<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\ImageController;
use OCA\Learning\Db\Question;
use OCA\Learning\Service\ImageService;
use OCA\Learning\Service\QuestionService;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 2026-11 audit #140: replacing a question's image uploaded a new file and repointed image_path,
 * but left the old file in the uploader's Learning/images, using quota with nothing pointing at it.
 */
#[AllowMockObjectsWithoutExpectations]
class ImageReplaceTest extends TestCase {
    private function controller(?string $previous, ImageService $images, ?LoggerInterface $logger = null): ImageController {
        $request = $this->createMock(IRequest::class);
        $request->method('getUploadedFile')->with('image')->willReturn(
            ['name' => 'new.png', 'tmp_name' => '/tmp/x', 'size' => 10, 'error' => UPLOAD_ERR_OK]
        );
        $question = new Question();
        $question->setImagePath($previous);
        $questions = $this->createMock(QuestionService::class);
        $questions->method('findEntity')->willReturn($question);
        $questions->expects($this->once())->method('setImagePath')->with(8, 'author/Learning/images/q_new.png', 'author');

        return new ImageController('learning', $request, $images, $questions, 'author', $logger ?? $this->createMock(LoggerInterface::class));
    }

    public function testTheReplacedFileIsDeleted(): void {
        $images = $this->createMock(ImageService::class);
        $images->method('upload')->willReturn('author/Learning/images/q_new.png');
        $images->expects($this->once())->method('delete')->with('coauthor/Learning/images/q_old.png');

        $response = $this->controller('coauthor/Learning/images/q_old.png', $images)->upload(8);

        $this->assertSame(200, $response->getStatus());
    }

    public function testAFirstImageDeletesNothing(): void {
        $images = $this->createMock(ImageService::class);
        $images->method('upload')->willReturn('author/Learning/images/q_new.png');
        $images->expects($this->never())->method('delete');

        $this->assertSame(200, $this->controller(null, $images)->upload(8)->getStatus());
    }

    public function testAFailedCleanUpKeepsTheNewImageAndIsLogged(): void {
        $images = $this->createMock(ImageService::class);
        $images->method('upload')->willReturn('author/Learning/images/q_new.png');
        $images->method('delete')->willThrowException(new \RuntimeException('storage gone'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $response = $this->controller('coauthor/Learning/images/q_old.png', $images, $logger)->upload(8);

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(['image_path' => 'author/Learning/images/q_new.png'], $response->getData());
    }
}
