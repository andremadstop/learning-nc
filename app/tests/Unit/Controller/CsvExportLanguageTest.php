<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\CertificateReportController;
use OCA\Learning\Controller\CourseController;
use OCA\Learning\Controller\SummaryController;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Service\CertificateReportService;
use OCA\Learning\Service\CourseArchiveService;
use OCA\Learning\Service\CourseEnrollmentService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\CourseSummaryService;
use OCA\Learning\Service\ExamAttemptPolicy;
use OCA\Learning\Service\KeyService;
use OCA\Learning\Service\PassCriteriaService;
use OCA\Learning\Service\RoleService;
use OCA\Learning\Service\ScheduleService;
use OCA\Learning\Service\TelosService;
use OCA\Learning\Service\XpService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #48 (CSV part): the certificate report had German headers and the status values
 * "abgelaufen"/"gültig", the at-risk list German reason sentences, and both reports fixed
 * headers in one language. They are written in the exporting user's language now, from the
 * German source keys of the catalogue, with a UTF-8 BOM so spreadsheets read the encoding.
 */
#[AllowMockObjectsWithoutExpectations]
class CsvExportLanguageTest extends TestCase {
    private const EN = [
        'Name (Person)' => 'Name',
        'Bestanden am' => 'Passed on',
        'Ergebnis' => 'Result',
        'Gültig bis' => 'Valid until',
        'Verifizierungs-ID' => 'Verification ID',
        'Status' => 'Status',
        'Abgelaufen (Zertifikatsstatus)' => 'Expired',
        'Gültig (Zertifikatsstatus)' => 'Valid',
        'Risikostufe' => 'Risk level',
        'Kritische Karten' => 'Critical cards',
        'Risikogründe' => 'Risk reasons',
        'Trefferquote' => 'Accuracy',
        'Zuletzt aktiv' => 'Last active',
        'Hohes Risiko' => 'High risk',
        'Mittleres Risiko' => 'Medium risk',
        'Inaktiv seit {n} Tagen' => 'Inactive for {n} days',
        'Tage bis Fristende: {n}' => 'Days until deadline: {n}',
        'Noch nicht bestanden' => 'Not passed yet',
        'Trefferquote: %s' => 'Accuracy: %s',
        'Karten in Box 1: %s' => 'Cards in box 1: %s',
        'Benutzer-ID' => 'User ID',
        'Beherrschung' => 'Mastery',
        'Gewonnene Duelle' => 'Duels won',
        'Abzeichen' => 'Badges',
        'Schwarm-Beiträge' => 'Swarm Contributions',
        'Letztes Zertifikat' => 'Last certificate',
        'Pflicht-Pools bestanden' => 'Required pools passed',
        'Pflicht-Pools gesamt' => 'Required pools total',
        'Pflichtinhalte bestätigt' => 'Required content confirmed',
        'Pflichtinhalte gesamt' => 'Required content total',
        'Aktuell (Abdeckungsstatus)' => 'Current',
        'Nie bestanden (Abdeckungsstatus)' => 'Never passed',
        'Abgelaufen, in Kulanzzeit (Abdeckungsstatus)' => 'Expired, in grace period',
    ];

    private function english(): IL10N {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(
            static fn (string $text, $params = []): string => vsprintf(self::EN[$text] ?? $text, (array)$params)
        );
        return $l;
    }

