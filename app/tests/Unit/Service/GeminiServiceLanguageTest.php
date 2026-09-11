<?php
declare(strict_types=1);
namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\GeminiService;
use OCA\Learning\Service\LlmService;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Codeberg #6, part 3: "some of the answers/text are not translated into Ukrainian".
 *
 * Two separate defects made the assistant answer a Ukrainian user in English:
 *
 *  1. buildSystemPrompt()'s $langMap only knew de/en/ru/ar. For 'uk' and 'fr' it fell
 *     through to 'English', so the prompt literally said "If unsure, default to English."
 *  2. chat() derived the language from the `content_language` user value alone, with a
 *     hard 'en' fallback. That setting is opt-in and empty for most users, and its own
 *     whitelist never accepted 'uk' in the first place — so the Nextcloud interface
 *     language, which IS set to Ukrainian on the reporter's instance, was ignored.
 *
 * These tests reach the private helpers through reflection on purpose: the public entry
 * point performs a network call, and the defect lives entirely in language resolution.
 */
class GeminiServiceLanguageTest extends TestCase {

    private IConfig $config;
    private GeminiService $service;

    protected function setUp(): void {
        $this->config = $this->createMock(IConfig::class);
        $this->service = new GeminiService(
            $this->config,
            $this->createMock(ICacheFactory::class),
            $this->createMock(IDBConnection::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(LlmService::class)
        );
    }

    private function invoke(string $method, array $args) {
        $ref = new \ReflectionMethod(GeminiService::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($this->service, $args);
    }

    /**
     * getUserValue() stub driven by a map of "app/key" => value; everything else
     * returns the default it was asked for.
     */
    private function stubUserValues(array $map): void {
        $this->config->method('getUserValue')->willReturnCallback(
            static function (string $userId, string $app, string $key, $default = '') use ($map) {
                return $map[$app . '/' . $key] ?? $default;
            }
        );
        $this->config->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, $default = '') use ($map) {
                return $map['app:' . $key] ?? $default;
            }
        );
    }

    // ── $langMap coverage ───────────────────────────────────────────────────

    public function testSystemPromptNamesUkrainianForUk(): void {
        $prompt = $this->invoke('buildSystemPrompt', ['uk']);
        $this->assertStringContainsString('default to Ukrainian', $prompt);
        $this->assertStringNotContainsString('default to English', $prompt);
    }

    public function testSystemPromptNamesFrenchForFr(): void {
        $prompt = $this->invoke('buildSystemPrompt', ['fr']);
        $this->assertStringContainsString('default to French', $prompt);
    }

    public function testSystemPromptStillNamesEnglishForUnknownCode(): void {
        $prompt = $this->invoke('buildSystemPrompt', ['zz']);
        $this->assertStringContainsString('default to English', $prompt);
    }

    // ── language resolution ─────────────────────────────────────────────────

    public function testContentLanguageWins(): void {
        $this->stubUserValues(['learning/content_language' => 'ru', 'core/lang' => 'uk']);
        $this->assertSame('ru', $this->invoke('resolveResponseLanguage', ['alice']));
    }

    public function testFallsBackToNextcloudInterfaceLanguage(): void {
        $this->stubUserValues(['learning/content_language' => '', 'core/lang' => 'uk']);
        $this->assertSame('uk', $this->invoke('resolveResponseLanguage', ['andrii']));
    }

    public function testRegionalInterfaceLanguageIsReducedToItsBaseCode(): void {
        // Nextcloud stores locales like "uk_UA" / "fr_CA" in core/lang.
        $this->stubUserValues(['learning/content_language' => '', 'core/lang' => 'uk_UA']);
        $this->assertSame('uk', $this->invoke('resolveResponseLanguage', ['andrii']));
    }

    public function testUnsupportedInterfaceLanguageFallsThroughToAppDefault(): void {
        $this->stubUserValues([
            'learning/content_language' => '',
            'core/lang' => 'pl',
            'app:default_language' => 'de',
        ]);
        $this->assertSame('de', $this->invoke('resolveResponseLanguage', ['bob']));
    }

    public function testFallsBackToEnglishWhenNothingIsSet(): void {
        $this->stubUserValues([]);
        $this->assertSame('en', $this->invoke('resolveResponseLanguage', ['carol']));
    }

    // ── shared, not copied ──────────────────────────────────────────────────

    /**
     * NoteGeneratorService had its own verbatim copy of both the `?: 'en'` line and a
     * language-name map, and its copy was missing fr/uk in exactly the same way. The
     * assistant's "Create summary" button reaches it, so a Ukrainian user got a
     * translated button that produced an English summary.
     *
     * v5.3.1 settled how this repo handles a duplicated helper: QuestionService::
     * hasPoolAccess was made public rather than copied a sixth time. Both helpers are
     * public for the same reason — a private one here is the next copy in waiting.
     */
    public function testLanguageResolutionIsPublicSoCallersNeedNoCopy(): void {
        $this->assertTrue((new \ReflectionMethod(GeminiService::class, 'resolveResponseLanguage'))->isPublic());
        $this->assertTrue((new \ReflectionMethod(GeminiService::class, 'languageName'))->isPublic());
    }

    /**
     * @dataProvider languageNames
     */
    public function testLanguageNameCoversEverySupportedLanguage(string $code, string $name): void {
        $this->assertSame($name, $this->service->languageName($code));
    }

    public static function languageNames(): array {
        return [
            ['de', 'German'],
            ['en', 'English'],
            ['fr', 'French'],
            ['ru', 'Russian'],
            ['ar', 'Arabic'],
            ['uk', 'Ukrainian'],
            ['zz', 'English'],
        ];
    }
}
