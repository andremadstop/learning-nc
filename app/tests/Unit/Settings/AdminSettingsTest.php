<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Settings;

use OCA\Learning\Service\Llm\ApiKeyStore;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Settings\AdminSettings;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Review finding (HIGH): after the upgrade the Gemini key lives in the encrypted slot, so the
 * plaintext `gemini_api_key` no longer says whether a key is set.
 */
class AdminSettingsTest extends TestCase {
    /** @return array<string, array{?string, bool}> */
    public static function keys(): array {
        return ['a key is in force' => ['AIza-a', true], 'no key' => [null, false]];
    }

    /** @dataProvider keys */
    public function testTheFormReportsTheKeyTheGeminiConnectionUses(?string $key, bool $expected): void {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $name, string $default = ''): string => $name === 'gemini_api_key' ? 'stale-plaintext' : $default);
        $keys = $this->createMock(ApiKeyStore::class);
        $keys->method('get')->willReturnCallback(
            fn ($c): ?string => $c->connectionFingerprint() === ConnectionStore::legacyGemini(false)->connectionFingerprint() ? $key : null);

        $params = (new AdminSettings($config, $keys))->getForm()->getParams();

        $this->assertSame($expected, $params['gemini_api_key_set']);
    }
}