    /** @return list<list<string>> */
    private function rows(string $csv): array {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'UTF-8 BOM for spreadsheets');
        $lines = explode("\n", trim(substr($csv, 3)));
        return array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), $lines);
    }

    public function testTheCertificateReportIsInTheExportersLanguage(): void {
        $report = $this->createMock(CertificateReportService::class);
        $report->method('getCourseReport')->willReturn(['rows' => [
            ['display_name' => 'Anna', 'passed_at' => 1700000000, 'score' => 90, 'expires_at' => null, 'verification_id' => 'v1', 'expired' => false],
            ['display_name' => 'Ben', 'passed_at' => 1600000000, 'score' => 80, 'expires_at' => 1650000000, 'verification_id' => 'v2', 'expired' => true],
        ]]);
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturn(null);
        $controller = new CertificateReportController('learning', $request, $report,
            $this->createMock(KeyService::class), $this->createMock(LoggerInterface::class), 'teacher', $this->english());

        $response = $controller->exportCertReportCsv(7);
        $rows = $this->rows($response->render());

        $this->assertSame(['Name', 'Passed on', 'Result (%)', 'Valid until', 'Verification ID', 'Status'], $rows[0]);
        $this->assertSame('Valid', $rows[1][5]);
        $this->assertSame('Expired', $rows[2][5]);
        $this->assertStringContainsString('charset=utf-8', $response->getHeaders()['Content-Type']);
    }

    /**
     * Lückencheck B2: the coverage CSV is the list the table shows (same service call, the status
     * filter forwarded) in the exporter's language; the required pools are two numeric columns.
     */
    public function testTheCoverageReportIsInTheExportersLanguage(): void {
        $report = $this->createMock(CertificateReportService::class);
        $report->expects($this->once())->method('getCoverageReport')->with(7, 'teacher', 'expired')->willReturn(['rows' => [
            ['member_ref' => 'r1', 'display_name' => 'Anna', 'status' => 'current', 'last_certificate_at' => 1700000000, 'expires_at' => null, 'required_pools_passed' => 2, 'required_pools_total' => 2, 'required_content_confirmed' => 3, 'required_content_total' => 3],
            ['member_ref' => 'r2', 'display_name' => 'Ben', 'status' => 'never_passed', 'last_certificate_at' => null, 'expires_at' => null, 'required_pools_passed' => 1, 'required_pools_total' => 2, 'required_content_confirmed' => 1, 'required_content_total' => 3],
            ['member_ref' => 'r3', 'display_name' => 'Cleo', 'status' => 'expired', 'last_certificate_at' => 1600000000, 'expires_at' => 1650000000, 'required_pools_passed' => 0, 'required_pools_total' => 0, 'required_content_confirmed' => 0, 'required_content_total' => 0],
        ]]);
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(static fn (string $key) => $key === 'status' ? 'expired' : null);
        $controller = new CertificateReportController('learning', $request, $report,
            $this->createMock(KeyService::class), $this->createMock(LoggerInterface::class), 'teacher', $this->english());

        $response = $controller->exportCoverageCsv(7);
        $rows = $this->rows($response->render());

        $this->assertSame(['Name', 'Status', 'Last certificate', 'Valid until', 'Required pools passed', 'Required pools total', 'Required content confirmed', 'Required content total'], $rows[0]);
        $this->assertSame(['Anna', 'Current', '2023-11-14', '', '2', '2', '3', '3'], $rows[1]);
        $this->assertSame(['Ben', 'Never passed', '', '', '1', '2', '1', '3'], $rows[2]);
        $this->assertSame(['Cleo', 'Expired, in grace period', '2020-09-13', '2022-04-15', '', '', '', ''], $rows[3]);
        $this->assertStringContainsString('charset=utf-8', $response->getHeaders()['Content-Type']);
    }

    public function testTheAtRiskListIsInTheExportersLanguageFromTheReasonCodes(): void {
        $courseService = $this->createMock(CourseService::class);
        $courseService->method('getAtRiskStudents')->willReturn(['at_risk' => [[
            'user_id' => 'anna',
            'display_name' => 'Anna',
            'risk_level' => 'medium',
            'critical_cards_count' => 0,
            'risk_reasons' => ['Inaktiv seit 9 Tagen', 'Accuracy 30%', '70% der Karten in Box 1'],
            'risk_reason_codes' => [
                ['code' => 'inactive_days', 'n' => 9],
                ['code' => 'low_accuracy', 'n' => 30],
                ['code' => 'box_one_share', 'n' => 70],
            ],
            'accuracy' => 30,
            'last_active' => '2026-11-01',
        ]]]);
        $controller = new CourseController('learning', $this->createMock(IRequest::class),
            $this->createMock(CourseMapper::class), $this->createMock(CourseMemberMapper::class),
            $this->createMock(CourseArchiveService::class), $courseService, $this->createMock(RoleService::class),
            $this->createMock(ScheduleService::class), $this->createMock(PassCriteriaService::class),
            $this->createMock(ExamAttemptPolicy::class), $this->createMock(CourseEnrollmentService::class),
            $this->createMock(LoggerInterface::class), 'teacher', $this->english());

        $response = $controller->exportAtRiskCsv(7);
        $rows = $this->rows($response->render());

        $this->assertSame(['Name', 'Risk level', 'Critical cards', 'Risk reasons', 'Accuracy (%)', 'Last active'], $rows[0]);
        $this->assertSame('Medium risk', $rows[1][1]);
        $this->assertSame('Inactive for 9 days; Accuracy: 30 %; Cards in box 1: 70 %', $rows[1][3]);
        $this->assertStringContainsString('charset=utf-8', $response->getHeaders()['Content-Type']);
    }

    /**
     * 5.13.0 review: "Frist endet in {n} Tagen" read "in 1 Tagen" / "in 1 days" for a deadline
     * tomorrow and had no form for n = 0; "label: n" needs no plural form in any language.
     */
    public function testTheDeadlineReasonNeedsNoPluralForm(): void {
        $csv = new \OCA\Learning\Service\CsvExportL10n($this->english());
        $this->assertSame('Not passed yet; Days until deadline: 1', $csv->riskReasons(['risk_reason_codes' => [
            ['code' => 'not_passed'],
            ['code' => 'deadline_days', 'n' => 1],
        ]]));
        $this->assertSame('Days until deadline: 0', $csv->riskReasons(['risk_reason_codes' => [
            ['code' => 'deadline_days', 'n' => 0],
        ]]));
    }

    /** @param array<string, array<string, mixed>> $students */
    private function classSummary(bool $gamification, array $students = ['anna' => []]): SummaryController {
        $courseService = $this->createMock(CourseService::class);
        $courseService->method('findById')->willReturn(['is_instructor' => true]);
        $summary = $this->createMock(CourseSummaryService::class);
        $summary->method('getClassSummary')->willReturn(['students' => $students]);
        $xp = $this->createMock(XpService::class);
        $xp->method('isGamificationEnabled')->willReturn($gamification);
        return new SummaryController('learning', $this->createMock(IRequest::class), $summary, $courseService,
            $this->createMock(TelosService::class), $xp, 'teacher', $this->english());
    }

    public function testTheClassSummaryHeadersAreInTheExportersLanguage(): void {
        $controller = $this->classSummary(true);

        $rows = $this->rows($controller->exportCsv(7)->render());

        $this->assertSame('User ID', $rows[0][0]);
        $this->assertSame('Accuracy (%)', $rows[0][3]);
        $this->assertSame('Mastery (%)', $rows[0][4]);
        $this->assertSame('Duels won', $rows[0][8]);
    }

    /** A student with a value in every column of the class summary. */
    private const FULL_STUDENT = [
        'xp' => ['total_xp' => 120, 'current_level' => 3],
        'sessions' => ['overall_accuracy' => 82.5, 'total_sessions' => 9],
        'mastery' => ['mastery_rate' => 40],
        'streak' => ['current_streak' => 4],
        'badges' => ['first', 'second'],
        'duels' => ['wins' => 2],
        'swarm' => ['approved_contributions' => 1],
    ];

    /**
     * GitHub #79 (comment 18.11.): without gamification the class summary CSV still had XP, level,
     * streak, badges, duels won and swarm contributions — frozen old values or zero.
     */
    public function testWithoutGamificationTheClassSummaryHasOnlyTheLearningColumns(): void {
        $rows = $this->rows($this->classSummary(false, ['anna' => self::FULL_STUDENT])->exportCsv(7)->render());

        $this->assertSame(['User ID', 'Accuracy (%)', 'Mastery (%)', 'Sessions'], $rows[0]);
        $this->assertSame(['anna', '82.5', '40', '9'], $rows[1]);
    }

    public function testWithGamificationTheClassSummaryKeepsEveryColumn(): void {
        $rows = $this->rows($this->classSummary(true, ['anna' => self::FULL_STUDENT])->exportCsv(7)->render());

        $this->assertSame(['User ID', 'XP', 'Level', 'Accuracy (%)', 'Mastery (%)', 'Sessions', 'Streak', 'Badges', 'Duels won', 'Swarm Contributions'], $rows[0]);
        $this->assertSame(['anna', '120', '3', '82.5', '40', '9', '4', '2', '2', '1'], $rows[1]);
    }

    public function testEveryRowIsAsWideAsTheHeaderInBothModes(): void {
        foreach ([true, false] as $gamification) {
            $rows = $this->rows($this->classSummary($gamification, ['anna' => self::FULL_STUDENT, 'bob' => []])->exportCsv(7)->render());
            $this->assertCount(3, $rows);
            foreach ($rows as $row) {
                $this->assertCount(count($rows[0]), $row, 'gamification ' . ($gamification ? 'on' : 'off'));
            }
        }
    }
}
