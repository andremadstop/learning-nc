<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\RagImportController;
use OCA\Learning\Db\RagChunkMapper;
use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\RagImportService;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 2026-11 audit #65: a knowledge-base upload PHP had rejected (upload_max_filesize) arrived with
 * tmp_name '' and size 0, passed the 5 MB check, and file_get_contents('') threw a ValueError that
 * catch (\Exception) did not cover → HTTP 500 instead of 400.
 */
#[AllowMockObjectsWithoutExpectations]
class RagImportUploadErrorTest extends TestCase {
    /** @param array<string, mixed> $file */
    private function importFile(array $file, ?RagImportService $service = null): \OCP\AppFramework\Http\DataResponse {
        $request = $this->createMock(IRequest::class);
        $request->method('getUploadedFile')->with('file')->willReturn($file);
        $courses = $this->createMock(CourseService::class);
        $courses->method('canManageCourse')->willReturn(true);

        $controller = new RagImportController(
            'learning', $request,
            $service ?? $this->createMock(RagImportService::class),
            $this->createMock(RagChunkMapper::class),
            $this->createMock(AuditService::class),
            $this->createMock(BadgeService::class),
            $this->createMock(LoggerInterface::class),
            'teacher', $courses,
        );
        return $controller->importFile(3);
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function rejectedUploads(): array {
        return [
            'over upload_max_filesize' => [UPLOAD_ERR_INI_SIZE, 'File too large (max 5 MB)'],
            'over MAX_FILE_SIZE' => [UPLOAD_ERR_FORM_SIZE, 'File too large (max 5 MB)'],
            'partial upload' => [UPLOAD_ERR_PARTIAL, 'No file uploaded or upload error'],
            'no file' => [UPLOAD_ERR_NO_FILE, 'No file uploaded or upload error'],
        ];
    }

    #[DataProvider('rejectedUploads')]
    public function testARejectedUploadIsABadRequest(int $error, string $message): void {
        $response = $this->importFile(['name' => 'notes.md', 'tmp_name' => '', 'size' => 0, 'error' => $error]);

        $this->assertSame(400, $response->getStatus());
        $this->assertSame(['error' => $message], $response->getData());
    }

    public function testAGoodUploadIsStillImported(): void {
        $tmp = tempnam(sys_get_temp_dir(), 'rag');
        file_put_contents($tmp, str_repeat('Subnetting erklärt. ', 10));
        $service = $this->createMock(RagImportService::class);
        $service->expects($this->once())->method('importText')
            ->with(3, 'notes.md', str_repeat('Subnetting erklärt. ', 10))
            ->willReturn(['chunks' => 1, 'tokens' => 50, 'title' => 'notes.md']);

        try {
            $response = $this->importFile(['name' => 'notes.md', 'tmp_name' => $tmp, 'size' => 200, 'error' => UPLOAD_ERR_OK], $service);
        } finally {
            @unlink($tmp);
        }

        $this->assertSame(200, $response->getStatus());
    }
}
