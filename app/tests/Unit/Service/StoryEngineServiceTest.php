<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\StoryProgressMapper;
use OCA\Learning\Service\CampaignGraphService;
use OCA\Learning\Service\GeminiService;
use OCA\Learning\Service\StoryEngineService;
use OCA\Learning\Service\TelosService;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * AUDIT v5.2.1 (consent-fix B): StoryEngineService::callGeminiForStory() (private) must return ''
 * WITHOUT calling GeminiService::generateNote() when TelosService::hasAiConsent() is false. Tested
 * through the public generateNarrative() caller, which falls back to the scene's static narrative
 * whenever the Gemini call comes back empty.
 *
 * The consent gate sits AFTER the narratorEnabled + AI-availability checks inside
 * callGeminiForStory()'s caller chain, so both must be satisfied (narrator_mode truthy, an available
 * AI connection) for the flow to reach the consent check at all — otherwise the test would only
 * be exercising the earlier (unrelated) fallbacks.
 *
 * @group audit-consent-fix-b
 */
class StoryEngineServiceTest extends TestCase {
    /** @var StoryProgressMapper&\PHPUnit\Framework\MockObject\MockObject */
    private $progressMapperMock;
    /** @var IDBConnection&\PHPUnit\Framework\MockObject\MockObject */
    private $dbMock;
    /** @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $loggerMock;
    /** @var GeminiService&\PHPUnit\Framework\MockObject\MockObject */
    private $geminiServiceMock;
    /** @var IConfig&\PHPUnit\Framework\MockObject\MockObject */
    private $configMock;
    /** @var ICacheFactory&\PHPUnit\Framework\MockObject\MockObject */
    private $cacheFactoryMock;
    /** @var CampaignGraphService&\PHPUnit\Framework\MockObject\MockObject */
    private $graphServiceMock;
    /** @var TelosService&\PHPUnit\Framework\MockObject\MockObject */
    private $telosMock;

    protected function setUp(): void {
        $this->progressMapperMock = $this->createMock(StoryProgressMapper::class);
        $this->dbMock = $this->createMock(IDBConnection::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->geminiServiceMock = $this->createMock(GeminiService::class);
        $this->configMock = $this->createMock(IConfig::class);
        $this->cacheFactoryMock = $this->createMock(ICacheFactory::class);
        $this->graphServiceMock = $this->createMock(CampaignGraphService::class);
        $this->telosMock = $this->createMock(TelosService::class);
    }

    private function makeService(): StoryEngineService {
        return new StoryEngineService(
            $this->progressMapperMock,
            $this->dbMock,
            $this->loggerMock,
            $this->geminiServiceMock,
            $this->configMock,
            $this->cacheFactoryMock,
            $this->graphServiceMock,
            $this->telosMock
        );
    }

    /**
     * Stub what generateNarrative() checks before reaching the consent gate: an available AI
     * connection (5.7.0: GeminiService::isAvailable(), i.e. the connection snapshot — the legacy
     * plaintext `gemini_api_key` is empty here on purpose, it is migrated away) and
     * `content_language` (getUserValue, any value is fine — falls back to 'de' via `?:`).
     */
    private function stubNarratorConfigGates(bool $aiAvailable = true): void {
        $this->geminiServiceMock->method('isAvailable')->willReturn($aiAvailable);
        $this->configMock->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = ''): string {
                return $key === 'gemini_api_key' ? '' : $default;
            }
        );
        $this->configMock->method('getUserValue')->willReturn('de');
    }

    private function narratorScene(): array {
        return [
            'id' => 'scene-incident-1',
            'title' => 'Der erste Vorfall',
            'narrative' => 'Statischer Erzaehltext fuer diese Szene.',
            'narrator_mode' => true,
        ];
    }

    private function narratorCampaign(): array {
        return [
            'campaign_id' => 'demo-campaign',
            'focus_areas' => ['security'],
            'narrator_style' => 'neutral und informativ',
        ];
    }

    public function testGenerateNarrativeFallsBackToStaticWithoutConsent(): void {
        $this->stubNarratorConfigGates();
        $this->telosMock->method('hasAiConsent')->with('user-A')->willReturn(false);

        // The consent gate lives inside the private callGeminiForStory(); it must prevent
        // generateNote() from ever being reached.
        $this->geminiServiceMock->expects($this->never())->method('generateNote');

        $narrative = $this->makeService()->generateNarrative(
            $this->narratorScene(),
            $this->narratorCampaign(),
            'security',
            [],
            'user-A'
        );

        $this->assertSame('Statischer Erzaehltext fuer diese Szene.', $narrative);
    }

    public function testGenerateNarrativeCallsGeminiWhenConsentGranted(): void {
        $this->stubNarratorConfigGates();
        $this->telosMock->method('hasAiConsent')->with('user-A')->willReturn(true);

        $this->geminiServiceMock->expects($this->once())->method('generateNote')
            ->willReturn('Dynamisch generierter Erzaehltext.');

        $narrative = $this->makeService()->generateNarrative(
            $this->narratorScene(),
            $this->narratorCampaign(),
            'security',
            [],
            'user-A'
        );

        $this->assertSame('Dynamisch generierter Erzaehltext.', $narrative);
    }

    /** 5.7.0 Task B5: with AI switched off (no usable connection) nothing is sent. */
    public function testGenerateNarrativeStaysStaticWhenAiIsNotAvailable(): void {
        $this->stubNarratorConfigGates(false);
        $this->telosMock->method('hasAiConsent')->willReturn(true);
        $this->geminiServiceMock->expects($this->never())->method('generateNote');

        $narrative = $this->makeService()->generateNarrative(
            $this->narratorScene(), $this->narratorCampaign(), 'security', [], 'user-A');

        $this->assertSame('Statischer Erzaehltext fuer diese Szene.', $narrative);
    }

    /**
     * 5.7.0 Task B5 (spec 2.4): a consent the gateway refuses after the early check makes
     * callGeminiForStory() answer '' explicitly — the static fallback — not an exception the four
     * callers happen to swallow.
     */
    public function testALateConsentRefusalMakesTheStoryCallAnswerEmpty(): void {
        $this->telosMock->method('hasAiConsent')->willReturn(true);
        $this->geminiServiceMock->method('generateNote')
            ->willThrowException(new \OCA\Learning\Service\Llm\LlmConsentException());

        $method = new \ReflectionMethod(StoryEngineService::class, 'callGeminiForStory');

        $this->assertSame('', $method->invoke($this->makeService(), 'system', 'user', 'user-A', 250));
    }
}
