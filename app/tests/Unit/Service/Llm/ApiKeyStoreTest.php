<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service\Llm;

use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\Llm\ApiKeyStore;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\LlmConnection;
use OCA\Learning\Tests\Support\FakeAppConfigValues;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 5.6.0 Task A2: API keys are stored encrypted and bound to the connection fingerprint, so a key
 * never follows the admin to a different endpoint. The 5.5 plaintext Gemini key is moved over
 * without ever being lost: it is deleted only after the encrypted copy was read back.
 */
class ApiKeyStoreTest extends TestCase {
    private const LEGACY = 'AIza-LEGACY-MARKER';

    private FakeAppConfigValues $values;
    private IAppConfig $appConfig;
    /** @var list<string> */
    private array $logLines = [];

    protected function setUp(): void {
        $this->values = new FakeAppConfigValues();
        $appConfig = $this->createMock(IAppConfig::class);
        $this->values->wire($appConfig);
        $this->appConfig = $appConfig;
    }

    /**
     * ICrypto fake: encrypt = 'enc:'.$v, decrypt reverses it and throws on anything else (e.g. 'bad:').
     * Failure injection: encrypting a value containing THROW throws, one containing CORRUPT yields
     * an undecryptable ciphertext.
     */
    private function crypto(): ICrypto {
        $crypto = $this->createMock(ICrypto::class);
        $crypto->method('encrypt')->willReturnCallback(function (string $v, string $p = ''): string {
            if (str_contains($v, 'THROW')) {
                throw new \Exception('encryption failed');
            }
            return (str_contains($v, 'CORRUPT') ? 'bad:' : 'enc:') . $v;
        });
        $crypto->method('decrypt')->willReturnCallback(function (string $v, string $p = ''): string {
            if (!str_starts_with($v, 'enc:')) {
                throw new \Exception('HMAC does not match.');
            }
            return substr($v, 4);
        });
        return $crypto;
    }

    private function keys(): ApiKeyStore {
        $logger = $this->createMock(LoggerInterface::class);
        foreach (['warning', 'error', 'info', 'debug', 'notice'] as $level) {
            $logger->method($level)->willReturnCallback(function (string $msg, array $ctx = []): void {
                $this->logLines[] = $msg . ' ' . json_encode($ctx);
            });
        }
        return new ApiKeyStore($this->appConfig, $this->crypto(), $logger);
    }

    private function connections(): ConnectionStore {
        return new ConnectionStore($this->appConfig, $this->createMock(AuditService::class), $this->createMock(LoggerInterface::class));
    }

    private function conn(string $url = 'https://api.mistral.ai/v1'): LlmConnection {
        return LlmConnection::fromArray(['enabled' => true, 'provider' => 'openai_compatible', 'preset' => 'mistral',
            'baseUrl' => $url, 'model' => 'm1']);
    }

    private function targetKey(): string {
        return ApiKeyStore::keyName(ConnectionStore::legacyGemini(false));
    }

    public function testKeyIsBoundToConnectionFingerprint(): void {
        $keys = $this->keys();
        $keys->apply($this->conn('https://a.example/v1'), ['action' => 'replace', 'value' => 'sk-A']);
        $this->assertSame('sk-A', $keys->get($this->conn('https://a.example/v1')));
        $this->assertNull($keys->get($this->conn('https://b.example/v1')));
    }

    public function testReplaceStoresEncryptedSensitiveValueUnderShortKey(): void {
        $c = $this->conn();
        $this->keys()->apply($c, ['action' => 'replace', 'value' => ' sk-123 ']);
        $name = ApiKeyStore::keyName($c);
        $this->assertSame('ai_key_' . substr($c->connectionFingerprint(), 0, 32), $name);
        $this->assertLessThanOrEqual(FakeAppConfigValues::KEY_MAX_LENGTH, strlen($name));
        $this->assertSame('enc:sk-123', $this->values->get('learning', $name));
        $this->assertTrue($this->values->sensitive["learning\0" . $name]);
    }

    public function testKeepChangesNothing(): void {
        $keys = $this->keys();
        $keys->apply($this->conn(), ['action' => 'replace', 'value' => 'sk-1']);
        $before = $this->values->values;
        $sets = $this->values->setCalls;
        $keys->apply($this->conn(), ['action' => 'keep']);
        $keys->apply($this->conn(), ['action' => 'keep', 'value' => 'ignored']);
        $this->assertSame($before, $this->values->values);
        $this->assertSame($sets, $this->values->setCalls);
    }

    public function testDeleteRemovesKey(): void {
        $keys = $this->keys();
        $keys->apply($this->conn(), ['action' => 'replace', 'value' => 'sk-1']);
        $keys->apply($this->conn(), ['action' => 'delete']);
        $this->assertNull($keys->get($this->conn()));
        $this->assertFalse($this->values->has('learning', ApiKeyStore::keyName($this->conn())));
    }

    public function testReplaceWithEmptyValueIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->keys()->apply($this->conn(), ['action' => 'replace', 'value' => '   ']);
    }

    public function testUnknownActionIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->keys()->apply($this->conn(), ['action' => 'rotate']);
    }

    public function testDisabledConnectionHasNoKey(): void {
        $this->values->set('learning', 'gemini_api_key', self::LEGACY);
        $this->assertNull($this->keys()->get(LlmConnection::fromArray(['provider' => 'disabled'])));
    }

    public function testGetFallsBackToLegacyPlaintextForGeminiOnly(): void {
        $this->values->set('learning', 'gemini_api_key', ' ' . self::LEGACY . ' ');
        $keys = $this->keys();
        $this->assertSame(self::LEGACY, $keys->get(ConnectionStore::legacyGemini(true)));
        $this->assertNull($keys->get($this->conn()));
    }

    public function testUndecryptableStoredKeyReadsAsNull(): void {
        $this->values->set('learning', ApiKeyStore::keyName($this->conn()), 'bad:xyz');
        $this->assertNull($this->keys()->get($this->conn()));
    }

    public function testDeleteOnLegacyGeminiAlsoRemovesPlaintext(): void {
        $this->values->set('learning', 'gemini_api_key', self::LEGACY);
        $keys = $this->keys();
        $keys->apply(ConnectionStore::legacyGemini(true), ['action' => 'delete']);
        $this->assertNull($keys->get(ConnectionStore::legacyGemini(true)));
        $this->assertFalse($this->values->has('learning', 'gemini_api_key'));
    }

    public function testReplaceOnLegacyGeminiRemovesPlaintextAfterVerifiedWrite(): void {
        $this->values->set('learning', 'gemini_api_key', self::LEGACY);
        $keys = $this->keys();
        $keys->apply(ConnectionStore::legacyGemini(true), ['action' => 'replace', 'value' => 'AIza-new']);
        $this->assertSame('AIza-new', $keys->get(ConnectionStore::legacyGemini(true)));
        $this->assertFalse($this->values->has('learning', 'gemini_api_key'));
    }

    public function testReplaceWhoseReadbackFailsThrowsAndLeavesNoBrokenKey(): void {
        $c = $this->conn();
        $name = ApiKeyStore::keyName($c);
        $this->values->readOverride = fn (string $app, string $key): ?string => $key === $name ? 'bad:corrupt' : null;
        try {
            $this->keys()->apply($c, ['action' => 'replace', 'value' => 'sk-1']);
            $this->fail('replace with failed readback did not throw');
        } catch (\RuntimeException) {
            $this->assertFalse($this->values->has('learning', $name));
        }
    }

    // (a)
    public function testMigrateMovesPlaintextToEncryptedKey(): void {
        $this->values->set('learning', 'gemini_api_key', self::LEGACY);
        $this->assertSame('migrated', $this->keys()->migrateLegacyGeminiKey());
        $this->assertFalse($this->values->has('learning', 'gemini_api_key'));
        $this->assertSame('enc:' . self::LEGACY, $this->values->get('learning', $this->targetKey()));
        $this->assertTrue($this->values->sensitive["learning\0" . $this->targetKey()]);
    }

    // (b)
    public function testMigrateWithExistingDecryptableTargetDeletesPlaintext(): void {
        $this->values->set('learning', 'gemini_api_key', self::LEGACY);
        $this->values->set('learning', $this->targetKey(), 'enc:AIza-already');
        $this->assertSame('already', $this->keys()->migrateLegacyGeminiKey());
        $this->assertFalse($this->values->has('learning', 'gemini_api_key'));
        $this->assertSame('enc:AIza-already', $this->values->get('learning', $this->targetKey()));
    }

    // (c)
    public function testMigrateKeepsPlaintextWhenExistingTargetCannotBeDecrypted(): void {
        $this->values->set('learning', 'gemini_api_key', self::LEGACY);
        $this->values->set('learning', $this->targetKey(), 'bad:broken');
        $this->assertSame('kept_plaintext', $this->keys()->migrateLegacyGeminiKey());
        $this->assertSame(self::LEGACY, $this->values->get('learning', 'gemini_api_key'));
        $this->assertSame('bad:broken', $this->values->get('learning', $this->targetKey()));
        // The key keeps working through the plaintext fallback.
        $this->assertSame(self::LEGACY, $this->keys()->get(ConnectionStore::legacyGemini(true)));
        $this->assertNoKeyMaterialLogged();
    }

    // (d)
    public function testMigrateSecondRunReportsAlready(): void {
        $this->values->set('learning', 'gemini_api_key', self::LEGACY);
        $keys = $this->keys();
        $this->assertSame('migrated', $keys->migrateLegacyGeminiKey());
        $this->assertSame('already', $keys->migrateLegacyGeminiKey());
        $this->assertSame('enc:' . self::LEGACY, $this->values->get('learning', $this->targetKey()));
    }

    public function testMigrateWithoutPlaintextReportsNone(): void {
        $this->assertSame('none', $this->keys()->migrateLegacyGeminiKey());
        $this->values->set('learning', 'gemini_api_key', '   ');
        $this->assertSame('none', $this->keys()->migrateLegacyGeminiKey());
        $this->assertFalse($this->values->has('learning', $this->targetKey()));
    }

    public function testMigrateKeepsPlaintextWhenReadbackFails(): void {
        $this->values->set('learning', 'gemini_api_key', self::LEGACY);
        $target = $this->targetKey();
        $this->values->readOverride = fn (string $app, string $key): ?string => $key === $target ? 'bad:corrupt' : null;
        $this->assertSame('kept_plaintext', $this->keys()->migrateLegacyGeminiKey());
        $this->assertSame(self::LEGACY, $this->values->get('learning', 'gemini_api_key'));
        $this->assertFalse($this->values->has('learning', $target));
        $this->assertNoKeyMaterialLogged();
    }

    public function testMigratedKeyIsFoundThroughConnectionStoreLoad(): void {
        $this->values->set('learning', 'ai_provider', 'gemini');
        $this->values->set('learning', 'ai_enabled', 'yes');
        $this->values->set('learning', 'gemini_api_key', self::LEGACY);
        $keys = $this->keys();
        $this->assertSame('migrated', $keys->migrateLegacyGeminiKey());
        // Plaintext is gone, so this answer can only come from the encrypted key.
        $this->assertSame(self::LEGACY, $keys->get($this->connections()->load()));
    }

    public function testMigrateTargetsGeminiEvenWhenOllamaIsActive(): void {
        $this->values->set('learning', 'ai_provider', 'ollama');
        $this->values->set('learning', 'ai_enabled', 'yes');
        $this->values->set('learning', 'gemini_api_key', self::LEGACY);
        $keys = $this->keys();
        $this->assertSame('migrated', $keys->migrateLegacyGeminiKey());
        $this->assertSame(self::LEGACY, $keys->get(ConnectionStore::legacyGemini(false)));
        $this->assertNull($keys->get($this->connections()->load()));
    }

    // Review A1/A2 (Codex P2): a failed replace must never cost the admin the working key.
    public function testReplaceWhoseEncryptionThrowsKeepsTheExistingKey(): void {
        $keys = $this->keys();
        $keys->apply($this->conn(), ['action' => 'replace', 'value' => 'sk-old']);
        try {
            $keys->apply($this->conn(), ['action' => 'replace', 'value' => 'sk-THROW']);
            $this->fail('failed encryption did not throw');
        } catch (\RuntimeException) {
            $this->assertSame('sk-old', $keys->get($this->conn()));
        }
    }

    public function testReplaceWhoseReadbackFailsRestoresTheExistingKey(): void {
        $keys = $this->keys();
        $keys->apply($this->conn(), ['action' => 'replace', 'value' => 'sk-old']);
        try {
            $keys->apply($this->conn(), ['action' => 'replace', 'value' => 'sk-CORRUPT']);
            $this->fail('failed readback did not throw');
        } catch (\RuntimeException) {
            $this->assertSame('sk-old', $keys->get($this->conn()));
            $this->assertTrue($this->values->sensitive["learning\0" . ApiKeyStore::keyName($this->conn())]);
        }
    }

    // Review A1/A2 (Codex P2): the readback must come from storage, not from the write's own cache entry.
    public function testReplaceThatWasNotPersistedFails(): void {
        $c = $this->conn();
        $this->values->lostWrites = [ApiKeyStore::keyName($c)];
        $this->expectException(\RuntimeException::class);
        $this->keys()->apply($c, ['action' => 'replace', 'value' => 'sk-1']);
    }

    public function testMigrationThatWasNotPersistedKeepsPlaintext(): void {
        $this->values->set('learning', 'gemini_api_key', self::LEGACY);
        $this->values->lostWrites = [$this->targetKey()];
        $this->assertSame('kept_plaintext', $this->keys()->migrateLegacyGeminiKey());
        $this->assertSame(self::LEGACY, $this->values->get('learning', 'gemini_api_key'));
        $this->assertFalse($this->values->has('learning', $this->targetKey()));
    }

    // Review A1/A2 (Codex P2): Nextcloud decrypts sensitive values inside getValueString() and throws there.
    public function testOuterDecryptionFailureFallsBackToPlaintextAndKeepsIt(): void {
        $this->values->set('learning', 'gemini_api_key', self::LEGACY);
        $this->values->set('learning', $this->targetKey(), 'enc:AIza-unreadable');
        $target = $this->targetKey();
        $this->values->readOverride = function (string $app, string $key) use ($target): ?string {
            if ($key === $target) {
                throw new \Exception('HMAC does not match.');
            }
            return null;
        };
        $keys = $this->keys();
        $this->assertSame(self::LEGACY, $keys->get(ConnectionStore::legacyGemini(true)));
        $this->assertSame('kept_plaintext', $keys->migrateLegacyGeminiKey());
        $this->assertSame(self::LEGACY, $this->values->get('learning', 'gemini_api_key'));
        $this->assertNoKeyMaterialLogged();
    }

    private function assertNoKeyMaterialLogged(): void {
        foreach ($this->logLines as $line) {
            $this->assertStringNotContainsString('AIza', $line);
            $this->assertStringNotContainsString('corrupt', $line);
            $this->assertStringNotContainsString('broken', $line);
        }
    }
}
