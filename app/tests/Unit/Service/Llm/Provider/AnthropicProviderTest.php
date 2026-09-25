<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service\Llm\Provider;

require_once __DIR__ . '/../../../../Support/RecordingLlmHttp.php';

use OCA\Learning\Service\Llm\ApiKeyStore;
use OCA\Learning\Service\Llm\LlmConnection;
use OCA\Learning\Service\Llm\LlmOutputNormalizer;
use OCA\Learning\Service\Llm\LlmRequest;
use OCA\Learning\Service\Llm\ModelCapabilities;
use OCA\Learning\Service\Llm\Provider\AnthropicProvider;
use OCA\Learning\Service\Llm\Provider\LlmProviderInterface;
use OCA\Learning\Tests\Support\RecordingLlmHttp;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 5.6.0 Task A5d: the Anthropic adapter (spec 4.2). There is no 5.5 class, so
 * tests/Fixtures/llm/anthropic-request.json is pinned against the Claude API reference (claude-api
 * skill, checked 2026-09-25):
 *
 * - Messages API: POST https://api.anthropic.com/v1/messages, headers `x-api-key` and
 *   `anthropic-version: 2023-06-01`; body `model`, `max_tokens`, optional top-level `system`,
 *   `messages` with a `user` message. Default model `claude-opus-5`.
 * - `temperature` is rejected (400) by claude-opus-5, -5-5, sonnet-5, fable-5/-5-1, opus-4-7/4-8;
 *   which models may get it is ModelCapabilities' verified list (Task A4, real class used here).
 *   Where it is allowed its range is 0.0–1.0 ("Invalid `temperature` value (must be 0.0-1.0)").
 * - claude-opus-5 thinks by default and claude-opus-5-5 cannot turn thinking off; thinking tokens
 *   count against `max_tokens`, hence the 4096 floor. The response then carries `thinking` blocks
 *   — only `type: text` blocks are the answer. Non-streaming requests keep `max_tokens` around
 *   16000 or below (larger values need streaming), hence the 16000 ceiling.
 * - `stop_reason`: `end_turn`, `max_tokens`, `stop_sequence`, `tool_use`, `pause_turn`,
 *   `refusal`, `model_context_window_exceeded`. A refusal is an HTTP 200 whose content may hold
 *   partial text; "check stop_reason before reading content", `stop_details` is informational and
 *   may be null. Usage: `usage.input_tokens` / `usage.output_tokens`.
 * - Errors: 401 authentication, 403 permission, 429 rate limit, 529 overloaded.
 *
 * Deliberately not sent: `thinking`, `output_config`, the server-side `fallbacks` beta — a fallback
 * re-runs the prompt on a model the admin did not choose (plan: no automatic retries).
 */
class AnthropicProviderTest extends TestCase {
    use RecordingLlmHttp;

    private const DNS = [
        'api.anthropic.com' => ['160.79.104.10'],
        'api.openai.com' => ['162.159.140.245'],
    ];
    private const FIXTURE = 'anthropic-request.json';

    /** @return array<string, array{string}> */
    public static function fixtureCases(): array {
        return self::llmFixtureCaseNames(self::FIXTURE);
    }

    /** @dataProvider fixtureCases */
    public function testRequestMatchesFixture(string $name): void {
        $fixture = self::llmFixture(self::FIXTURE);
        $case = self::llmFixtureCase($fixture, $name);
        $expected = $case['request'];
        $provider = $this->provider([$this->httpResponse(200, (string)json_encode($fixture['response']['body']))], $case['apiKey']);

        $provider->complete(LlmConnection::fromArray($case['connection']), self::request($case['input']));

        $this->assertCount(1, $this->calls);
        [$url, $opts] = $this->calls[0];
        $this->assertSame($expected['url'], $url);
        $this->assertSame($expected['timeoutSec'], $opts['timeout']);
        $this->assertSame($expected['body'], json_decode($opts['body'], true));

        // exactly the adapter's headers, plus what LlmHttpClient adds for every provider
        $sent = $opts['headers'];
        ksort($sent);
        $want = $expected['headers'] + ['Accept' => 'application/json', 'Accept-Encoding' => 'identity'];
        ksort($want);
        $this->assertSame($want, $sent);
    }

