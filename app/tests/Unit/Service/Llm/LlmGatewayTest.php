<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service\Llm;

require_once __DIR__ . '/../../../Support/RecordingLlmHttp.php';

use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\Llm\ApiKeyStore;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\ConsentService;
use OCA\Learning\Service\Llm\LlmBudget;
use OCA\Learning\Service\Llm\LlmBudgetTicket;
use OCA\Learning\Service\Llm\LlmConnection;
use OCA\Learning\Service\Llm\LlmConsentException;
use OCA\Learning\Service\Llm\LlmGateway;
use OCA\Learning\Service\Llm\LlmOutputNormalizer;
use OCA\Learning\Service\Llm\LlmProviderException;
use OCA\Learning\Service\Llm\LlmPurpose;
use OCA\Learning\Service\Llm\LlmRequest;
use OCA\Learning\Service\Llm\LlmSession;
use OCA\Learning\Tests\Support\RecordingLlmHttp;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Task B3: the one send path. The adapters are the real Gemini/Ollama adapters over a recording
 * HTTP client, so "the adapter is never called" means: not a single request left the instance.
 */
class LlmGatewayTest extends TestCase {
    use RecordingLlmHttp;

    private const PROMPT_MARKER = 'PROMPT-MARKER-7f3a';
    private const KEY_MARKER = 'KEY-MARKER-9c1d';
    private const GEMINI_HOST = 'generativelanguage.googleapis.com';
    private const OLLAMA_URL = 'http://ollama.internal:11434';
    private const DNS = [
        self::GEMINI_HOST => ['142.250.185.106'],
        'ollama.internal' => ['10.0.0.5'],
        'api.openai.com' => ['104.18.7.192'],
        'api.anthropic.com' => ['160.79.104.10'],
    ];

    private ConnectionStore&MockObject $store;
    private ConsentService&MockObject $consent;
    private LlmBudget&MockObject $budget;
    private ApiKeyStore&MockObject $keys;
    private AuditService&MockObject $audit;
    private LoggerInterface&MockObject $logger;

    /** @var list<array{string, string, array<string, mixed>}> */
    private array $auditEvents = [];
    /** @var list<array{string, string, array<mixed>}> */
    private array $logLines = [];

