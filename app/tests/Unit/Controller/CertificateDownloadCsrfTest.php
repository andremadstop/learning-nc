<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\ClassbookController;
use OCA\Learning\Controller\CertificateController;
use OCA\Learning\Controller\CertificateReportController;
use OCA\Learning\Controller\CourseCertificateController;
use OCA\Learning\Controller\CourseController;
use OCA\Learning\Controller\DataExportController;
use OCA\Learning\Controller\ExportController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #41: "Download certificate" answered "Access forbidden – CSRF check failed".
 *
 * The certificate download and the instructor's certificate-report CSV are opened by plain browser
 * navigation (window.location.href), which carries no request token. Both are read-only GETs that
 * authorize in the method (owner-scoped mapper query / instructor gate), so they must be exempt from
 * the framework CSRF check — and nothing else in these controllers may be: list/show/revoke go
 * through axios and keep it. Annotations are read from the PHPDoc (the attribute form is not
 * honoured in this NC build), so the docblock is what the middleware sees.
 */
class CertificateDownloadCsrfTest extends TestCase {
    /** @return array<string, array{0: class-string, 1: string}> */
    public static function navigationDownloads(): array {
        return [
            'own certificate download' => [CertificateController::class, 'download'],
            'certificate report CSV' => [CertificateReportController::class, 'exportCertReportCsv'],
            'instructor copy of a certificate (#42)' => [CourseCertificateController::class, 'download'],
            'instructor ZIP of the report (#42)' => [CourseCertificateController::class, 'exportZip'],
            'at-risk CSV on the same Participants tab (#45)' => [CourseController::class, 'exportAtRiskCsv'],
            'classbook vCard on the same Participants tab (#45)' => [ClassbookController::class, 'exportVcard'],
            // Persona sweep (#36): the same 412 on "Download my data" and on the pool exports.
            'own data export, personal settings (#36)' => [DataExportController::class, 'myData'],
            'pool CSV export, pool window (#36)' => [ExportController::class, 'exportCsv'],
            'pool JSON export, pool window (#36)' => [ExportController::class, 'exportJson'],
        ];
    }

    /**
     * The exemption is only harmless on a GET: a CSRF-exempt route that writes could be triggered
     * from a foreign page. Every navigation download must be routed as GET only.
     *
     * @param class-string $class
     */
    #[DataProvider('navigationDownloads')]
    public function testNavigationDownloadIsRoutedAsGetOnly(string $class, string $method): void {
        $routes = require __DIR__ . '/../../../appinfo/routes.php';
        $short = (new \ReflectionClass($class))->getShortName();
        $name = lcfirst(substr($short, 0, -strlen('Controller'))) . '#' . $method;
        $verbs = [];
        foreach ($routes['routes'] as $route) {
            if ($route['name'] === $name) {
                $verbs[] = $route['verb'] ?? 'GET';
            }
        }
        $this->assertNotSame([], $verbs, "route $name must exist");
        $this->assertSame(['GET'], array_values(array_unique($verbs)), "route $name must be GET only");
    }

    /** @param class-string $class */
    #[DataProvider('navigationDownloads')]
    public function testNavigationDownloadNeedsNoCsrfTokenButStaysAuthenticated(string $class, string $method): void {
        $doc = (string)(new \ReflectionMethod($class, $method))->getDocComment();
        $this->assertMatchesRegularExpression('/^\s*\*\s*@NoCSRFRequired\s*$/m', $doc);
        $this->assertMatchesRegularExpression('/^\s*\*\s*@NoAdminRequired\s*$/m', $doc);
        // Never public: the server must still know who asks (owner / instructor check).
        $this->assertStringNotContainsString('@PublicPage', $doc);
    }

    /** @return array<string, array{0: class-string, 1: string}> */
    public static function axiosActions(): array {
        return [
            'list' => [CertificateController::class, 'index'],
            'show' => [CertificateController::class, 'show'],
            'revoke (writes)' => [CertificateController::class, 'revoke'],
            'report' => [CertificateReportController::class, 'certReport'],
            'group report' => [CertificateReportController::class, 'groupReport'],
            'remind (writes)' => [CertificateReportController::class, 'remindMember'],
            'instructor view (#42)' => [CourseCertificateController::class, 'show'],
            'at-risk list (#45)' => [CourseController::class, 'atRisk'],
        ];
    }

    /** @param class-string $class */
    #[DataProvider('axiosActions')]
    public function testAxiosActionsKeepTheCsrfCheck(string $class, string $method): void {
        $doc = (string)(new \ReflectionMethod($class, $method))->getDocComment();
        $this->assertStringNotContainsString('@NoCSRFRequired', $doc);
    }
}