    public function testParsesTextBlocksAndUsageIgnoringThinking(): void {
        $fixture = self::llmFixture(self::FIXTURE);
        $provider = $this->provider([$this->httpResponse(200, (string)json_encode($fixture['response']['body']))]);

        $result = $provider->complete($this->connection('claude-opus-5'), new LlmRequest('', 'Hallo'));

        $this->assertSame($fixture['response']['text'], $result->text);
        $this->assertStringNotContainsString('THINKING-MARKER', $result->text);
        $this->assertSame($fixture['response']['tokensIn'], $result->tokensIn);
        $this->assertSame($fixture['response']['tokensOut'], $result->tokensOut);
        $this->assertSame('claude-opus-5', $result->model, 'the configured model, as in the other adapters');
    }

    public function testImplementsTheNewProviderContract(): void {
        $this->assertInstanceOf(LlmProviderInterface::class, $this->provider([]));
    }

    public function testEmptyModelFallsBackToClaudeOpus5(): void {
        // fromArray() refuses an empty model; a snapshot built with the constructor can still carry one
        $provider = $this->provider([$this->ok('Antwort')]);
        $conn = new LlmConnection(1, true, 'anthropic', '', 'https://api.anthropic.com/v1', '', false, false, null, null);
        $result = $provider->complete($conn, new LlmRequest('S', 'U', 0.5));
        $body = json_decode($this->calls[0][1]['body'], true);
        $this->assertSame('claude-opus-5', $body['model']);
        $this->assertArrayNotHasKey('temperature', $body);
        $this->assertSame('claude-opus-5', $result->model);
    }

    // ─── what is never sent ───────────────────────────────────────────────

    public function testNoThinkingEffortFallbackOrBetaIsSent(): void {
        $provider = $this->provider([$this->ok('Antwort')]);
        $provider->complete($this->connection('claude-opus-5'), new LlmRequest('S', 'U', 0.3, 900));
        [, $opts] = $this->calls[0];
        $body = json_decode($opts['body'], true);
        foreach (['thinking', 'output_config', 'fallbacks', 'temperature', 'top_p', 'top_k', 'stream'] as $key) {
            $this->assertArrayNotHasKey($key, $body, $key);
        }
        $this->assertArrayNotHasKey('anthropic-beta', $opts['headers']);
        $this->assertArrayNotHasKey('Authorization', $opts['headers']);
    }

    /** @return array<string, array{string}> */
    public static function modelsWithoutTemperature(): array {
        return ['opus-5' => ['claude-opus-5'], 'opus-5-5' => ['claude-opus-5-5'], 'sonnet-5' => ['claude-sonnet-5'],
            'fable-5-1' => ['claude-fable-5-1'], 'opus-4-8' => ['claude-opus-4-8'], 'unknown' => ['claude-future-9']];
    }

    /** @dataProvider modelsWithoutTemperature */
    public function testModelsThatRejectTemperatureGetNone(string $model): void {
        $provider = $this->provider([$this->ok('Antwort')]);
        $provider->complete($this->connection($model), new LlmRequest('S', 'U', 0.2));
        $this->assertArrayNotHasKey('temperature', json_decode($this->calls[0][1]['body'], true));
    }

