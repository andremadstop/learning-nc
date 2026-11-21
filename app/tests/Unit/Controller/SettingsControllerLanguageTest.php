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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

/**
 * Codeberg #6: the personal settings content-language dropdown offers Arabic
 * (PersonalSettings.vue) and the frontend sends 'ar', but savePersonal()'s whitelist was
 * ['de','en','ru','']. The value was silently coerced to '' and the endpoint answered
 * 200 — the user picked a language, got no error, and nothing changed.
 *
 * Same class as [[feedback_request_payload_contracts]]: no gate can see a payload value
 * that the controller quietly drops; only a test at the request boundary catches it.
 *
 * 5.18.0: French and Ukrainian, too. They were left out on purpose while the CHECK constraint on
 * the translation tables (PostgreSQL instances upgraded through 3.2) allowed de/en/ru/ar only;
 * Version011600Date20261121140000 widens it, and the whitelist is now
 * TranslationService::ALLOWED_LANGS — the six interface languages.
 */
#[AllowMockObjectsWithoutExpectations]
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
            $this->createMock(\OCA\Learning\Service\Llm\ConnectionStore::class),
            $this->createMock(\OCA\Learning\Service\Llm\ApiKeyStore::class),
            $this->createMock(\OCA\Learning\Service\RoleService::class),
            'alice'
        );
    }

    private function save(string $contentLanguage): string {
        $this->written = [];
        $controller = $this->makeController();
        $response = $controller->savePersonal('yes', 'yes', $contentLanguage);

        $this->assertSame(200, $response->getStatus());

        return $this->written['content_language'] ?? '__unset__';
    }

    #[DataProvider('offeredLanguages')]
    public function testEveryLanguageTheDropdownOffersIsActuallyStored(string $code): void {
        $this->assertSame($code, $this->save($code));
    }

    public static function offeredLanguages(): array {
        // Exactly the <option value="…"> set of the content-language select.
        return [['de'], ['en'], ['fr'], ['ru'], ['ar'], ['uk']];
    }

    public function testEmptyMeansOriginalContentAndStaysEmpty(): void {
        $this->assertSame('', $this->save(''));
    }

    public function testUnsupportedLanguageIsStillRejected(): void {
        $this->assertSame('', $this->save('klingon'));
    }

    /** The whitelist is the translation languages, which are the app's interface languages. */
    public function testTheContentLanguagesAreTheInterfaceLanguages(): void {
        $interface = (new \ReflectionClassConstant(\OCA\Learning\Controller\VirtuProfController::class, 'ALLOWED_INTERFACE_LANGUAGES'))->getValue();
        $this->assertSame(array_values(array_diff($interface, [''])), \OCA\Learning\Service\TranslationService::ALLOWED_LANGS);
        $this->assertSame(array_map(static fn (array $row): string => $row[0], self::offeredLanguages()), \OCA\Learning\Service\TranslationService::ALLOWED_LANGS);
    }

    /**
     * Audit #12/#61/#218: 'UI Language' was stored and never read. An old client may still send
     * it; it is accepted and not stored, and the settings no longer report it.
     */
    public function testUiLanguageIsNeitherStoredNorReported(): void {
        $this->written = [];
        $controller = $this->makeController();
        $this->config->method('getUserValue')->willReturnArgument(3);

        $response = $controller->savePersonal('yes', 'yes', 'de', 'yes', 'no', 'yes', 'en');

        $this->assertSame(200, $response->getStatus());
        $this->assertArrayNotHasKey('ui_language', $this->written);
        $this->assertArrayNotHasKey('ui_language', $controller->getPersonal()->getData());
    }
}
