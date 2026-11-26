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
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Task B4: LlmService no longer sends anything (LlmGateway does). It is the thin answer to "is AI
 * usable right now" for the UI: provider from the connection snapshot (never from the legacy keys
 * ai_provider / ai_ollama_*), and available only for a backend 5.7.0 routes that has what it
 * needs — Gemini a key for exactly this endpoint, Ollama nothing, openai_compatible a key for
 * every preset except `custom` (a keyless custom gateway needs none, like Ollama).
 */
#[AllowMockObjectsWithoutExpectations]
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

    /**
     * A snapshot LlmConnection::fromArray accepts (https URL, model, openrouter with its fixed
     * routing), so a false from isAvailable() comes from the rule, not from a snapshot the store
     * swapped for 'disabled'.
     *
     * @param 'openai'|'mistral'|'ionos'|'openrouter'|'custom' $preset
     */
    private function openAiCompatible(string $preset, bool $enabled = true): void {
        $a = ['rev' => 1, 'enabled' => $enabled, 'provider' => 'openai_compatible', 'preset' => $preset,
            'baseUrl' => [
                'openai' => 'https://api.openai.com/v1',
                'mistral' => 'https://api.mistral.ai/v1',
                'ionos' => 'https://openai.inference.de-txl.ionos.com/v1',
                'openrouter' => 'https://openrouter.ai/api/v1',
                'custom' => 'https://api.deepseek.com',
            ][$preset],
            'model' => 'm'];
        if ($preset === 'openrouter') {
            $a['openRouterRouting'] = ['order' => ['openai'], 'allowFallbacks' => false];
        }
        $this->snapshot($a);
    }

    /** @return array<string, array{'openai'|'mistral'|'ionos'|'openrouter'}> */
    public static function keyedPresets(): array {
        return ['openai' => ['openai'], 'mistral' => ['mistral'], 'ionos' => ['ionos'], 'openrouter' => ['openrouter']];
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
        $this->assertFalse($service->isAvailable());
    }

    public function testASwitchedOffConnectionIsNotAvailable(): void {
        $this->snapshot(ConnectionStore::legacyGemini(false)->toArray());
        $this->keys->method('get')->willReturn('k');

        $this->assertFalse($this->service()->isAvailable());
    }

    /** GitHub #115: a custom endpoint works without a key, so it must not hide the AI features. */
    public function testAKeylessCustomEndpointIsAvailable(): void {
        $this->openAiCompatible('custom');
        $this->keys->method('get')->willReturnCallback(static fn (LlmConnection $c) => null);

        $service = $this->service();
        $this->assertSame('openai_compatible', $service->getProviderId());
        $this->assertTrue($service->isAvailable());
    }

    /**
     * GitHub #115: a preset with a key for exactly this endpoint is available.
     *
     * @param 'openai'|'mistral'|'ionos'|'openrouter' $preset
     */
    #[DataProvider('keyedPresets')]
    public function testAKeyedPresetWithAKeyForThisEndpointIsAvailable(string $preset): void {
        $this->openAiCompatible($preset);
        $this->keys->method('get')->willReturnCallback(
            static fn (LlmConnection $c) => $c->provider === 'openai_compatible' && $c->preset === $preset ? 'k' : null);

        $service = $this->service();
        $this->assertSame('openai_compatible', $service->getProviderId());
        $this->assertTrue($service->isAvailable());
    }

    /**
     * Only `custom` may go without a key; every other preset would fail with 401 on the first message.
     *
     * @param 'openai'|'mistral'|'ionos'|'openrouter' $preset
     */
    #[DataProvider('keyedPresets')]
    public function testAKeyedPresetWithoutAKeyIsNotAvailable(string $preset): void {
        $this->openAiCompatible($preset);
        $this->keys->method('get')->willReturnCallback(static fn (LlmConnection $c) => null);

        $service = $this->service();
        $this->assertSame('openai_compatible', $service->getProviderId(), 'the snapshot is valid, not swapped for disabled');
        $this->assertFalse($service->isAvailable());
    }

    /** The custom shortcut stays behind the switch. */
    public function testASwitchedOffCustomEndpointIsNotAvailable(): void {
        $this->openAiCompatible('custom', false);
        $this->keys->method('get')->willReturn('k');

        $service = $this->service();
        $this->assertSame('openai_compatible', $service->getProviderId(), 'the snapshot is valid, not swapped for disabled');
        $this->assertFalse($service->isAvailable());
    }
}
