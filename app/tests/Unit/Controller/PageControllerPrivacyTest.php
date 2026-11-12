<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\PageController;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\OperatorInfo;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

/** 5.7.0 D6: the public privacy page names the AI recipient that is in force right now. */
#[AllowMockObjectsWithoutExpectations]
class PageControllerPrivacyTest extends TestCase {
    /** @var array<string, string> */
    private array $appValues = [];

    private function controller(ConnectionStore $store): PageController {
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('linkToRoute')->willReturnCallback(static fn (string $route): string => '/' . $route);
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, $default = '') => $this->appValues[$key] ?? $default
        );
        return new PageController('learning', $this->createMock(IRequest::class), $urls, $store, new OperatorInfo($config));
    }

    private function store(): ConnectionStore {
        $store = $this->createMock(ConnectionStore::class);
        $store->method('load')->willReturn(ConnectionStore::legacyGemini(false));
        return $store;
    }

    /**
     * Audit 2026-11 (high): both public pages named the app author's company as operator on every
     * installation. Without operator details they carry none; the page shows a neutral hint.
     */
    public function testWithoutOperatorDetailsBothPagesCarryNoOperator(): void {
        $controller = $this->controller($this->store());

        $this->assertArrayHasKey('operator', $controller->privacy()->getParams());
        $this->assertNull($controller->privacy()->getParams()['operator']);
        $this->assertArrayHasKey('operator', $controller->impressum()->getParams());
        $this->assertNull($controller->impressum()->getParams()['operator']);
    }

    public function testBothPagesCarryTheConfiguredOperator(): void {
        $this->appValues = [
            'operator_name' => 'Musterschule e. V.',
            'operator_address' => 'Schulweg 1, 12345 Musterstadt',
            'operator_email' => 'kontakt@musterschule.example',
        ];
        $expected = [
            'name' => 'Musterschule e. V.',
            'address' => 'Schulweg 1, 12345 Musterstadt',
            'register' => '',
            'email' => 'kontakt@musterschule.example',
            'content_responsible' => '',
        ];
        $controller = $this->controller($this->store());

        $this->assertSame($expected, $controller->privacy()->getParams()['operator']);
        $this->assertSame($expected, $controller->impressum()->getParams()['operator']);
    }

    public function testThePrivacyPageCarriesTheCurrentRecipient(): void {
        $store = $this->createMock(ConnectionStore::class);
        $store->method('load')->willReturn(ConnectionStore::legacyGemini(true));

        $params = $this->controller($store)->privacy()->getParams();

        $this->assertSame('/learning.page.index', $params['appUrl']);
        $this->assertSame('/learning.page.impressum', $params['imprintUrl']);
        $this->assertSame(['active' => true, 'provider' => 'Google Gemini', 'region' => 'us'], $params['aiNotice']);
    }

    public function testThePrivacyPageSaysAiIsOffWhenItIsOff(): void {
        $store = $this->createMock(ConnectionStore::class);
        $store->method('load')->willReturn(ConnectionStore::legacyGemini(false));

        $params = $this->controller($store)->privacy()->getParams();

        $this->assertFalse($params['aiNotice']['active']);
    }
}
