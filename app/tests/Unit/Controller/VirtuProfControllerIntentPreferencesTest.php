<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\VirtuProfController;
use OCA\Learning\Service\AiChatMemoryService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\GeminiService;
use OCA\Learning\Service\LernplanService;
use OCA\Learning\Service\Llm\LlmGateway;
use OCA\Learning\Service\NoteGeneratorService;
use OCA\Learning\Service\PoolService;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\RagContextService;
use OCA\Learning\Service\SupportTicketService;
use OCA\Learning\Service\TelosService;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Audit #16: the keyword intents took over ordinary learning questions (a file was written or a
 * support ticket filed, and no answer came back).
 * Audit #17: the 'Bot sounds' switch was sent with every save and silently dropped.
 */
#[AllowMockObjectsWithoutExpectations]
class VirtuProfControllerIntentPreferencesTest extends TestCase {
    /** @var array<string, string> user values by key, for user 'alice' */
    private array $userValues = [];

    private function controller(): VirtuProfController {
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturnCallback(
            fn (string $uid, string $app, string $key, string $default = '') => $this->userValues[$key] ?? $default
        );
        $config->method('setUserValue')->willReturnCallback(function (string $uid, string $app, string $key, $value): void {
            $this->userValues[$key] = (string)$value;
        });
        $config->method('getAppValue')->willReturnCallback(static fn (string $app, string $key, string $default = '') => $default);
        $config->method('getUserKeys')->willReturn([]);

        return new VirtuProfController(
            'learning',
            $this->createMock(IRequest::class),
            $config,
            'alice',
            $this->createMock(GeminiService::class),
            $this->createMock(RagContextService::class),
            $this->createMock(AiChatMemoryService::class),
            $this->createMock(NoteGeneratorService::class),
            $this->createMock(LernplanService::class),
            $this->createMock(SupportTicketService::class),
            $this->createMock(TelosService::class),
            $this->createMock(CourseService::class),
            $this->createMock(PoolService::class),
            $this->createMock(QuestionService::class),
            $this->createMock(LlmGateway::class)
        );
    }

    private function intent(string $method, string $message): bool {
        return (bool)(new \ReflectionMethod(VirtuProfController::class, $method))
            ->invoke($this->controller(), mb_strtolower($message));
    }

    /** @return array<string, array{string}> */
    public static function ordinaryQuestions(): array {
        return [
            'ping problem' => ['Mein Ping zwischen den VLANs geht nicht, warum?'],
            'dns not working' => ['DNS resolution not working after NAT – why?'],
            'broken route' => ['Die statische Route ist kaputt, oder? Ich verstehe es nicht.'],
            'progress question' => ['Wie kann ich meinen Fortschritt bei Subnetting verbessern?'],
            'plan question' => ['Was gehört in einen guten Lernplan für Security+?'],
            'learning plan question' => ['What makes a good learning plan for Network+?'],
        ];
    }

    #[DataProvider('ordinaryQuestions')]
    public function testOrdinaryQuestionsAreNoIntent(string $message): void {
        $this->assertFalse($this->intent('isFileIntent', $message), 'file intent: ' . $message);
        $this->assertFalse($this->intent('isTicketIntent', $message), 'ticket intent: ' . $message);
    }

    /** @return array<string, array{string, string}> */
    public static function explicitCommands(): array {
        return [
            'lernplan erstellen' => ['isFileIntent', 'Bitte Lernplan erstellen'],
            'create learning plan' => ['isFileIntent', 'Please create learning plan for next week'],
            'zusammenfassung erstellen' => ['isFileIntent', 'Zusammenfassung erstellen zu VLANs'],
            'create summary' => ['isFileIntent', 'Create summary of OSPF'],
            'fortschritt anzeigen' => ['isFileIntent', 'Fortschritt anzeigen'],
            'bug melden' => ['isTicketIntent', 'Ich möchte einen Bug melden'],
            'report a bug' => ['isTicketIntent', 'I want to report a bug'],
            'ticket erstellen' => ['isTicketIntent', 'Ticket erstellen bitte'],
        ];
    }

    #[DataProvider('explicitCommands')]
    public function testExplicitCommandsStillWork(string $method, string $message): void {
        $this->assertTrue($this->intent($method, $message), $message);
    }

    public function testBotSoundsSurviveAReload(): void {
        $this->assertFalse($this->controller()->getState()->getData()['bot_sounds_enabled'], 'off by default');

        $this->assertSame(200, $this->controller()->savePreferences(botSoundsEnabled: true)->getStatus());
        $this->assertTrue($this->controller()->getState()->getData()['bot_sounds_enabled']);

        $this->controller()->savePreferences(botSoundsEnabled: false);
        $this->assertFalse($this->controller()->getState()->getData()['bot_sounds_enabled']);
    }

    public function testSavingOtherPreferencesLeavesBotSoundsAlone(): void {
        $this->controller()->savePreferences(botSoundsEnabled: true);
        $this->controller()->savePreferences(ttsEnabled: false);

        $this->assertTrue($this->controller()->getState()->getData()['bot_sounds_enabled']);
    }
}
