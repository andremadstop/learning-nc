<?php
declare(strict_types=1);
namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\SettingsController;
use OCA\Learning\Service\AuditCheckpointService;
use OCA\Learning\Service\KeyService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Codeberg #6: the personal settings content-language dropdown offers Arabic
 * (PersonalSettings.vue) and the frontend sends 'ar', but savePersonal()'s whitelist was
 * ['de','en','ru','']. The value was silently coerced to '' and the endpoint answered
 * 200 — the user picked a language, got no error, and nothing changed.
 *
 * Same class as [[feedback_request_payload_contracts]]: no gate can see a payload value
 * that the controller quietly drops; only a test at the request boundary catches it.
 *
 * NOTE on the deliberate gap: 'uk' and 'fr' stay OUT of this whitelist. Content language
 * drives lookups in learning_q_translations / learning_a_translations, whose CHECK
 * constraint and TranslationService::ALLOWED_LANGS both permit de/en/ru/ar only. Widening
 * it needs a migration tested on PostgreSQL AND MariaDB. Offering a language whose
 * translations can never be stored would trade a silent drop for a silent no-op.
 */
class SettingsControllerLanguageTest extends TestCase {

    private IConfig $config;
    /** @var array<string, string> */
    private array $written = [];

    private function makeController(): SettingsController {
        $this->config = $this->createMock(IConfig::class);
        $this->config->method('setUserValue')->willReturnCallback(
            function (string $userId, string $app, string $key, string $value): void {
                $this->written[$key] = $value;
            }
        );

        return new SettingsController(
            'learning',
            $this->createMock(IRequest::class),
            $this->config,
            $this->createMock(IDBConnection::class),
            $this->createMock(KeyService::class),
            $this->createMock(AuditCheckpointService::class),
            'alice'
        );
    }

    private function save(string $contentLanguage): string {
        $this->written = [];
        $controller = $this->makeController();
        $response = $controller->savePersonal('yes', '', 'yes', $contentLanguage);

        $this->assertSame(200, $response->getStatus());

        return $this->written['content_language'] ?? '__unset__';
    }

    /**
     * @dataProvider offeredLanguages
     */
    public function testEveryLanguageTheDropdownOffersIsActuallyStored(string $code): void {
        $this->assertSame($code, $this->save($code));
    }

    public static function offeredLanguages(): array {
        // Exactly the <option value="…"> set of the content-language select.
        return [['de'], ['en'], ['ru'], ['ar']];
    }

    public function testEmptyMeansOriginalContentAndStaysEmpty(): void {
        $this->assertSame('', $this->save(''));
    }

    public function testUnsupportedLanguageIsStillRejected(): void {
        $this->assertSame('', $this->save('klingon'));
    }

    public function testUkrainianIsStillRejectedUntilTheTranslationTablesAllowIt(): void {
        // Guards the deliberate gap above: if someone widens the whitelist without the
        // migration, this turns red and points at the reason.
        $this->assertSame('', $this->save('uk'));
    }
}
