<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Templates;

use OCP\IL10N;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Support/TemplateFunctions.php';

/**
 * GitHub #77 (review): the organisation name on the public verify page is set per course, by any
 * manager of it. The page also names the instance whose key signed the certificate.
 */
class VerifyTemplateTest extends TestCase {
    /** @param array<string, mixed> $params */
    private function render(array $params): string {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(static fn (string $text, $args = []): string => vsprintf($text, (array)$args));
        // IL10N::l() is not in the OCP stub; the template falls back to date() for it
        $_ = $params;
        ob_start();
        try {
            include __DIR__ . '/../../../templates/verify.php';
        } finally {
            $html = (string)ob_get_clean();
        }
        return $html;
    }

    /** @return array<string, mixed> */
    private function valid(): array {
        return [
            'status' => 'valid',
            'issuer_logo' => '',
            'issuer_name' => 'Some Other Academy GmbH',
            'signed_by' => 'cloud.example.org',
            'course_title' => 'Brandschutz',
            'course_elements' => [],
            'issued_at' => 1700000000,
            'expires_at' => null,
            'verification_id' => '00000000-0000-4000-8000-000000000000',
        ];
    }

    public function testAValidCertificateNamesTheSigningInstance(): void {
        $html = $this->render($this->valid());
        $this->assertStringContainsString('Some Other Academy GmbH', $html);
        $this->assertStringContainsString('Signiert von: <span class="lrn-verify__ltr">cloud.example.org</span>', $html);
    }

    public function testNoLineWithoutASigningHost(): void {
        $html = $this->render(['signed_by' => ''] + $this->valid());
        $this->assertStringNotContainsString('Signiert von', $html);
    }

    public function testTheHostIsEscaped(): void {
        $html = $this->render(['signed_by' => '<b>x</b>'] + $this->valid());
        $this->assertStringNotContainsString('<b>x</b>', $html);
    }

    public function testAnUnknownIdShowsNoSigner(): void {
        $html = $this->render(['status' => 'unknown', 'issuer_logo' => '']);
        $this->assertStringNotContainsString('Signiert von', $html);
    }
}