    // ─── endpoint, key, target ────────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function foreignBaseUrls(): array {
        return [
            'other host' => ['https://api.openai.com/v1'],
            'other path' => ['https://api.anthropic.com/v2'],
            'path prefix' => ['https://api.anthropic.com/proxy/v1'],
            'no path' => ['https://api.anthropic.com'],
            'other port' => ['https://api.anthropic.com:8443/v1'],
        ];
    }

    /**
     * Key and consent are bound to the connection's baseUrl; posting to the fixed endpoint for any
     * other baseUrl would send data to a recipient the fingerprints do not describe.
     *
     * @dataProvider foreignBaseUrls
     */
    public function testBaseUrlOtherThanTheAnthropicEndpointIsConfigAndSendsNothing(string $baseUrl): void {
        $provider = $this->provider([$this->ok('Antwort')]);
        $conn = new LlmConnection(1, true, 'anthropic', '', $baseUrl, 'claude-opus-5', false, false, null, null);
        $this->assertProviderCategory('config', fn() => $provider->complete($conn, new LlmRequest('S', 'U')));
        $this->assertCount(0, $this->calls);
    }

    public function testUppercaseHostIsTheSameEndpoint(): void {
        $provider = $this->provider([$this->ok('Antwort')]);
        $provider->complete($this->connection('claude-opus-5', 'https://API.Anthropic.com/v1'), new LlmRequest('S', 'U'));
        $this->assertSame('https://api.anthropic.com/v1/messages', $this->calls[0][0]);
    }

    /** @return array<string, array{?string}> */
    public static function missingKeys(): array {
        return ['null' => [null], 'empty' => [''], 'blank' => ['   ']];
    }

    /** @dataProvider missingKeys */
    public function testMissingKeyIsConfigAndSendsNothing(?string $key): void {
        $provider = $this->provider([$this->ok('Antwort')], $key);
        $this->assertProviderCategory('config', fn() => $provider->complete($this->connection('claude-opus-5'), new LlmRequest('S', 'U')));
        $this->assertCount(0, $this->calls);
    }

    public function testKeyTravelsOnlyInTheHeader(): void {
        $provider = $this->provider([$this->ok('Antwort')], 'KEY-MARKER-123');
        $provider->complete($this->connection('claude-opus-5'), new LlmRequest('', 'Hallo'));
        [$url, $opts] = $this->calls[0];
        $this->assertSame('KEY-MARKER-123', $opts['headers']['x-api-key']);
        $this->assertStringNotContainsString('KEY-MARKER', $url);
        $this->assertStringNotContainsString('KEY-MARKER', $opts['body']);
    }

    public function testOtherProviderConnectionIsConfigAndSendsNothing(): void {
        $provider = $this->provider([$this->ok('Antwort')]);
        $openai = LlmConnection::fromArray(['enabled' => true, 'provider' => 'openai_compatible', 'preset' => 'openai',
            'baseUrl' => 'https://api.anthropic.com/v1', 'model' => 'claude-opus-5']);
        $this->assertProviderCategory('config', fn() => $provider->complete($openai, new LlmRequest('S', 'U')));
        $this->assertCount(0, $this->calls);
    }

    public function testRequestIsPinnedAndRedirectsAreNotFollowed(): void {
        $provider = $this->provider([$this->ok('Antwort')]);
        $provider->complete($this->connection('claude-opus-5'), new LlmRequest('S', 'U'));
        $this->assertFalse($this->calls[0][1]['allow_redirects']);
        $this->assertSame(['api.anthropic.com:443:160.79.104.10'], $this->calls[0][1]['curl'][CURLOPT_RESOLVE]);
        $this->assertSame(['allow_local_address' => false], $this->calls[0][1]['nextcloud']);
    }

    // ─── parsing and stop reasons ─────────────────────────────────────────

    public function testRefusalIsRefusalEvenWithPartialText(): void {
        $provider = $this->provider([$this->httpResponse(200, (string)json_encode([
            'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5',
            'content' => [['type' => 'text', 'text' => 'ANSWER-MARKER-5d1c angefangen']],
            'stop_reason' => 'refusal',
            'stop_details' => ['type' => 'refusal', 'category' => 'cyber', 'explanation' => 'EXPLANATION-MARKER PROMPT-MARKER-7f3a'],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 3],
        ]))]);
        $e = $this->assertProviderCategory('refusal',
            fn() => $provider->complete($this->connection('claude-opus-5'), new LlmRequest('S', 'PROMPT-MARKER-7f3a')));
        $this->assertStringContainsString('cyber', $e->adminDetail);
        foreach (['ANSWER-MARKER', 'EXPLANATION-MARKER', 'PROMPT-MARKER'] as $marker) {
            $this->assertStringNotContainsString($marker, $e->getMessage() . $e->adminDetail, $marker);
        }
        $this->assertCount(1, $this->calls, 'a refusal is never re-sent');
    }

    /** @return array<string, array{mixed}> */
    public static function refusalDetails(): array {
        return [
            'null' => [null],
            'no category' => [['type' => 'refusal']],
            'category null' => [['type' => 'refusal', 'category' => null]],
            'odd category' => [['type' => 'refusal', 'category' => 'x" <script> PROMPT-MARKER-7f3a ' . str_repeat('a', 300)]],
        ];
    }

    /** @dataProvider refusalDetails */
    public function testRefusalWithoutUsableDetailsIsStillRefusal(mixed $details): void {
        $provider = $this->provider([$this->httpResponse(200, (string)json_encode([
            'content' => [], 'stop_reason' => 'refusal', 'stop_details' => $details,
        ]))]);
        $e = $this->assertProviderCategory('refusal',
            fn() => $provider->complete($this->connection('claude-opus-5'), new LlmRequest('S', 'U')));
        $this->assertStringNotContainsString('PROMPT-MARKER', $e->adminDetail);
        $this->assertStringNotContainsString('<script>', $e->adminDetail);
        $this->assertLessThanOrEqual(200, strlen($e->adminDetail));
    }

    /** @return array<string, array{string}> */
    public static function emptyBodies(): array {
        return [
            'empty content' => ['{"content":[],"stop_reason":"end_turn"}'],
            'blank text' => ['{"content":[{"type":"text","text":"   "}],"stop_reason":"end_turn"}'],
            'no content' => ['{"stop_reason":"end_turn"}'],
            'content not a list' => ['{"content":"Antwort","stop_reason":"end_turn"}'],
            'text not a string' => ['{"content":[{"type":"text","text":42}],"stop_reason":"end_turn"}'],
            'not json' => ['<html>ok</html>'],
            'only thinking' => ['{"content":[{"type":"thinking","thinking":"nur Gedanken","signature":"s"}],"stop_reason":"end_turn"}'],
            'only redacted thinking' => ['{"content":[{"type":"redacted_thinking","data":"x"}],"stop_reason":"end_turn"}'],
            'only think tags' => ['{"content":[{"type":"text","text":"<think>nur Gedanken</think>"}],"stop_reason":"end_turn"}'],
        ];
    }

    /** @dataProvider emptyBodies */
    public function testNoAnswerTextIsEmpty(string $body): void {
        $provider = $this->provider([$this->httpResponse(200, $body)]);
        $this->assertProviderCategory('empty', fn() => $provider->complete($this->connection('claude-opus-5'), new LlmRequest('S', 'U')));
        $this->assertCount(1, $this->calls);
    }

    public function testThinkingThatUsesUpTheBudgetIsEmptyAndSaysWhy(): void {
        $provider = $this->provider([$this->httpResponse(200, '{"content":[{"type":"thinking","thinking":"","signature":"s"}],'
            . '"stop_reason":"max_tokens","usage":{"input_tokens":10,"output_tokens":4096}}')]);
        $e = $this->assertProviderCategory('empty',
            fn() => $provider->complete($this->connection('claude-opus-5'), new LlmRequest('S', 'U')));
        $this->assertStringContainsString('max_tokens', $e->adminDetail);
    }

    /** @return array<string, array{string}> */
    public static function cutOffStopReasons(): array {
        return ['max_tokens' => ['max_tokens'], 'context window' => ['model_context_window_exceeded']];
    }

    /** @dataProvider cutOffStopReasons */
    public function testCutOffAnswerWithTextIsStillTheAnswer(string $stopReason): void {
        $provider = $this->provider([$this->httpResponse(200, (string)json_encode([
            'content' => [['type' => 'thinking', 'thinking' => '', 'signature' => 's'], ['type' => 'text', 'text' => '  Teilantwort bis zum Limit']],
            'stop_reason' => $stopReason, 'usage' => ['input_tokens' => 10, 'output_tokens' => 4096],
        ]))]);
        $result = $provider->complete($this->connection('claude-opus-5'), new LlmRequest('S', 'U'));
        $this->assertSame('Teilantwort bis zum Limit', $result->text);
        $this->assertSame(10, $result->tokensIn);
        $this->assertSame(4096, $result->tokensOut);
        $this->assertCount(1, $this->calls);
    }

    public function testOtherBlockTypesAreNotPartOfTheAnswer(): void {
        $provider = $this->provider([$this->httpResponse(200, (string)json_encode([
            'content' => [
                ['type' => 'text', 'text' => 'Eins. '],
                ['type' => 'tool_use', 'id' => 't', 'name' => 'x', 'input' => ['text' => 'TOOL-MARKER']],
                ['type' => 'redacted_thinking', 'data' => 'REDACTED-MARKER'],
                ['type' => 'text', 'text' => 'Zwei.'],
            ],
            'stop_reason' => 'end_turn',
        ]))]);
        $this->assertSame('Eins. Zwei.', $provider->complete($this->connection('claude-opus-5'), new LlmRequest('S', 'U'))->text);
    }

    public function testMissingUsageCountsAsZero(): void {
        $provider = $this->provider([$this->ok('Hi')]);
        $result = $provider->complete($this->connection('claude-opus-5'), new LlmRequest('S', 'U'));
        $this->assertSame(0, $result->tokensIn);
        $this->assertSame(0, $result->tokensOut);
    }

    // ─── errors, no retry, no logging ─────────────────────────────────────

    /** @return array<string, array{int, string}> */
    public static function errorStatuses(): array {
        return ['401' => [401, 'auth'], '403' => [403, 'auth'], '429' => [429, 'rate_limit'],
            '503' => [503, 'overloaded'], '529' => [529, 'overloaded'], '400' => [400, 'bad_request'],
            '404' => [404, 'bad_request'], '500' => [500, 'network']];
    }

    /** @dataProvider errorStatuses */
    public function testErrorStatusMapsToCategoryWithoutRetry(int $status, string $category): void {
        $provider = $this->provider([
            $this->httpResponse($status, '{"type":"error","error":{"type":"invalid_request_error","message":"PROMPT-MARKER nope"}}'),
            $this->ok('zweiter Versuch'),
        ]);
        $e = $this->assertProviderCategory($category,
            fn() => $provider->complete($this->connection('claude-opus-5'), new LlmRequest('', 'PROMPT-MARKER')));
        $this->assertCount(1, $this->calls, 'a failed request is never sent a second time');
        $this->assertStringNotContainsString('PROMPT-MARKER', $e->getMessage());
    }

    public function testTimeoutIsNotRetried(): void {
        $provider = $this->provider([
            new \RuntimeException('cURL error 28: Operation timed out after 45001 milliseconds'),
            $this->ok('zweiter Versuch'),
        ]);
        $this->assertProviderCategory('timeout',
            fn() => $provider->complete($this->connection('claude-opus-5'), new LlmRequest('S', 'U')));
        $this->assertCount(1, $this->calls);
    }

    public function testAdapterHasNoLogger(): void {
        $ctor = (new \ReflectionClass(AnthropicProvider::class))->getConstructor();
        $this->assertNotNull($ctor);
        foreach ($ctor->getParameters() as $param) {
            $this->assertNotSame(LoggerInterface::class, (string)$param->getType());
        }
    }

    public function testNothingIsLoggedOrPrinted(): void {
        $markers = ['PROMPT-MARKER-7f3a', 'SYSTEM-MARKER-2b8e', 'ANSWER-MARKER-5d1c', 'KEY-MARKER-9c1d', 'ERRBODY-MARKER-4a6f'];
        $request = new LlmRequest('SYSTEM-MARKER-2b8e', 'PROMPT-MARKER-7f3a');
        $provider = $this->provider([
            $this->ok('ANSWER-MARKER-5d1c'),
            $this->httpResponse(401, '{"type":"error","error":{"type":"authentication_error","message":"ERRBODY-MARKER-4a6f KEY-MARKER-9c1d"}}'),
            $this->httpResponse(200, '{"content":[{"type":"text","text":"ANSWER-MARKER-5d1c"}],"stop_reason":"refusal","stop_details":null}'),
            $this->httpResponse(200, '{"content":[{"type":"thinking","thinking":"ANSWER-MARKER-5d1c","signature":"s"}],"stop_reason":"max_tokens"}'),
        ], 'KEY-MARKER-9c1d');
        $conn = $this->connection('claude-opus-5');

        $this->assertAdapterWritesNoContent(AnthropicProvider::class, [
            fn() => $provider->complete($conn, $request),
            fn() => $provider->complete($conn, $request),
            fn() => $provider->complete($conn, $request),
            fn() => $provider->complete($conn, $request),
        ], $markers);
        $this->assertCount(4, $this->calls);
    }

    public function testExceptionsCarryNoPromptAnswerOrKey(): void {
        $content = ['PROMPT-MARKER-7f3a', 'SYSTEM-MARKER-2b8e', 'ANSWER-MARKER-5d1c', 'ERRBODY-MARKER-4a6f', 'KEY-MARKER-9c1d'];
        $request = new LlmRequest('SYSTEM-MARKER-2b8e', 'PROMPT-MARKER-7f3a');
        $provider = $this->provider([
            $this->httpResponse(401, '{"type":"error","error":{"type":"authentication_error","message":"ERRBODY-MARKER-4a6f KEY-MARKER-9c1d PROMPT-MARKER-7f3a"}}'),
            $this->httpResponse(200, '{"content":[{"type":"text","text":"ANSWER-MARKER-5d1c"}],"stop_reason":"refusal","stop_details":{"category":"bio","explanation":"ANSWER-MARKER-5d1c"}}'),
            $this->httpResponse(200, '{"content":[{"type":"thinking","thinking":"ANSWER-MARKER-5d1c","signature":"s"}],"stop_reason":"max_tokens"}'),
        ], 'KEY-MARKER-9c1d');
        $conn = $this->connection('claude-opus-5');

        foreach (['auth', 'refusal', 'empty'] as $category) {
            $e = $this->assertProviderCategory($category, fn() => $provider->complete($conn, $request));
            foreach ($content as $marker) {
                $this->assertStringNotContainsString($marker, $e->getMessage(), "$category message contains $marker");
            }
            $this->assertStringNotContainsString('KEY-MARKER-9c1d', $e->adminDetail, "$category admin detail contains the key");
            $this->assertStringNotContainsString('ANSWER-MARKER-5d1c', $e->adminDetail, "$category admin detail contains the answer");
        }
        $this->assertCount(3, $this->calls);
    }

    // ─── helpers ───────────────────────────────────────────────────────────

    /** @param list<\OCP\Http\Client\IResponse|\Throwable> $outcomes */
    private function provider(array $outcomes, ?string $apiKey = 'test-key'): AnthropicProvider {
        $keys = $this->createMock(ApiKeyStore::class);
        $keys->method('get')->willReturn($apiKey);
        return new AnthropicProvider($this->recordingHttp(self::DNS, $outcomes), $keys, new LlmOutputNormalizer(),
            new ModelCapabilities());
    }

    private function connection(string $model, string $baseUrl = 'https://api.anthropic.com/v1'): LlmConnection {
        return LlmConnection::fromArray(['rev' => 1, 'enabled' => true, 'provider' => 'anthropic', 'baseUrl' => $baseUrl, 'model' => $model]);
    }

    private function ok(string $text): \OCP\Http\Client\IResponse {
        return $this->httpResponse(200, (string)json_encode(['type' => 'message', 'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => 'end_turn']));
    }

    /** @param array<string, mixed> $input */
    private static function request(array $input): LlmRequest {
        return new LlmRequest(
            $input['systemPrompt'],
            $input['userPrompt'],
            $input['temperature'] === null ? null : (float)$input['temperature'],
            $input['maxOutputTokens'] ?? 1200,
        );
    }
}
