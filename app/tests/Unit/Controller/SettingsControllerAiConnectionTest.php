<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\SettingsController;
use OCA\Learning\Db\UserTelos;
use OCA\Learning\Db\UserTelosMapper;
use OCA\Learning\Service\AuditCheckpointService;
use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\KeyService;
use OCA\Learning\Service\Llm\ApiKeyStore;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\ConsentService;
use OCA\Learning\Tests\Support\FakeAppConfigValues;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

/**
 * Review B1 (BLOCKER): the 5.5 admin form writes ai_provider / ai_ollama_*. Once a connection
 * snapshot exists, that write must reach the snapshot too — otherwise consent is checked against
 * one recipient while the admin believes another is configured. A changed recipient then asks
 * every user for consent again (recipient_changed).
 */
#[AllowMockObjectsWithoutExpectations]
class SettingsControllerAiConnectionTest extends TestCase {
    private FakeAppConfigValues $values;
    private AuditService&MockObject $audit;
    private IConfig&MockObject $config;

    protected function setUp(): void {
        $this->values = new FakeAppConfigValues();
        $this->audit = $this->createMock(AuditService::class);
        $this->config = $this->createMock(IConfig::class);
        $this->config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, string $default = '') => $this->values->get($app, $key) ?? $default);
        $this->config->method('setAppValue')->willReturnCallback(
            function (string $app, string $key, string $value): void {
                $this->values->set($app, $key, $value);
            });
    }

    private function store(): ConnectionStore {
        $appConfig = $this->createMock(IAppConfig::class);
        $this->values->wire($appConfig);
        return new ConnectionStore($appConfig, $this->audit, $this->createMock(LoggerInterface::class));
    }

    /** ICrypto fake: 'enc:'.$v; encrypting a value containing THROW fails. */
    private function keys(): ApiKeyStore {
        $appConfig = $this->createMock(IAppConfig::class);
        $this->values->wire($appConfig);
        $crypto = $this->createMock(ICrypto::class);
        $crypto->method('encrypt')->willReturnCallback(function (string $v): string {
            if (str_contains($v, 'THROW')) {
                throw new \Exception('encryption failed');
            }
            return 'enc:' . $v;
        });
        $crypto->method('decrypt')->willReturnCallback(function (string $v): string {
            if (!str_starts_with($v, 'enc:')) {
                throw new \Exception('HMAC does not match.');
            }
            return substr($v, 4);
        });
        return new ApiKeyStore($appConfig, $crypto, $this->createMock(LoggerInterface::class));
    }

    private function controller(): SettingsController {
        return new SettingsController('learning', $this->createMock(IRequest::class), $this->config,
            $this->createMock(IDBConnection::class), $this->createMock(KeyService::class),
            $this->createMock(AuditCheckpointService::class), $this->store(), $this->keys(), $this->createMock(\OCA\Learning\Service\RoleService::class), 'admin');
    }

    private function saveAi(string $provider, string $url = 'http://localhost:11434', string $model = 'llama3', string $enabled = 'yes', ?string $key = null, string $internal = 'no'): int {
        return $this->controller()->saveAdmin('yes', 'de', 2, 'yes', 'learning-instructors', 'no', 5, 10, $key,
            $provider, $url, $model, $enabled, $internal)->getStatus();
    }

    private function geminiSlot(): string {
        return ApiKeyStore::keyName(ConnectionStore::legacyGemini(false));
    }

    /**
     * Review finding (HIGH): after the upgrade moved the key into the encrypted slot, the 5.7 admin
     * form still wrote the plaintext `gemini_api_key`, which get() never prefers — a rotated key was
     * silently ignored and the next repair deleted it. The form now stores the key encrypted.
     */
    public function testAKeyEnteredInTheAdminFormReplacesTheMigratedKey(): void {
        $this->values->set('learning', $this->geminiSlot(), 'enc:AIza-old-leaked');

        $this->assertSame(200, $this->saveAi('gemini', key: 'AIza-rotated'));

        $this->assertFalse($this->values->has('learning', 'gemini_api_key'), 'never stored in plaintext');
        $this->assertSame('enc:AIza-rotated', $this->values->get('learning', $this->geminiSlot()));
        $this->assertSame('AIza-rotated', $this->keys()->get($this->store()->load()));
        $this->assertSame('already', $this->keys()->migrateLegacyGeminiKey(), 'a later repair keeps it');
        $this->assertSame('AIza-rotated', $this->keys()->get($this->store()->load()));
    }

    public function testAKeyEnteredWhileOllamaIsActiveIsStoredForGemini(): void {
        $this->assertSame(200, $this->saveAi('ollama', key: 'AIza-for-later'));
        $this->assertSame('AIza-for-later', $this->keys()->get(ConnectionStore::legacyGemini(true)));
        $this->assertFalse($this->values->has('learning', 'gemini_api_key'));
    }

    public function testAnEmptyKeyDeletesBothCopiesAndTheMaskKeepsTheKey(): void {
        $this->values->set('learning', $this->geminiSlot(), 'enc:AIza-a');
        $this->assertSame(200, $this->saveAi('gemini', key: '***'));
        $this->assertSame('AIza-a', $this->keys()->get(ConnectionStore::legacyGemini(true)));

        $this->values->set('learning', 'gemini_api_key', 'AIza-plain');
        $this->assertSame(200, $this->saveAi('gemini', key: ''));
        $this->assertNull($this->keys()->get(ConnectionStore::legacyGemini(true)));
        $this->assertFalse($this->values->has('learning', 'gemini_api_key'));
        $this->assertFalse($this->values->has('learning', $this->geminiSlot()));
    }

    public function testAKeyThatCannotBeStoredIsAnErrorAndNothingIsSaved(): void {
        $this->values->set('learning', $this->geminiSlot(), 'enc:AIza-a');
        $this->config->expects($this->never())->method('setAppValue');

        $this->assertSame(500, $this->saveAi('gemini', key: 'AIza-THROW'));

        $this->assertSame('enc:AIza-a', $this->values->get('learning', $this->geminiSlot()));
        $this->assertFalse($this->values->has('learning', 'gemini_api_key'));
    }

    /** @return array<string, array{array<string, string>, bool}> */
    public static function keyStates(): array {
        return [
            'migrated (encrypted only)' => [['slot' => 'enc:AIza-a'], true],
            'not yet migrated (plaintext only)' => [['plain' => 'AIza-a'], true],
            'none' => [[], false],
            'undecryptable slot only' => [['slot' => 'bad:x'], false],
        ];
    }

    /**
     * @param array<string, string> $state
     */
    #[DataProvider('keyStates')]
    public function testTheAdminPageReportsTheKeyThatIsActuallyUsed(array $state, bool $expected): void {
        if (isset($state['slot'])) {
            $this->values->set('learning', $this->geminiSlot(), $state['slot']);
        }
        if (isset($state['plain'])) {
            $this->values->set('learning', 'gemini_api_key', $state['plain']);
        }
        $this->assertSame($expected, $this->adminView()['gemini_api_key_set']);
    }

    public function testAnAdminSwitchToOllamaReachesTheSnapshotAndAsksForConsentAgain(): void {
        $gemini = ConnectionStore::legacyGemini(true);
        $this->values->set('learning', ConnectionStore::KEY, json_encode($gemini->toArray(), JSON_THROW_ON_ERROR));

        $mapper = $this->createMock(UserTelosMapper::class);
        $consent = new ConsentService($mapper, $this->createMock(AuditService::class), $this->createMock(IAppConfig::class));
        $row = new UserTelos();
        $row->setUserId('alice');
        $row->setAiConsentVersion($consent->currentVersion());
        $row->setAiConsentFingerprint($gemini->recipientFingerprint());
        $mapper->method('findByUserIdOrNull')->willReturn($row);
        $this->assertTrue($consent->isValidFor('alice', $this->store()->load()));

        $this->audit->expects($this->once())->method('logEvent')->with('ai_connection_changed', 'admin', $this->anything());
        $this->assertSame(200, $this->saveAi('ollama', 'http://ollama-b.lan:11434', 'model-b'));

        $now = $this->store()->load();
        $this->assertSame('ollama', $now->provider);
        $this->assertSame('http://ollama-b.lan:11434', $now->baseUrl);
        $this->assertSame('model-b', $now->model);
        $this->assertSame('recipient_changed', $consent->state('alice', $now)['reason']);
        // that the gateway sends only to the snapshot recipient is LlmGatewayTest's job (Task B3)
    }

    public function testUnchangedAiSettingsLeaveTheSnapshotAlone(): void {
        $local = ['rev' => 3, 'enabled' => true, 'provider' => 'ollama', 'baseUrl' => 'http://ollama.lan:11434',
            'model' => 'm', 'allowInternalNetwork' => true, 'processingLocal' => true];
        $this->values->set('learning', ConnectionStore::KEY, json_encode($local, JSON_THROW_ON_ERROR));
        $before = $this->values->get('learning', ConnectionStore::KEY);

        $this->audit->expects($this->never())->method('logEvent');
        $this->assertSame(200, $this->saveAi('ollama', 'http://ollama.lan:11434', 'm'));

        $this->assertSame($before, $this->values->get('learning', ConnectionStore::KEY), 'processingLocal and rev are kept');
    }

    public function testWithoutASnapshotTheLegacyKeysStayTheSource(): void {
        $this->assertSame(200, $this->saveAi('ollama', 'http://ollama-b.lan:11434', 'model-b'));

        $this->assertFalse($this->values->has('learning', ConnectionStore::KEY));
        $this->assertSame('http://ollama-b.lan:11434', $this->store()->load()->baseUrl);
    }

    public function testAnInvalidOllamaUrlIsRejectedBeforeAnythingIsWritten(): void {
        $this->config->expects($this->never())->method('setAppValue');

        $this->assertSame(400, $this->saveAi('ollama', 'not a url'));
    }

    /** @return array<string, array{string}> */
    public static function notSelectableProviders(): array {
        return [
            'anthropic' => ['anthropic'],
            'unknown' => ['deepseek'],
            'empty' => [''],
        ];
    }

    /**
     * Scope cut (review finding): a provider this release does not offer is an error — it must
     * not silently become Gemini (an external recipient), answer 200 and turn every user's
     * consent into recipient_changed. Anthropic ships later; a provider name the app has never
     * known stays refused (`deepseek` is such a name — the service reaches that endpoint through
     * openai_compatible instead).
     *
     */
    #[DataProvider('notSelectableProviders')]
    public function testAProviderThisReleaseDoesNotOfferIsRejectedBeforeAnythingIsWritten(string $provider): void {
        $local = ['rev' => 3, 'enabled' => true, 'provider' => 'ollama', 'baseUrl' => 'http://ollama.lan:11434',
            'model' => 'm', 'allowInternalNetwork' => true, 'processingLocal' => true];
        $this->values->set('learning', ConnectionStore::KEY, json_encode($local, JSON_THROW_ON_ERROR));
        $before = $this->values->get('learning', ConnectionStore::KEY);
        $this->config->expects($this->never())->method('setAppValue');
        $this->audit->expects($this->never())->method('logEvent');

        $this->assertSame(400, $this->saveAi($provider));

        $this->assertSame($before, $this->values->get('learning', ConnectionStore::KEY));
    }

    public function testDisabledIsStillAccepted(): void {
        $this->assertSame(200, $this->saveAi('disabled'));
        $this->assertFalse($this->store()->load()->enabled);
    }

    /** The form's endpoint/model fields carry any OpenAI-compatible gateway (DeepSeek, MiMo, ...). */
    public function testOpenAiCompatibleCanBeSelectedAndSaved(): void {
        $this->assertSame(200, $this->saveAi('openai_compatible', 'https://api.deepseek.com', 'deepseek-chat'));

        $c = $this->store()->load();
        $this->assertSame('openai_compatible', $c->provider);
        $this->assertSame('custom', $c->preset);
        $this->assertSame('https://api.deepseek.com', $c->baseUrl);
        $this->assertSame('deepseek-chat', $c->model);
        $this->assertFalse($c->processingLocal, 'a cloud gateway never claims local processing');
    }

    /**
     * Spec 5.3: unlike the Ollama URL, an OpenAI-compatible endpoint is usually a cloud host, so
     * the internal network is an explicit opt-in. Without it the API key and user data would go
     * out in plain http, and TargetGuard's LAN/DNS-rebinding refusal would be switched off for
     * api.deepseek.com as well.
     */
    public function testAnOpenAiCompatibleCloudEndpointKeepsTheInternalNetworkClosed(): void {
        $this->assertSame(200, $this->saveAi('openai_compatible', 'https://api.deepseek.com', 'deepseek-chat'));
        $this->assertFalse($this->store()->load()->allowInternalNetwork);
        $this->assertSame('no', $this->adminView()['ai_allow_internal_network']);
    }

    public function testAnHttpOpenAiCompatibleEndpointIsRefusedWithoutTheOptIn(): void {
        $this->assertSame(400, $this->saveAi('openai_compatible', 'http://api.deepseek.com', 'deepseek-chat'));
        $this->assertFalse($this->values->has('learning', ConnectionStore::KEY));
        $this->assertNotSame('openai_compatible', $this->store()->load()->provider, 'nothing half-saved');
    }

    public function testAnHttpGatewayOnTheLanIsAcceptedWithTheOptIn(): void {
        $this->assertSame(200, $this->saveAi('openai_compatible', 'http://llm.lan:8080/v1', 'local-model', internal: 'yes'));
        $c = $this->store()->load();
        $this->assertSame('http://llm.lan:8080/v1', $c->baseUrl);
        $this->assertTrue($c->allowInternalNetwork);
        $this->assertSame('yes', $this->adminView()['ai_allow_internal_network']);
        $this->assertSame('http://llm.lan:8080/v1', $this->adminView()['ai_ollama_url']);
    }

    /** formView covers the opt-in: switching it off on an existing snapshot must reach the snapshot. */
    public function testTurningTheOptInOffReachesAnExistingSnapshot(): void {
        $this->assertSame(200, $this->saveAi('openai_compatible', 'https://llm.lan/v1', 'm', internal: 'yes'));
        $this->store()->save($this->store()->load(), 'admin');
        $this->assertTrue($this->store()->load()->allowInternalNetwork);

        $this->assertSame(200, $this->saveAi('openai_compatible', 'https://llm.lan/v1', 'm', internal: 'no'));
        $this->assertFalse($this->store()->load()->allowInternalNetwork);
    }

    /** An empty endpoint or model is an error for OpenAI-compatible, never the Ollama defaults. */
    public function testAnEmptyOpenAiCompatibleEndpointOrModelIsRefused(): void {
        $this->assertSame(400, $this->saveAi('openai_compatible', '', 'deepseek-chat', internal: 'yes'));
        $this->assertSame(400, $this->saveAi('openai_compatible', 'https://api.deepseek.com', '', internal: 'yes'));
        $this->assertFalse($this->values->has('learning', ConnectionStore::KEY));
        $this->assertNotSame('openai_compatible', $this->store()->load()->provider);
    }

    /** A refused http endpoint leaves an existing snapshot exactly as it was. */
    public function testAnHttpEndpointWithTheOptInOffLeavesTheSnapshotUntouched(): void {
        $this->assertSame(200, $this->saveAi('openai_compatible', 'http://llm.lan:8080/v1', 'm', internal: 'yes'));
        $this->store()->save($this->store()->load(), 'admin');
        $before = $this->values->get('learning', ConnectionStore::KEY);

        $this->assertSame(400, $this->saveAi('openai_compatible', 'http://llm.lan:8080/v1', 'm', internal: 'no'));
        $this->assertSame($before, $this->values->get('learning', ConnectionStore::KEY));
    }

    /** The opt-in belongs to the OpenAI-compatible form only; the Ollama URL keeps its 5.5 trust model. */
    public function testOllamaStillAcceptsAnHttpUrlWithoutTheOptIn(): void {
        $this->assertSame(200, $this->saveAi('ollama', 'http://ollama.lan:11434', 'llama3'));
        $this->assertTrue($this->store()->load()->allowInternalNetwork);
    }

    /**
     * An OpenAI-compatible key is bound to its endpoint. A Gemini key in the legacy slot is never
     * sent there, so the page must not claim "key saved" for it (the admin would leave the field
     * empty and every request would fail with an auth error).
     */
    public function testAGeminiKeyDoesNotShowAsSavedForAnOpenAiCompatibleEndpoint(): void {
        $this->values->set('learning', $this->geminiSlot(), 'enc:AIza-gemini');
        $this->assertSame(200, $this->saveAi('openai_compatible', 'https://api.deepseek.com', 'deepseek-chat'));
        $this->assertFalse($this->adminView()['gemini_api_key_set']);

        $this->assertSame(200, $this->saveAi('openai_compatible', 'https://api.deepseek.com', 'deepseek-chat', key: 'sk-deepseek'));
        $this->assertTrue($this->adminView()['gemini_api_key_set']);
        $this->assertSame('sk-deepseek', $this->keys()->get($this->store()->load()));
        $this->assertSame('AIza-gemini', $this->keys()->get(ConnectionStore::legacyGemini(true)), 'the Gemini key is untouched');
    }

    /** The key follows the endpoint, never the admin to another host. */
    public function testAnOpenAiCompatibleKeyDoesNotFollowTheAdminToAnotherHost(): void {
        $this->assertSame(200, $this->saveAi('openai_compatible', 'https://api.deepseek.com', 'deepseek-chat', key: 'sk-deepseek'));
        $this->assertSame(200, $this->saveAi('openai_compatible', 'https://evil.example', 'deepseek-chat', key: '***'));
        $this->assertNull($this->keys()->get($this->store()->load()));
        $this->assertFalse($this->adminView()['gemini_api_key_set']);
    }

    private function adminView(): array {
        $checkpoints = $this->createMock(AuditCheckpointService::class);
        $checkpoints->method('getLivenessStatus')->willThrowException(new \RuntimeException('not migrated'));
        $controller = new SettingsController('learning', $this->createMock(IRequest::class), $this->config,
            $this->createMock(IDBConnection::class), $this->createMock(KeyService::class), $checkpoints, $this->store(), $this->keys(), $this->createMock(\OCA\Learning\Service\RoleService::class), 'admin');
        return $controller->getAdmin()->getData();
    }

    /**
     * Review follow-up (round trip of the finding above): a 5.5 install with ai_provider '' (the
     * removed TaskProcessing path) and ai_enabled yes has AI off. The admin page showed it as Gemini
     * and on, so saving any unrelated setting posted gemini + yes back and switched on an external
     * recipient. The page shows the connection that is actually in force.
     */
    public function testTheAdminPageShowsALegacyEmptyProviderAsOffAndSavingItBackKeepsAiOff(): void {
        $this->values->set('learning', 'ai_provider', '');
        $this->values->set('learning', 'ai_enabled', 'yes');

        $view = $this->adminView();

        $this->assertSame('disabled', $view['ai_provider']);
        $this->assertSame('no', $view['ai_enabled']);
        $this->assertSame(200, $this->saveAi($view['ai_provider'], $view['ai_ollama_url'], $view['ai_ollama_model'], $view['ai_enabled']));
        $this->assertFalse($this->store()->load()->enabled);
    }

    /** With a snapshot, the page shows the snapshot, not stale legacy keys. */
    public function testTheAdminPageShowsTheSnapshotOverStaleLegacyKeys(): void {
        $this->values->set('learning', 'ai_provider', 'gemini');
        $this->values->set('learning', 'ai_enabled', 'yes');
        $local = ['rev' => 3, 'enabled' => true, 'provider' => 'ollama', 'baseUrl' => 'http://ollama.lan:11434',
            'model' => 'm', 'allowInternalNetwork' => true, 'processingLocal' => true];
        $this->values->set('learning', ConnectionStore::KEY, json_encode($local, JSON_THROW_ON_ERROR));

        $view = $this->adminView();

        $this->assertSame('ollama', $view['ai_provider']);
        $this->assertSame('yes', $view['ai_enabled']);
        $this->assertSame('http://ollama.lan:11434', $view['ai_ollama_url']);
        $this->assertSame('m', $view['ai_ollama_model']);
    }

    /**
     * GitHub #35: the course view reads the admin's AI switch from GET /api/settings/tools (every
     * user may call it) to leave out the knowledge import and the text extraction when AI is off.
     * Same meaning as on the admin page: switched on with a provider.
     */
    public function testToolSettingsTellEveryUserWhetherAiIsSwitchedOn(): void {
        $this->values->set('learning', 'ai_provider', 'gemini');
        $this->values->set('learning', 'ai_enabled', 'yes');
        $this->assertTrue($this->controller()->getTools()->getData()['ai_enabled']);

        $this->values->set('learning', 'ai_enabled', 'no');
        $this->assertFalse($this->controller()->getTools()->getData()['ai_enabled']);

        $this->values->set('learning', 'ai_provider', '');
        $this->values->set('learning', 'ai_enabled', 'yes');
        $this->assertFalse($this->controller()->getTools()->getData()['ai_enabled'], 'no provider is AI off');
    }

    public function testTheAdminPageSaysWhetherPdfTextExtractionWorks(): void {
        $this->assertIsBool($this->adminView()['pdf_text_extraction_available']);
    }
}
