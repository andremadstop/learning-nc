<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\CertificateController;
use OCA\Learning\Controller\CertificateReportController;
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
        ];
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
        ];
    }

    /** @param class-string $class */
    #[DataProvider('axiosActions')]
    public function testAxiosActionsKeepTheCsrfCheck(string $class, string $method): void {
        $doc = (string)(new \ReflectionMethod($class, $method))->getDocComment();
        $this->assertStringNotContainsString('@NoCSRFRequired', $doc);
    }
}