    protected function setUp(): void {
        $this->calls = [];
        $this->auditEvents = [];
        $this->logLines = [];
        $this->store = $this->createMock(ConnectionStore::class);
        $this->consent = $this->createMock(ConsentService::class);
        $this->budget = $this->createMock(LlmBudget::class);
        $this->keys = $this->createMock(ApiKeyStore::class);
        $this->keys->method('get')->willReturn(self::KEY_MARKER);
        $this->audit = $this->createMock(AuditService::class);
        $this->audit->method('logEvent')->willReturnCallback(function (string $event, string $uid, array $ctx = []): void {
            $this->auditEvents[] = [$event, $uid, $ctx];
        });
        $this->logger = $this->createMock(LoggerInterface::class);
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $level) {
            $this->logger->method($level)->willReturnCallback(function (string|\Stringable $msg, array $ctx = []) use ($level): void {
                $this->logLines[] = [$level, (string)$msg, $ctx];
            });
        }
        $this->logger->method('log')->willReturnCallback(function ($level, string|\Stringable $msg, array $ctx = []): void {
            $this->logLines[] = [(string)$level, (string)$msg, $ctx];
        });
    }

    // ---------------------------------------------------------------- helpers

    private static function gemini(): LlmConnection {
        return ConnectionStore::legacyGemini(true);
    }

    private static function ollama(): LlmConnection {
        return LlmConnection::fromArray([
            'enabled' => true,
            'provider' => 'ollama',
            'baseUrl' => self::OLLAMA_URL,
            'model' => 'llama3',
            'allowInternalNetwork' => true,
        ]);
    }

    private function geminiOk(string $text = 'Antwort'): \OCP\Http\Client\IResponse {
        return $this->httpResponse(200, (string)json_encode([
            'candidates' => [['content' => ['parts' => [['text' => $text]]]]],
            'usageMetadata' => ['promptTokenCount' => 11, 'candidatesTokenCount' => 7],
        ]));
    }

    private function ollamaOk(string $text = 'Antwort'): \OCP\Http\Client\IResponse {
        return $this->httpResponse(200, (string)json_encode(['response' => $text, 'prompt_eval_count' => 5, 'eval_count' => 3]));
    }

    /** @param list<\OCP\Http\Client\IResponse|\Throwable> $outcomes */
    private function gateway(array $outcomes = []): LlmGateway {
        return new LlmGateway(
            $this->store,
            $this->consent,
            $this->budget,
            $this->recordingHttp(self::DNS, $outcomes),
            $this->keys,
            new LlmOutputNormalizer(),
            $this->audit,
            $this->logger,
        );
    }

    private function ticket(?string $uid = 'alice', LlmPurpose $p = LlmPurpose::Chat): LlmBudgetTicket {
        return new LlmBudgetTicket($uid, $p, $uid !== null ? 'u:' . $uid . ':inflight' : null, $uid !== null ? 1 : null, $uid !== null ? 42 : null);
    }

    /** Consent valid, AI on, budget available; the stored connection is $c. */
    private function happy(LlmConnection $c, int $epoch = 0): void {
        $this->store->method('load')->willReturn($c);
        $this->store->method('isEnabledNow')->willReturn(true);
        $this->consent->method('epoch')->willReturn($epoch);
        $this->consent->method('isValidFor')->willReturn(true);
        $this->budget->method('reserve')->willReturnCallback(fn(?string $uid, LlmPurpose $p) => $this->ticket($uid, $p));
    }

    private static function request(string $user = 'Was ist ein Subnetz?'): LlmRequest {
        return new LlmRequest('System', $user, 0.5, 400);
    }

    private function assertNothingSent(): void {
        $this->assertSame([], $this->calls, 'no request may leave the instance');
    }

    private function dump(): string {
        return (string)json_encode([$this->auditEvents, $this->logLines], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    // ---------------------------------------------------------------- begin()

    public function testBeginWithoutConsentThrowsBeforeAnythingIsSent(): void {
        $this->store->method('load')->willReturn(self::gemini());
        $this->store->method('isEnabledNow')->willReturn(true);
        $this->consent->method('isValidFor')->willReturn(false);
        $this->budget->expects($this->never())->method('reserve');

        try {
            $this->gateway([$this->geminiOk()])->begin('alice', LlmPurpose::Chat);
            $this->fail('expected LlmConsentException');
        } catch (LlmConsentException $e) {
            $this->assertSame('consent', $e->category);
        }
        $this->assertNothingSent();
    }

    public function testBeginWhenAiIsOffThrowsDisabled(): void {
        $this->store->method('load')->willReturn(LlmConnection::fromArray(['provider' => 'disabled']));
        $this->consent->expects($this->never())->method('isValidFor');

        $e = $this->assertProviderCategory('disabled', fn() => $this->gateway()->begin('alice', LlmPurpose::Chat));
        $this->assertNotInstanceOf(LlmConsentException::class, $e);
    }

    public function testBeginReadsTheEpochBeforeCheckingConsentAndKeepsIt(): void {
        $order = [];
        $this->store->method('load')->willReturn(self::gemini());
        $this->consent->method('epoch')->willReturnCallback(function () use (&$order): int {
            $order[] = 'epoch';
            return 4;
        });
        $this->consent->method('isValidFor')->willReturnCallback(function () use (&$order): bool {
            $order[] = 'valid';
            return true;
        });

        $session = $this->gateway()->begin('alice', LlmPurpose::Chat);

        // a revocation between the two reads is then seen by one of them
        $this->assertSame(['epoch', 'valid'], $order);
        $this->assertSame(4, $session->consentEpoch);
        $this->assertSame('alice', $session->subjectUserId);
        $this->assertSame(LlmPurpose::Chat, $session->purpose);
        $this->assertSame(self::gemini()->recipientFingerprint(), $session->connection->recipientFingerprint());
    }

    /** @return array<string, array{LlmPurpose}> */
    public static function purposesNeedingASubject(): array {
        $out = [];
        foreach (LlmPurpose::cases() as $p) {
            if ($p !== LlmPurpose::AdminTest) {
                $out[$p->value] = [$p];
            }
        }
        return $out;
    }

    /** @dataProvider purposesNeedingASubject */
    public function testOnlyAdminTestMayRunWithoutSubject(LlmPurpose $purpose): void {
        $this->happy(self::gemini());
        foreach ([null, ''] as $subject) {
            try {
                $this->gateway()->begin($subject, $purpose);
                $this->fail('expected InvalidArgumentException for ' . var_export($subject, true));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAdminTestWithoutSubjectNeedsNoConsent(): void {
        $this->store->method('load')->willReturn(self::gemini());
        $this->store->method('isEnabledNow')->willReturn(true);
        $this->consent->expects($this->never())->method('isValidFor');
        $this->consent->expects($this->never())->method('epoch');
        $this->budget->expects($this->once())->method('reserve')->with(null, LlmPurpose::AdminTest)
            ->willReturn($this->ticket(null, LlmPurpose::AdminTest));

        $gw = $this->gateway([$this->geminiOk('OK')]);
        $result = $gw->send($gw->begin(null, LlmPurpose::AdminTest), self::request());

        $this->assertSame('OK', $result->text);
        $this->assertCount(1, $this->calls);
    }

    // ---------------------------------------------------------------- send()

    public function testSendWithConsentCallsTheProviderExactlyOnce(): void {
        $this->happy(self::gemini());
        $this->budget->expects($this->once())->method('release');

        $gw = $this->gateway([$this->geminiOk('Ein Subnetz ist …')]);
        $result = $gw->send($gw->begin('alice', LlmPurpose::Chat), self::request());

        $this->assertSame('Ein Subnetz ist …', $result->text);
        $this->assertSame(11, $result->tokensIn);
        $this->assertSame(7, $result->tokensOut);
        $this->assertCount(1, $this->calls);
        $this->assertStringStartsWith('https://' . self::GEMINI_HOST . '/', $this->calls[0][0]);
    }

    public function testSendRechecksConsentFromTheDatabase(): void {
        $this->store->method('load')->willReturn(self::gemini());
        $this->store->method('isEnabledNow')->willReturn(true);
        $this->consent->method('epoch')->willReturn(0);
        $this->consent->method('isValidFor')->willReturnOnConsecutiveCalls(true, false);
        $this->budget->expects($this->never())->method('reserve');

        $gw = $this->gateway([$this->geminiOk()]);
        $session = $gw->begin('alice', LlmPurpose::Chat);

        $this->expectException(LlmConsentException::class);
        try {
            $gw->send($session, self::request());
        } finally {
            $this->assertNothingSent();
        }
    }

    public function testSwitchedOffAfterBeginSendsNothing(): void {
        $this->store->method('load')->willReturn(self::gemini());
        $this->store->method('isEnabledNow')->willReturn(false);
        $this->consent->method('isValidFor')->willReturn(true);
        $this->budget->expects($this->never())->method('reserve');

        $gw = $this->gateway([$this->geminiOk()]);
        $session = $gw->begin('alice', LlmPurpose::Chat);

        $e = $this->assertProviderCategory('disabled', fn() => $gw->send($session, self::request()));
        $this->assertNotInstanceOf(LlmConsentException::class, $e);
        $this->assertNothingSent();
    }

    /** Review Focus 3: a revocation while the context is built raises the epoch. */
    public function testRevocationBetweenBeginAndSendSendsNothing(): void {
        $this->store->method('load')->willReturn(self::gemini());
        $this->store->method('isEnabledNow')->willReturn(true);
        $this->consent->method('epoch')->willReturnOnConsecutiveCalls(0, 1);
        // even a consent that looks valid again (granted anew in between) does not count for this session
        $this->consent->method('isValidFor')->willReturn(true);
        $this->budget->expects($this->never())->method('reserve');

        $gw = $this->gateway([$this->geminiOk()]);
        $session = $gw->begin('alice', LlmPurpose::WeeklyNote);

        $this->expectException(LlmConsentException::class);
        try {
            $gw->send($session, self::request());
        } finally {
            $this->assertNothingSent();
        }
    }

    public function testSessionNotCreatedByBeginIsRefusedForASubject(): void {
        $this->happy(self::gemini());
        $this->budget->expects($this->never())->method('reserve');

        $this->expectException(LlmConsentException::class);
        try {
            $this->gateway([$this->geminiOk()])->send(new LlmSession(self::gemini(), 'alice', LlmPurpose::Chat), self::request());
        } finally {
            $this->assertNothingSent();
        }
    }

    /** Review Focus 1: the admin switches the backend while a chat is running. */
    public function testConfigChangeAfterBeginUsesTheSnapshot(): void {
        $this->store->method('load')->willReturnOnConsecutiveCalls(self::gemini(), self::ollama());
        $this->store->method('isEnabledNow')->willReturn(true);
        $this->consent->method('epoch')->willReturn(0);
        $checked = [];
        $this->consent->method('isValidFor')->willReturnCallback(function (string $uid, LlmConnection $c) use (&$checked): bool {
            $checked[] = $c->provider;
            return true;
        });
        $this->budget->method('reserve')->willReturn($this->ticket());

        $gw = $this->gateway([$this->geminiOk('vom Snapshot')]);
        $session = $gw->begin('alice', LlmPurpose::Chat);
        $result = $gw->send($session, self::request());

        $this->assertSame('vom Snapshot', $result->text);
        $this->assertCount(1, $this->calls);
        $this->assertStringStartsWith('https://' . self::GEMINI_HOST . '/', $this->calls[0][0]);
        // consent was checked for the recipient that receives the data, both times
        $this->assertSame(['gemini', 'gemini'], $checked);
    }

    public function testOllamaSnapshotIsRoutedToOllama(): void {
        $this->happy(self::ollama());

        $gw = $this->gateway([$this->ollamaOk('lokal')]);
        $result = $gw->send($gw->begin('alice', LlmPurpose::Chat), self::request());

        $this->assertSame('lokal', $result->text);
        $this->assertCount(1, $this->calls);
        $this->assertSame(self::OLLAMA_URL . '/api/generate', $this->calls[0][0]);
    }

    /** @return array<string, array{LlmConnection}> */
    public static function notSelectableIn570(): array {
        return [
            'openai_compatible' => [LlmConnection::fromArray([
                'enabled' => true, 'provider' => 'openai_compatible', 'preset' => 'openai',
                'baseUrl' => 'https://api.openai.com/v1', 'model' => 'gpt-4o-mini',
            ])],
            'anthropic' => [LlmConnection::fromArray([
                'enabled' => true, 'provider' => 'anthropic',
                'baseUrl' => 'https://api.anthropic.com/v1', 'model' => 'claude-sonnet-4-5',
            ])],
        ];
    }

    /**
     * 5.7.0 scope: only Gemini and Ollama are routed. A snapshot naming another backend (built
     * directly, since ConnectionStore::load() already reads it as disabled) fails as config —
     * before any budget is used and without a request.
     *
     * @dataProvider notSelectableIn570
     */
    public function testOnlyGeminiAndOllamaAreRouted(LlmConnection $c): void {
        $this->store->method('isEnabledNow')->willReturn(true);
        $this->consent->method('epoch')->willReturn(0);
        $this->consent->method('isValidFor')->willReturn(true);
        $this->budget->expects($this->never())->method('reserve');

        $e = $this->assertProviderCategory('config', fn() => $this->gateway([$this->geminiOk(), $this->ollamaOk()])
            ->send(new LlmSession($c, 'alice', LlmPurpose::Chat, 0), self::request()));
        $this->assertNotInstanceOf(LlmConsentException::class, $e);
        $this->assertNothingSent();
    }

    public function testExhaustedBudgetSendsNothing(): void {
        $this->store->method('load')->willReturn(self::gemini());
        $this->store->method('isEnabledNow')->willReturn(true);
        $this->consent->method('isValidFor')->willReturn(true);
        $this->budget->method('reserve')->willThrowException(new LlmProviderException('rate_limit'));
        $this->budget->expects($this->never())->method('release');

        $gw = $this->gateway([$this->geminiOk()]);
        $session = $gw->begin('alice', LlmPurpose::Chat);

        $this->assertProviderCategory('rate_limit', fn() => $gw->send($session, self::request()));
        $this->assertNothingSent();
    }

    public function testBudgetIsReservedForSubjectAndPurposeAndReleasedAfterAFailure(): void {
        $this->store->method('load')->willReturn(self::gemini());
        $this->store->method('isEnabledNow')->willReturn(true);
        $this->consent->method('isValidFor')->willReturn(true);
        $ticket = $this->ticket('alice', LlmPurpose::Note);
        $this->budget->expects($this->once())->method('reserve')->with('alice', LlmPurpose::Note)->willReturn($ticket);
        $this->budget->expects($this->once())->method('release')->with($this->identicalTo($ticket));

        $gw = $this->gateway([$this->httpResponse(503, '{"error":"overloaded"}')]);
        $session = $gw->begin('alice', LlmPurpose::Note);

        $this->assertProviderCategory('overloaded', fn() => $gw->send($session, self::request()));
        $this->assertCount(1, $this->calls, 'never a retry');
    }

    public function testAFailingReleaseNeitherMasksTheResultNorTheError(): void {
        $this->happy(self::gemini());
        $this->budget->method('release')->willThrowException(new \RuntimeException('db gone'));

        $gw = $this->gateway([$this->geminiOk('trotzdem'), $this->httpResponse(401, '{}')]);
        $this->assertSame('trotzdem', $gw->send($gw->begin('alice', LlmPurpose::Chat), self::request())->text);
        $this->assertProviderCategory('auth', fn() => $gw->send($gw->begin('alice', LlmPurpose::Chat), self::request()));
    }

    // ---------------------------------------------------------------- metric + audit

    public function testMetricAndAuditCarryMetadataOnly(): void {
        $this->happy(self::gemini());
        $prompt = 'Bitte erkläre ' . self::PROMPT_MARKER;
        // the provider echoes the prompt (and a key) in its answer and in an error body
        $gw = $this->gateway([
            $this->geminiOk('Echo: ' . self::PROMPT_MARKER),
            $this->httpResponse(400, '{"error":"bad ' . self::PROMPT_MARKER . ' ' . self::KEY_MARKER . '"}'),
        ]);

        $gw->send($gw->begin('alice', LlmPurpose::Chat), new LlmRequest('sys ' . self::PROMPT_MARKER, $prompt));
        try {
            $gw->send($gw->begin('alice', LlmPurpose::Chat), new LlmRequest('sys', $prompt));
        } catch (LlmProviderException) {
        }

        $dump = $this->dump();
        $this->assertStringNotContainsString(self::PROMPT_MARKER, $dump);
        $this->assertStringNotContainsString(self::KEY_MARKER, $dump);
        $this->assertStringNotContainsString('Bitte erkläre', $dump);

        $calls = array_values(array_filter($this->auditEvents, fn(array $e) => $e[0] === 'ai_call'));
        $this->assertCount(2, $calls);
        [, $uid, $ok] = $calls[0];
        $this->assertSame('alice', $uid);
        $this->assertSame('chat', $ok['purpose']);
        $this->assertSame('Google Gemini', $ok['recipient']);
        $this->assertSame(substr(self::gemini()->recipientFingerprint(), 0, 12), $ok['recipient_fp']);
        $this->assertSame('gemini-2.5-flash', $ok['model']);
        $this->assertSame(11, $ok['tokens_in']);
        $this->assertSame(7, $ok['tokens_out']);
        $this->assertSame('ok', $ok['status']);
        $this->assertSame('error', $calls[1][2]['status']);
        $this->assertSame('bad_request', $calls[1][2]['category']);

        $metrics = array_values(array_filter($this->logLines, fn(array $l) => $l[1] === 'llm_api_call'));
        $this->assertCount(2, $metrics);
        $this->assertSame('gemini', $metrics[0][2]['provider']);
        $this->assertSame('chat', $metrics[0][2]['purpose']);
        $this->assertSame('ok', $metrics[0][2]['status']);
        $this->assertIsInt($metrics[0][2]['latency_ms']);
    }

    public function testNoAuditWhenNothingWasSent(): void {
        $this->store->method('load')->willReturn(self::gemini());
        $this->store->method('isEnabledNow')->willReturn(false);
        $this->consent->method('isValidFor')->willReturn(true);

        $gw = $this->gateway();
        $session = $gw->begin('alice', LlmPurpose::Chat);
        $this->assertProviderCategory('disabled', fn() => $gw->send($session, self::request()));

        $this->assertSame([], array_filter($this->auditEvents, fn(array $e) => $e[0] === 'ai_call'));
    }

    // ---------------------------------------------------------------- testConnection()

    public function testConnectionUsesTheCandidateKeyWithoutStoringIt(): void {
        $this->store->expects($this->never())->method('isEnabledNow');
        $this->store->expects($this->never())->method('save');
        $this->keys->expects($this->never())->method('apply');
        $this->consent->expects($this->never())->method('isValidFor');
        $this->budget->expects($this->once())->method('reserve')->with(null, LlmPurpose::AdminTest)
            ->willReturn($this->ticket(null, LlmPurpose::AdminTest));
        $this->budget->expects($this->once())->method('release');

        $result = $this->gateway([$this->geminiOk('OK')])->testConnection(self::gemini(), 'CANDIDATE-KEY-1');

        $this->assertTrue($result['ok']);
        $this->assertSame('gemini-2.5-flash', $result['model']);
        $this->assertIsInt($result['latencyMs']);
        $this->assertNull($result['category']);
        $this->assertCount(1, $this->calls);
        $this->assertSame('CANDIDATE-KEY-1', $this->calls[0][1]['headers']['x-goog-api-key']);
        // a fixed test message, nothing from a user
        $this->assertStringNotContainsString(self::PROMPT_MARKER, $this->calls[0][1]['body']);
        $this->assertStringNotContainsString('CANDIDATE-KEY-1', $this->dump());
    }

    public function testConnectionWithoutCandidateKeyUsesTheStoredOne(): void {
        $this->budget->method('reserve')->willReturn($this->ticket(null, LlmPurpose::AdminTest));

        $result = $this->gateway([$this->geminiOk('OK')])->testConnection(self::gemini(), null);

        $this->assertTrue($result['ok']);
        $this->assertSame(self::KEY_MARKER, $this->calls[0][1]['headers']['x-goog-api-key']);
    }

    public function testConnectionFailureDetailNeverContainsTheKey(): void {
        $this->budget->method('reserve')->willReturn($this->ticket(null, LlmPurpose::AdminTest));

        $result = $this->gateway([$this->httpResponse(401, '{"error":"invalid key ' . self::KEY_MARKER . '"}')])
            ->testConnection(self::gemini(), self::KEY_MARKER);

        $this->assertFalse($result['ok']);
        $this->assertSame('auth', $result['category']);
        $this->assertStringNotContainsString(self::KEY_MARKER, (string)$result['detail']);
        $this->assertStringNotContainsString(self::KEY_MARKER, $this->dump());
    }

    /** @dataProvider notSelectableIn570 */
    public function testConnectionRefusesBackendsThatAreNotSelectable(LlmConnection $c): void {
        $this->budget->expects($this->never())->method('reserve');

        $result = $this->gateway([$this->geminiOk(), $this->ollamaOk()])->testConnection($c, 'some-key');

        $this->assertFalse($result['ok']);
        $this->assertSame('config', $result['category']);
        $this->assertNothingSent();
    }

    public function testConnectionOfADisabledCandidateSendsNothing(): void {
        $this->budget->expects($this->never())->method('reserve');

        $result = $this->gateway([$this->geminiOk()])->testConnection(LlmConnection::fromArray(['provider' => 'disabled']), null);

        $this->assertFalse($result['ok']);
        $this->assertSame('config', $result['category']);
        $this->assertNothingSent();
    }

    public function testConnectionRespectsTheGlobalBudget(): void {
        $this->budget->method('reserve')->willThrowException(new LlmProviderException('rate_limit'));

        $result = $this->gateway([$this->ollamaOk()])->testConnection(self::ollama(), null);

        $this->assertFalse($result['ok']);
        $this->assertSame('rate_limit', $result['category']);
        $this->assertNothingSent();
    }

    public function testConnectionToOllama(): void {
        $this->budget->method('reserve')->willReturn($this->ticket(null, LlmPurpose::AdminTest));

        $result = $this->gateway([$this->ollamaOk('OK')])->testConnection(self::ollama(), null);

        $this->assertTrue($result['ok']);
        $this->assertSame('llama3', $result['model']);
        $this->assertSame(self::OLLAMA_URL . '/api/generate', $this->calls[0][0]);
    }
}
