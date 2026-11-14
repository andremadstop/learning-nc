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
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Audit #93: knowledge sources were deleted through DELETE …/knowledge/{title}; a title with '/'
 * never reached the controller. The title now travels as query parameter.
 */
#[AllowMockObjectsWithoutExpectations]
class RagImportControllerDeleteTest extends TestCase {
    private function controller(RagImportService $imports, bool $canManage = true): RagImportController {
        $courses = $this->createMock(CourseService::class);
        $courses->method('canManageCourse')->willReturn($canManage);
        return new RagImportController(
            'learning',
            $this->createMock(IRequest::class),
            $imports,
            $this->createMock(RagChunkMapper::class),
            $this->createMock(AuditService::class),
            $this->createMock(BadgeService::class),
            $this->createMock(LoggerInterface::class),
            'teacher',
            $courses
        );
    }

    public function testATitleWithASlashIsDeleted(): void {
        $imports = $this->createMock(RagImportService::class);
        $imports->expects($this->once())->method('deleteImported')->with(5, 'Kapitel 3 / Netzwerktopologien')->willReturn(4);

        $response = $this->controller($imports)->deleteImportedByQuery(5, 'Kapitel 3 / Netzwerktopologien');

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(['deleted' => 4], $response->getData());
    }

    public function testAnEmptyTitleDeletesNothing(): void {
        $imports = $this->createMock(RagImportService::class);
        $imports->expects($this->never())->method('deleteImported');

        $this->assertSame(400, $this->controller($imports)->deleteImportedByQuery(5, '  ')->getStatus());
    }

    public function testOnlyCourseManagersMayDelete(): void {
        $imports = $this->createMock(RagImportService::class);
        $imports->expects($this->never())->method('deleteImported');

        $this->assertSame(403, $this->controller($imports, false)->deleteImportedByQuery(5, 'a/b')->getStatus());
    }

    public function testTheQueryRouteExists(): void {
        $routes = require __DIR__ . '/../../../appinfo/routes.php';
        $match = array_filter($routes['routes'], static fn (array $r): bool =>
            $r['name'] === 'rag_import#deleteImportedByQuery'
            && $r['url'] === '/api/courses/{courseId}/knowledge'
            && $r['verb'] === 'DELETE');
        $this->assertCount(1, $match);
    }
}
