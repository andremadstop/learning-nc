<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\CertificateReportController;
use OCA\Learning\Service\CertificateReportService;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\KeyService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Lückencheck B2: GET /api/courses/{id}/cert-coverage — the JSON side of the coverage list.
 * The CSV side is covered in CsvExportLanguageTest and CertificateDownloadCsrfTest.
 */
#[AllowMockObjectsWithoutExpectations]
class CoverageReportControllerTest extends TestCase {
    private function controller(CertificateReportService $report, ?string $userId, ?string $status = null): CertificateReportController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(static fn (string $key) => $key === 'status' ? $status : null);
        return new CertificateReportController('learning', $request, $report,
            $this->createMock(KeyService::class), $this->createMock(LoggerInterface::class), $userId);
    }

    public function testWithoutASessionNothingIsRead(): void {
        $report = $this->createMock(CertificateReportService::class);
        $report->expects($this->never())->method('getCoverageReport');
        $this->assertSame(401, $this->controller($report, null)->coverageReport(7)->getStatus());
    }

    public function testAForeignInstructorGets403AndAMissingCourse404(): void {
        $report = $this->createMock(CertificateReportService::class);
        $report->method('getCoverageReport')->willThrowException(new ForbiddenException('No permission'));
        $this->assertSame(403, $this->controller($report, 'mallory')->coverageReport(7)->getStatus());

        $report = $this->createMock(CertificateReportService::class);
        $report->method('getCoverageReport')->willThrowException(new DoesNotExistException('no course'));
        $this->assertSame(404, $this->controller($report, 'teacher')->coverageReport(7)->getStatus());
    }

    public function testTheStatusFilterIsPassedOnAndAnEmptyOneMeansAll(): void {
        $rows = ['rows' => [['member_ref' => 'r1', 'display_name' => 'Ben', 'status' => 'overdue']]];
        $report = $this->createMock(CertificateReportService::class);
        $report->expects($this->exactly(2))->method('getCoverageReport')
            ->willReturnCallback(function (int $courseId, string $userId, ?string $status) use ($rows): array {
                static $calls = 0;
                $calls++;
                $this->assertSame([7, 'teacher'], [$courseId, $userId]);
                $this->assertSame($calls === 1 ? 'overdue' : null, $status);
                return $rows;
            });

        $response = $this->controller($report, 'teacher', 'overdue')->coverageReport(7);
        $this->assertSame(200, $response->getStatus());
        $this->assertSame($rows, $response->getData());
        $this->assertSame(200, $this->controller($report, 'teacher', '')->coverageReport(7)->getStatus());
    }
}
