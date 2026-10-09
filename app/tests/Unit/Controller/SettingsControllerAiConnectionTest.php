<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\SettingsController;
use OCA\Learning\Db\UserTelos;
use OCA\Learning\Db\UserTelosMapper;
use OCA\Learning\Service\AuditCheckpointService;
use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\GeminiProvider;
use OCA\Learning\Service\KeyService;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\ConsentService;
use OCA\Learning\Service\LlmService;
use OCA\Learning\Service\OllamaProvider;
use OCA\Learning\Tests\Support\FakeAppConfigValues;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Review B1 (BLOCKER): the 5.5 admin form writes ai_provider / ai_ollama_*. Once a connection
 * snapshot exists, that write must reach the snapshot too — otherwise consent is checked against
 * one recipient while the admin believes another is configured. A changed recipient then asks
 * every user for consent again (recipient_changed).
 */
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

    private function controller(): SettingsController {
        return new SettingsController('learning', $this->createMock(IRequest::class), $this->config,
            $this->createMock(IDBConnection::class), $this->createMock(KeyService::class),
            $this->createMock(AuditCheckpointService::class), $this->store(), 'admin');
    }

    private function saveAi(string $provider, string $url = 'http://localhost:11434', string $model = 'llama3', string $enabled = 'yes'): int {
        return $this->controller()->saveAdmin('yes', 'de', 2, 'yes', 'learning-instructors', 'no', 5, 10, null,
            $provider, $url, $model, $enabled)->getStatus();
    }

    public function testAnAdminSwitchToOllamaReachesTheSnapshotAndAsksForConsentAgain(): void {
        $gemini = ConnectionStore::legacyGemini(true);
        $this->values->set('learning', ConnectionStore::KEY, json_encode($gemini->toArray(), JSON_THROW_ON_ERROR));

        $mapper = $this->createMock(UserTelosMapper::class);
        $consent = new ConsentService($mapper, $this->createMock(AuditService::class));
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

        // and the transport sends where the snapshot (and so the consent check) points
        $ollama = $this->createMock(OllamaProvider::class);
        $ollama->expects($this->once())->method('generateAt')->with('http://ollama-b.lan:11434', 'model-b')->willReturn('x');
        (new LlmService($this->createMock(GeminiProvider::class), $ollama, $this->store()))->generateText('p');
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
}
