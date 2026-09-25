<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service\Llm;

use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\LlmConnection;
use OCA\Learning\Tests\Support\FakeAppConfigValues;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 5.6.0 Task A2: ai_connection is the single source of the connection snapshot. Without it the
 * snapshot is derived from the 5.5 keys, so upgraded instances keep working before any repair
 * step ran; an empty legacy provider means the removed TaskProcessing path and becomes disabled.
 */
class ConnectionStoreTest extends TestCase {
    private FakeAppConfigValues $values;
    private AuditService&MockObject $audit;

    protected function setUp(): void {
        $this->values = new FakeAppConfigValues();
        $this->audit = $this->createMock(AuditService::class);
    }

    private function store(): ConnectionStore {
        $appConfig = $this->createMock(IAppConfig::class);
        $this->values->wire($appConfig);
        return new ConnectionStore($appConfig, $this->audit, $this->createMock(LoggerInterface::class));
    }

    /** @return array<string, mixed> */
    private function mistral(): array {
        return ['rev' => 1, 'enabled' => true, 'provider' => 'openai_compatible', 'preset' => 'mistral',
            'baseUrl' => 'https://api.mistral.ai/v1', 'model' => 'm1', 'processingLocal' => false,
            'allowInternalNetwork' => false];
    }

    public function testLegacyOllamaIsDerivedWhenAiConnectionIsMissing(): void {
        $this->values->set('learning', 'ai_provider', 'ollama');
        $this->values->set('learning', 'ai_enabled', 'yes');
        $this->values->set('learning', 'ai_ollama_url', 'http://ollama.lan:11434');
        $this->values->set('learning', 'ai_ollama_model', 'mistral-nemo');

        $c = $this->store()->load();

        $this->assertSame('ollama', $c->provider);
        $this->assertSame('http://ollama.lan:11434', $c->baseUrl);
        $this->assertSame('mistral-nemo', $c->model);
        $this->assertTrue($c->enabled);
        // 5.5 reached the configured Ollama host without an internal-network gate; keep it working.
        $this->assertTrue($c->allowInternalNetwork);
        // A configured URL does not prove local processing: the recipient stays "unknown".
        $this->assertFalse($c->processingLocal);
    }

    public function testLegacyOllamaUsesTheOldDefaults(): void {
        $this->values->set('learning', 'ai_provider', 'ollama');
        $c = $this->store()->load();
        $this->assertSame('http://localhost:11434', $c->baseUrl);
        $this->assertSame('llama3', $c->model);
        $this->assertFalse($c->enabled);
    }

    public function testLegacyGeminiIsDerived(): void {
        $this->values->set('learning', 'ai_provider', 'gemini');
        $this->values->set('learning', 'ai_enabled', 'yes');
        $c = $this->store()->load();
        $this->assertSame('gemini', $c->provider);
        $this->assertTrue($c->enabled);
        $this->assertSame(ConnectionStore::GEMINI_BASE_URL, $c->baseUrl);
        $this->assertSame(ConnectionStore::GEMINI_MODEL, $c->model);
        $this->assertSame(ConnectionStore::legacyGemini(false)->connectionFingerprint(), $c->connectionFingerprint());
    }

    public function testEmptyLegacyProviderIsDisabled(): void {
        $this->assertSame('disabled', $this->store()->load()->provider);
        $this->values->set('learning', 'ai_provider', '');
        $this->values->set('learning', 'ai_enabled', 'yes');
        $c = $this->store()->load();
        $this->assertSame('disabled', $c->provider);
        $this->assertFalse($c->enabled);
    }

    public function testUnknownLegacyProviderIsDisabled(): void {
        $this->values->set('learning', 'ai_provider', 'nc_assistant');
        $this->values->set('learning', 'ai_enabled', 'yes');
        $this->assertSame('disabled', $this->store()->load()->provider);
    }

    public function testAiConnectionTakesPrecedenceOverLegacyKeys(): void {
        $this->values->set('learning', 'ai_provider', 'ollama');
        $this->values->set('learning', 'ai_connection', (string)json_encode($this->mistral()));
        $c = $this->store()->load();
        $this->assertSame('openai_compatible', $c->provider);
        $this->assertSame('mistral', $c->preset);
    }

