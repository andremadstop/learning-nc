<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Controller\SettingsController;
use OCA\Learning\Controller\TelosController;
use OCA\Learning\Service\AIService;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\LlmService;
use OCP\AppFramework\App;
use OCP\IAppConfig;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Review B1: LlmService, AIService and SettingsController now take the ConnectionStore; the real
 * container must still build them, and the transport must follow ai_connection, not ai_provider.
 */
final class LlmTransportWiringTest extends TestCase {
    private IAppConfig $appConfig;

    protected function setUp(): void {
        $this->appConfig = Server::get(IAppConfig::class);
        $this->clean();
    }

    protected function tearDown(): void {
        $this->clean();
    }

    private function clean(): void {
        foreach ([ConnectionStore::KEY, 'ai_provider', 'ai_enabled'] as $key) {
            $this->appConfig->deleteKey('learning', $key);
        }
    }

    public function testTheContainerBuildsTheTransport(): void {
        $this->assertInstanceOf(AIService::class, Server::get(AIService::class));
        $container = (new App('learning'))->getContainer();
        $this->assertInstanceOf(SettingsController::class, $container->get(SettingsController::class));
        $this->assertInstanceOf(TelosController::class, $container->get(TelosController::class));
    }

    public function testTheProviderComesFromTheSnapshot(): void {
        $this->appConfig->setValueString('learning', 'ai_provider', 'gemini');
        $this->appConfig->setValueString('learning', 'ai_enabled', 'yes');
        $this->appConfig->setValueString('learning', ConnectionStore::KEY, json_encode([
            'enabled' => true, 'provider' => 'ollama', 'baseUrl' => 'http://127.0.0.1:18080',
            'model' => 'm', 'allowInternalNetwork' => true, 'processingLocal' => true,
        ], JSON_THROW_ON_ERROR));

        $this->assertSame('ollama', Server::get(LlmService::class)->getProviderId());
    }
}
