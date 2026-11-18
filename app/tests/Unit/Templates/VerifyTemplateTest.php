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
        // the proof text points at the signing instance, not at the course-set name above it
        $this->assertStringContainsString('unter „Signiert von“ genannten Nextcloud digital signiert', $html);
        $this->assertStringNotContainsString('vom oben genannten Aussteller', $html);
    }

    public function testNoLineWithoutASigningHost(): void {
        $html = $this->render(['signed_by' => ''] + $this->valid());
        $this->assertStringNotContainsString('class="lrn-verify__signed-by"', $html);
        // GitHub #77 (review L3): the proof text pointed at a "Signiert von" line that is not there
        $this->assertStringNotContainsString('„Signiert von“', $html);
        $this->assertStringContainsString('Bestätigt: Dieses Zertifikat wurde von der Nextcloud, die es ausgestellt hat, digital signiert und ist unverändert. Der Organisationsname wird im Kurs festgelegt.', $html);
    }

    public function testTheHostIsEscaped(): void {
        $html = $this->render(['signed_by' => '<b>x</b>'] + $this->valid());
        $this->assertStringNotContainsString('<b>x</b>', $html);
    }

    public function testAnUnknownIdShowsNoSigner(): void {
        $html = $this->render(['status' => 'unknown', 'issuer_logo' => '']);
        $this->assertStringNotContainsString('class="lrn-verify__signed-by"', $html);
    }

    /** The page's own <style> block, comments removed. */
    private function style(string $html): string {
        $this->assertSame(1, preg_match('~<style>(.*?)</style>~s', $html, $m));
        return (string)preg_replace('~/\*.*?\*/~s', '', $m[1]);
    }

    /**
     * GitHub #91: Nextcloud's public layout makes #content a fixed box of the body height with
     * overflow: clip; the page could not be scrolled. The page is its own scroll container now and
     * wraps everything the template renders.
     */
    public function testThePageIsItsOwnScrollContainer(): void {
        foreach ([$this->valid(), ['status' => 'unknown', 'issuer_logo' => ''], ['status' => 'anonymized']] as $params) {
            $html = $this->render($params);
            $this->assertSame(1, preg_match('~\.lrn-verify-page\s*\{([^}]*)\}~', $this->style($html), $rule));
            $this->assertMatchesRegularExpression('~overflow-y:\s*auto~', $rule[1]);
            $this->assertMatchesRegularExpression('~min-height:\s*0~', $rule[1]);
            $this->assertMatchesRegularExpression('~background-color:\s*var\(--color-main-background\)~', $rule[1]);
            $body = substr($html, (int)strpos($html, '</style>') + 8);
            $this->assertStringStartsWith('<div class="lrn-verify-page">', ltrim($body));
            $this->assertStringEndsWith('</div>', rtrim($body));
        }
    }

    /**
     * GitHub #91: fixed text colours (#1a3a5c, #555, #777) and light box backgrounds read as blue on
     * blue or white on white with a theme colour behind the page or in dark mode. Apart from the
     * banner (a fixed background with fixed white text) every colour is a theme variable.
     */
    public function testEveryColourOutsideTheBannerIsAThemeVariable(): void {
        foreach ([$this->valid(), ['status' => 'expired', 'expires_at' => 1700000000] + $this->valid(), ['status' => 'unknown', 'issuer_logo' => '']] as $params) {
            $html = $this->render($params);
            preg_match_all('~([^{}]+)\{([^}]*)\}~', $this->style($html), $rules, PREG_SET_ORDER);
            foreach ($rules as [, $selector, $body]) {
                if (str_contains($selector, '__banner')) {
                    continue;
                }
                $this->assertDoesNotMatchRegularExpression('~#[0-9a-fA-F]{3,8}\b|\brgba?\(~', $body, trim($selector));
            }
            // no inline colour outside the banner either (the badge had one: #2e7d32 on dark is 3.5:1)
            $withoutBanner = (string)preg_replace('~<div class="lrn-verify__banner"[^>]*>~', '', $html);
            $this->assertDoesNotMatchRegularExpression('~style="[^"]*colou?r~', $withoutBanner);
        }
    }

    public function testOnlyAValidCertificateGetsTheSuccessBadge(): void {
        $this->assertStringContainsString('class="lrn-verify__badge lrn-verify__badge--valid"', $this->render($this->valid()));
        $expired = $this->render(['status' => 'expired', 'expires_at' => 1700000000] + $this->valid());
        $this->assertStringContainsString('class="lrn-verify__badge"', $expired);
        $this->assertStringNotContainsString('class="lrn-verify__badge lrn-verify__badge--valid"', $expired);
    }
}
