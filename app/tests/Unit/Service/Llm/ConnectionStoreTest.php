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
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

/**
 * 5.6.0 Task A2: ai_connection is the single source of the connection snapshot. Without it the
 * snapshot is derived from the 5.5 keys, so upgraded instances keep working before any repair
 * step ran; an empty legacy provider means the removed TaskProcessing path and becomes disabled.
 */
#[AllowMockObjectsWithoutExpectations]
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

    /**
     * A connection 5.7.0 can select: self-hosted Ollama.
     * @return array<string, mixed>
     */
    private function ollama(): array {
        return ['rev' => 1, 'enabled' => true, 'provider' => 'ollama', 'preset' => '',
            'baseUrl' => 'http://ollama.lan:11434', 'model' => 'mistral-nemo', 'processingLocal' => true,
            'allowInternalNetwork' => true];
    }

    /**
     * Valid for the adapters, but not selectable before 5.8.0.
     * @return array<string, mixed>
     */
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
        $this->values->set('learning', 'ai_provider', 'gemini');
        $this->values->set('learning', 'ai_connection', (string)json_encode($this->ollama()));
        $c = $this->store()->load();
        $this->assertSame('ollama', $c->provider);
        $this->assertSame('http://ollama.lan:11434', $c->baseUrl);
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
        $this->values->set('learning', 'ai_connection', (string)json_encode($this->ollama()));
        $this->assertTrue($store->isEnabledNow());
        $this->values->set('learning', 'ai_connection', (string)json_encode(array_merge($this->ollama(), ['enabled' => false])));
        $this->assertFalse($store->isEnabledNow());
        // A long-running job must see the admin switching AI off: the per-process cache is dropped.
        $this->assertSame(2, $this->values->clearCacheCalls);
    }

    // Review A1/A2 (Codex P2): only a missing snapshot may fall back to the 5.5 keys.
    public function testExistingEmptyAiConnectionFailsClosed(): void {
        $this->values->set('learning', 'ai_provider', 'gemini');
        $this->values->set('learning', 'ai_enabled', 'yes');
        $this->values->set('learning', 'ai_connection', '');
        $store = $this->store();
        $this->assertSame('disabled', $store->load()->provider);
        $this->assertFalse($store->isEnabledNow());
    }

    public function testIsEnabledNowSeesAnotherProcessSwitchingOff(): void {
        $this->values->set('learning', 'ai_connection', (string)json_encode($this->ollama()));
        $store = $this->store();
        $this->assertTrue($store->load()->enabled);
        $this->values->setFromOtherProcess('learning', 'ai_connection',
            (string)json_encode(array_merge($this->ollama(), ['enabled' => false])));
        // The snapshot read keeps the process cache (a request sees one consistent state) ...
        $this->assertTrue($store->load()->enabled);
        // ... the kill-switch check before sending does not.
        $this->assertFalse($store->isEnabledNow());
    }

    /**
     * Review finding (HIGH): a long-running process (the weekly job) must take each new snapshot
     * from the persisted value, not from a cache filled before the admin switched the recipient in
     * another process. refresh() drops the process cache; the next load() reads what is stored.
     */
    public function testRefreshSeesAnotherProcessSwitchingTheRecipient(): void {
        $this->values->set('learning', 'ai_connection', (string)json_encode($this->ollama()));
        $store = $this->store();
        $this->assertSame('http://ollama.lan:11434', $store->load()->baseUrl);
        $this->values->setFromOtherProcess('learning', 'ai_connection',
            (string)json_encode(array_merge($this->ollama(), ['baseUrl' => 'http://other.lan:11434', 'rev' => 2])));
        $this->assertSame('http://ollama.lan:11434', $store->load()->baseUrl, 'load() alone keeps the cache');

        $store->refresh();

        $this->assertSame('http://other.lan:11434', $store->load()->baseUrl);
        $this->assertSame(1, $this->values->clearCacheCalls);
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
        $store->save(LlmConnection::fromArray($this->ollama()), 'admin');

        $this->assertSame(2, $this->values->setCalls);
        $saved = json_decode((string)$this->values->get('learning', 'ai_connection'), true);
        $this->assertSame('ollama', $saved['provider']);
        $this->assertSame('http://ollama.lan:11434', $saved['baseUrl']);
        $this->assertSame($this->values->get('learning', 'ai_connection'),
            $this->values->get('learning', 'ai_connection_saved_ollama_'));
        $this->assertSame($saved, $store->load()->toArray());
    }

    public function testSaveIncrementsRevisionPastTheStoredOne(): void {
        $this->values->set('learning', 'ai_connection', (string)json_encode(array_merge($this->ollama(), ['rev' => 7])));
        $store = $this->store();
        $store->save(LlmConnection::fromArray($this->ollama()), 'admin');
        $this->assertSame(8, $store->load()->rev);
        $store->save($store->load(), 'admin');
        $this->assertSame(9, $store->load()->rev);
    }

    public function testSavingDisabledWritesNoPerBackendCopy(): void {
        $this->store()->save(LlmConnection::fromArray(['provider' => 'disabled']), 'admin');
        $this->assertSame(['ai_connection'], $this->values->keys('learning'));
    }

    public function testSaveAuditsNameAndFingerprintPrefixOnly(): void {
        $c = LlmConnection::fromArray($this->ollama());
        $captured = null;
        $this->audit->expects($this->once())->method('logEvent')
            ->with('ai_connection_changed', 'admin', $this->anything())
            ->willReturnCallback(function (string $key, string $uid, array $ctx) use (&$captured): void {
                $captured = $ctx;
            });

        $this->store()->save($c, 'admin');

        $this->assertIsArray($captured);
        $this->assertSame('Ollama (ollama.lan)', $captured['recipient']);
        $this->assertSame(substr($c->connectionFingerprint(), 0, 12), $captured['connection_fp']);
        $this->assertSame(substr($c->recipientFingerprint(), 0, 12), $captured['recipient_fp']);
        $encoded = (string)json_encode($captured);
        $this->assertStringNotContainsString($c->connectionFingerprint(), $encoded);
        $this->assertStringNotContainsString($c->recipientFingerprint(), $encoded);
    }

    // ---- Providers still not selectable: the anthropic adapter ships later -------------------

    /** @return list<array<string, mixed>> */
    private function notYetSelectable(): array {
        return [
            ['enabled' => true, 'provider' => 'anthropic',
                'baseUrl' => 'https://api.anthropic.com/v1', 'model' => 'claude-x'],
        ];
    }

    public function testSaveRejectsProvidersThatAreNotSelectableYet(): void {
        foreach ($this->notYetSelectable() as $a) {
            $store = $this->store();
            try {
                $store->save(LlmConnection::fromArray($a), 'admin');
                $this->fail('saving ' . $a['provider'] . ' must be refused in this release');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('not available', $e->getMessage());
            }
        }
        $this->assertSame(0, $this->values->setCalls, 'nothing may be written');
    }

    public function testStoredProviderThatIsNotSelectableYetLoadsAsDisabled(): void {
        foreach ($this->notYetSelectable() as $a) {
            $this->values->set('learning', 'ai_connection', (string)json_encode($a));
            $store = $this->store();
            $this->assertSame('disabled', $store->load()->provider, $a['provider']);
            $this->assertFalse($store->load()->enabled, $a['provider']);
            $this->assertFalse($store->isEnabledNow(), $a['provider']);
        }
    }

    public function testTheSelectableProvidersRoundTrip(): void {
        $this->assertSame(['gemini', 'ollama', 'openai_compatible', 'disabled'], ConnectionStore::SELECTABLE_PROVIDERS);
        $store = $this->store();
        $store->save(ConnectionStore::legacyGemini(true), 'admin');
        $this->assertSame('gemini', $store->load()->provider);
        $store->save(LlmConnection::fromArray($this->ollama()), 'admin');
        $this->assertSame('ollama', $store->load()->provider);
        $store->save(LlmConnection::fromArray([
            'enabled' => true, 'provider' => 'openai_compatible', 'preset' => 'custom',
            'baseUrl' => 'https://api.deepseek.com', 'model' => 'deepseek-chat',
        ]), 'admin');
        $this->assertSame('openai_compatible', $store->load()->provider);
        $store->save(LlmConnection::fromArray(['provider' => 'disabled']), 'admin');
        $this->assertSame('disabled', $store->load()->provider);
    }

    /**
     * Spec 5.3: an OpenAI-compatible endpoint from the admin form keeps the internal network
     * closed by default (https only, TargetGuard refuses LAN addresses); the legacy derivation
     * reads the opt-in from its own key, so a fresh install without a snapshot sees the same.
     */
    public function testTheOpenAiCompatibleFormConnectionOpensTheInternalNetworkOnlyOnRequest(): void {
        $cloud = ConnectionStore::fromLegacyValues('openai_compatible', true, 'https://api.deepseek.com', 'deepseek-chat');
        $this->assertFalse($cloud->allowInternalNetwork);
        $this->assertSame('custom', $cloud->preset);

        try {
            ConnectionStore::fromLegacyValues('openai_compatible', true, 'http://api.deepseek.com', 'deepseek-chat');
            $this->fail('http without the opt-in must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('https', $e->getMessage());
        }

        $lan = ConnectionStore::fromLegacyValues('openai_compatible', true, 'http://llm.lan:8080/v1', 'm', true);
        $this->assertTrue($lan->allowInternalNetwork);

        $this->values->set('learning', 'ai_provider', 'openai_compatible');
        $this->values->set('learning', 'ai_enabled', 'yes');
        $this->values->set('learning', 'ai_ollama_url', 'http://llm.lan:8080/v1');
        $this->values->set('learning', 'ai_ollama_model', 'm');
        $this->assertSame('disabled', $this->store()->load()->provider, 'an http endpoint without the opt-in fails closed');
        $this->values->set('learning', 'ai_allow_internal_network', 'yes');
        $this->assertTrue($this->store()->load()->allowInternalNetwork);
        $this->assertSame('openai_compatible', $this->store()->load()->provider);
    }

    /** The opt-in does not change who receives the data, so it must not re-ask every user for consent. */
    public function testTheOptInIsNotPartOfTheRecipientFingerprint(): void {
        $off = ConnectionStore::fromLegacyValues('openai_compatible', true, 'https://llm.example/v1', 'm');
        $on = ConnectionStore::fromLegacyValues('openai_compatible', true, 'https://llm.example/v1', 'm', true);
        $this->assertSame($off->recipientFingerprint(), $on->recipientFingerprint());
        $this->assertSame($off->connectionFingerprint(), $on->connectionFingerprint(), 'the key stays bound');
    }
}
