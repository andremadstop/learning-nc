<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\PageController;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/** 5.7.0 D6: the public privacy page names the AI recipient that is in force right now. */
class PageControllerPrivacyTest extends TestCase {
    private function controller(ConnectionStore $store): PageController {
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('linkToRoute')->willReturnCallback(static fn (string $route): string => '/' . $route);
        return new PageController('learning', $this->createMock(IRequest::class), $urls, $store);
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
