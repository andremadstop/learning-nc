<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\AIService;
use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\GeminiProvider;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\LlmService;
use OCA\Learning\Service\OllamaProvider;
use OCA\Learning\Tests\Support\FakeAppConfigValues;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Review B1 (BLOCKER): consent is checked against ConnectionStore::load(), so the transport must
 * send to exactly that recipient. Before B3 routes every call through the gateway snapshot, the
 * 5.5 transport (LlmService + GeminiProvider/OllamaProvider, AIService) takes provider, host and
 * model from the same snapshot and never from the legacy keys ai_provider / ai_ollama_*.
 */
class LlmServiceSnapshotTest extends TestCase {
    private FakeAppConfigValues $values;
    private GeminiProvider&MockObject $gemini;
    private OllamaProvider&MockObject $ollama;

    protected function setUp(): void {
        $this->values = new FakeAppConfigValues();
        $this->gemini = $this->createMock(GeminiProvider::class);
        $this->ollama = $this->createMock(OllamaProvider::class);
    }

    private function store(): ConnectionStore {
        $appConfig = $this->createMock(IAppConfig::class);
        $this->values->wire($appConfig);
        return new ConnectionStore($appConfig, $this->createMock(AuditService::class), $this->createMock(LoggerInterface::class));
    }

    private function service(): LlmService {
        return new LlmService($this->gemini, $this->ollama, $this->store());
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

    public function testAGeminiSnapshotNeverReachesOllamaWhateverTheLegacyKeysSay(): void {
        $this->snapshot(ConnectionStore::legacyGemini(true)->toArray());
        $this->legacyOllama('http://ollama-b.lan:11434');
        $this->gemini->method('isAvailable')->willReturn(true);
        $this->gemini->expects($this->once())->method('generateText')->with('prompt')->willReturn('answer');
        $this->ollama->expects($this->never())->method('generateText');
        $this->ollama->expects($this->never())->method('generateAt');

        $service = $this->service();
        $this->assertSame('gemini', $service->getProviderId());
        $this->assertSame('answer', $service->generateText('prompt'));
    }

    public function testTheOllamaHostAndModelComeFromTheSnapshot(): void {
        $this->snapshot(['enabled' => true, 'provider' => 'ollama', 'baseUrl' => 'http://ollama-a.lan:11434',
            'model' => 'model-a', 'allowInternalNetwork' => true, 'processingLocal' => true]);
        $this->legacyOllama('http://ollama-b.lan:11434');
        $this->ollama->expects($this->once())->method('generateAt')
            ->with('http://ollama-a.lan:11434', 'model-a', 'prompt', ['temperature' => 0.2])->willReturn('answer');
        $this->ollama->expects($this->never())->method('generateText');
        $this->gemini->expects($this->never())->method('generateText');

        $this->assertSame('answer', $this->service()->generateText('prompt', ['temperature' => 0.2]));
    }

    public function testAnEmptyLegacyProviderIsDisabledNotGemini(): void {
        $this->values->set('learning', 'ai_provider', '');
        $this->values->set('learning', 'ai_enabled', 'yes');
        $this->gemini->method('isAvailable')->willReturn(true);
        $this->gemini->expects($this->never())->method('generateText');

        $service = $this->service();
        $this->assertSame('disabled', $service->getProviderId());
        $this->assertFalse($service->isEnabled());
        $this->assertFalse($service->isAvailable());
        $this->expectException(\RuntimeException::class);
        $service->generateText('prompt');
    }

    public function testAGeminiSnapshotForAnotherEndpointIsRefused(): void {
        $this->snapshot(['enabled' => true, 'provider' => 'gemini', 'baseUrl' => 'https://gemini-proxy.example/v1beta',
            'model' => ConnectionStore::GEMINI_MODEL]);
        $this->gemini->method('isAvailable')->willReturn(true);
        $this->gemini->expects($this->never())->method('generateText');

        $service = $this->service();
        $this->assertFalse($service->isAvailable(), 'the 5.5 transport only reaches the fixed Gemini endpoint');
        $this->expectException(\RuntimeException::class);
        $service->generateText('prompt');
    }

    /**
     * AIService chose the Nextcloud TaskProcessing path from a raw empty ai_provider, even when
     * the snapshot (and so the consent) names Gemini.
     */
    public function testAiServiceFollowsTheSnapshotNotAnEmptyLegacyProvider(): void {
        $this->snapshot(ConnectionStore::legacyGemini(true)->toArray());
        $this->values->set('learning', 'ai_provider', '');
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, string $default = '') => $this->values->get($app, $key) ?? $default);
        $llm = $this->createMock(LlmService::class);
        $llm->method('isAvailable')->willReturn(true);
        $llm->expects($this->once())->method('generateText')->willReturn('answer');
        $cacheFactory = new class implements ICacheFactory {
            public function createDistributed(string $appName): object {
                return new class {
                    /** @var array<string, mixed> */
                    public array $values = [];

                    public function set(string $key, mixed $value, int $ttl = 0): bool {
                        $this->values[$key] = $value;
                        return true;
                    }
                };
            }
        };

        $ai = new AIService($this->createMock(IDBConnection::class), $config, $cacheFactory,
            $this->createMock(LoggerInterface::class), $llm, $this->store());
        $ai->schedulePrompt('prompt', 'alice');
    }
}