    public function testCorruptAiConnectionFailsClosed(): void {
        $this->values->set('learning', 'ai_provider', 'gemini');
        $this->values->set('learning', 'ai_enabled', 'yes');
        foreach (['{not json', '"a string"', json_encode(array_merge($this->mistral(), ['preset' => 'openrouter']))] as $raw) {
            $this->values->set('learning', 'ai_connection', (string)$raw);
            $c = $this->store()->load();
            $this->assertSame('disabled', $c->provider, (string)$raw);
            $this->assertFalse($c->enabled, (string)$raw);
        }
    }

    public function testIsEnabledNowReadsFreshOnEveryCall(): void {
        $store = $this->store();
        $this->values->set('learning', 'ai_connection', (string)json_encode($this->mistral()));
        $this->assertTrue($store->isEnabledNow());
        $this->values->set('learning', 'ai_connection', (string)json_encode(array_merge($this->mistral(), ['enabled' => false])));
        $this->assertFalse($store->isEnabledNow());
        // A long-running job must see the admin switching AI off: the per-process cache is dropped.
        $this->assertSame(2, $this->values->clearCacheCalls);
    }

    public function testIsEnabledNowIsFalseForDisabledProvider(): void {
        $this->values->set('learning', 'ai_connection', (string)json_encode(['provider' => 'disabled', 'enabled' => true]));
        $this->assertFalse($this->store()->isEnabledNow());
    }

    public function testLoadDoesNotDropTheCache(): void {
        $this->store()->load();
        $this->assertSame(0, $this->values->clearCacheCalls);
    }

    public function testSaveWritesSnapshotAndPerBackendCopy(): void {
        $store = $this->store();
        $store->save(LlmConnection::fromArray($this->mistral()), 'admin');

        $this->assertSame(2, $this->values->setCalls);
        $saved = json_decode((string)$this->values->get('learning', 'ai_connection'), true);
        $this->assertSame('openai_compatible', $saved['provider']);
        $this->assertSame('mistral', $saved['preset']);
        $this->assertSame('https://api.mistral.ai/v1', $saved['baseUrl']);
        $this->assertSame($this->values->get('learning', 'ai_connection'),
            $this->values->get('learning', 'ai_connection_saved_openai_compatible_mistral'));
        $this->assertSame($saved, $store->load()->toArray());
    }

    public function testSaveIncrementsRevisionPastTheStoredOne(): void {
        $this->values->set('learning', 'ai_connection', (string)json_encode(array_merge($this->mistral(), ['rev' => 7])));
        $store = $this->store();
        $store->save(LlmConnection::fromArray($this->mistral()), 'admin');
        $this->assertSame(8, $store->load()->rev);
        $store->save($store->load(), 'admin');
        $this->assertSame(9, $store->load()->rev);
    }

    public function testSavingDisabledWritesNoPerBackendCopy(): void {
        $this->store()->save(LlmConnection::fromArray(['provider' => 'disabled']), 'admin');
        $this->assertSame(['ai_connection'], $this->values->keys('learning'));
    }

    public function testSaveAuditsNameAndFingerprintPrefixOnly(): void {
        $c = LlmConnection::fromArray($this->mistral());
        $captured = null;
        $this->audit->expects($this->once())->method('logEvent')
            ->with('ai_connection_changed', 'admin', $this->anything())
            ->willReturnCallback(function (string $key, string $uid, array $ctx) use (&$captured): void {
                $captured = $ctx;
            });

        $this->store()->save($c, 'admin');

        $this->assertIsArray($captured);
        $this->assertSame('Mistral AI', $captured['recipient']);
        $this->assertSame(substr($c->connectionFingerprint(), 0, 12), $captured['connection_fp']);
        $this->assertSame(substr($c->recipientFingerprint(), 0, 12), $captured['recipient_fp']);
        $encoded = (string)json_encode($captured);
        $this->assertStringNotContainsString($c->connectionFingerprint(), $encoded);
        $this->assertStringNotContainsString($c->recipientFingerprint(), $encoded);
    }
}
