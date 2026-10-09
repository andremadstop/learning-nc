<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\Llm\ApiKeyStore;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\LlmConnection;
use OCA\Learning\Service\LlmService;
use OCA\Learning\Tests\Support\FakeAppConfigValues;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Task B4: LlmService no longer sends anything (LlmGateway does). It is the thin answer to "is AI
 * usable right now" for the UI: provider from the connection snapshot (never from the legacy keys
 * ai_provider / ai_ollama_*), and available only for a backend 5.7.0 routes that has what it
 * needs — Gemini a key for exactly this endpoint, Ollama nothing.
 */
class LlmServiceSnapshotTest extends TestCase {
    private FakeAppConfigValues $values;
    private ApiKeyStore&MockObject $keys;

    protected function setUp(): void {
        $this->values = new FakeAppConfigValues();
        $this->keys = $this->createMock(ApiKeyStore::class);
    }

    private function store(): ConnectionStore {
        $appConfig = $this->createMock(IAppConfig::class);
        $this->values->wire($appConfig);
        return new ConnectionStore($appConfig, $this->createMock(AuditService::class), $this->createMock(LoggerInterface::class));
    }

    private function service(): LlmService {
        return new LlmService($this->store(), $this->keys);
    }

    /** @param array<string, mixed> $c */
    private function snapshot(array $c): void {
        $this->values->set('learning', ConnectionStore::KEY, json_encode($c, JSON_THROW_ON_ERROR));
    }

    private function legacyOllama(string $url): void {
        $this->values->set('learning', 'ai_provider', 'ollama');
        $this->values->set('learning', 'ai_enabled', 'yes');
        $this->values->set('learning', 'ai_ollama_url', $url);
        $this->values->set('learning', 'ai_ollama_model', 'legacy-model');
    }

    public function testTheTransportMethodIsGone(): void {
        $this->assertFalse(method_exists(LlmService::class, 'generateText'));
    }

    public function testAGeminiSnapshotWinsOverTheLegacyKeys(): void {
        $this->snapshot(ConnectionStore::legacyGemini(true)->toArray());
        $this->legacyOllama('http://ollama-b.lan:11434');
        $this->keys->method('get')->willReturn('k');

        $service = $this->service();
        $this->assertSame('gemini', $service->getProviderId());
        $this->assertTrue($service->isAvailable());
    }

    public function testGeminiWithoutAKeyForThisEndpointIsNotAvailable(): void {
        $this->snapshot(ConnectionStore::legacyGemini(true)->toArray());
        $this->keys->method('get')->willReturnCallback(static fn (LlmConnection $c) => null);

        $this->assertFalse($this->service()->isAvailable());
    }

    public function testOllamaNeedsNoKey(): void {
        $this->snapshot(['enabled' => true, 'provider' => 'ollama', 'baseUrl' => 'http://ollama-a.lan:11434',
            'model' => 'model-a', 'allowInternalNetwork' => true, 'processingLocal' => true]);
        $this->legacyOllama('http://ollama-b.lan:11434');
        $this->keys->expects($this->never())->method('get');

        $service = $this->service();
        $this->assertSame('ollama', $service->getProviderId());
        $this->assertTrue($service->isAvailable());
    }

    public function testAnEmptyLegacyProviderIsDisabledNotGemini(): void {
        $this->values->set('learning', 'ai_provider', '');
        $this->values->set('learning', 'ai_enabled', 'yes');
        $this->keys->method('get')->willReturn('k');

        $service = $this->service();
        $this->assertSame('disabled', $service->getProviderId());
        $this->assertFalse($service->isEnabled());
        $this->assertFalse($service->isAvailable());
    }

    public function testASwitchedOffConnectionIsNotAvailable(): void {
        $this->snapshot(ConnectionStore::legacyGemini(false)->toArray());
        $this->keys->method('get')->willReturn('k');

        $this->assertFalse($this->service()->isAvailable());
    }
}
