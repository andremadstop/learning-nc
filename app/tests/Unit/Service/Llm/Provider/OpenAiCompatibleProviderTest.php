<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service\Llm\Provider;

require_once __DIR__ . '/../../../../Support/RecordingLlmHttp.php';

use OCA\Learning\Service\Llm\ApiKeyStore;
use OCA\Learning\Service\Llm\LlmConnection;
use OCA\Learning\Service\Llm\LlmOutputNormalizer;
use OCA\Learning\Service\Llm\LlmRequest;
use OCA\Learning\Service\Llm\ModelCapabilities;
use OCA\Learning\Service\Llm\Provider\LlmProviderInterface;
use OCA\Learning\Service\Llm\Provider\OpenAiCompatibleProvider;
use OCA\Learning\Tests\Support\RecordingLlmHttp;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 5.6.0 Task A5c: the OpenAI-compatible adapter (presets openai, mistral, ionos, openrouter, custom;
 * spec 4.1). There is no 5.5 class, so tests/Fixtures/llm/openai-compatible-request.json is pinned
 * against the vendor documentation, checked on 2026-09-25:
 *
 * - OpenAI, Create chat completion
 *   https://developers.openai.com/api/reference/resources/chat/subresources/completions/methods/create
 *   (read via context7 /websites/developers_openai; WebFetch got 404 on the page): POST
 *   /chat/completions, `Authorization: Bearer`, `messages` with role `system` / `user`, answer in
 *   `choices[].message.content`, `finish_reason` (`stop`, `length`, …), `usage.prompt_tokens` /
 *   `usage.completion_tokens`. On `max_tokens`: "This value is now deprecated in favor of
 *   `max_completion_tokens`, and is not compatible with o-series models." Which models get
 *   `max_completion_tokens` and no temperature is ModelCapabilities' verified list (Task A4); this
 *   adapter uses the real class, not a mock.
 * - OpenRouter, Provider Routing https://openrouter.ai/docs/guides/routing/providers: request field
 *   `provider` with `order` (string[], "List of provider slugs to try in order", e.g. `deepinfra/turbo`)
 *   and `allow_fallbacks` (boolean, default **true** — so it is always sent as a literal false).
 *   Example body there: `"provider": {"order": ["openai", "together"], "allow_fallbacks": false}`.
 * - Mistral, reasoning https://github.com/mistralai/platform-docs-public/blob/main/public/studio-api/conversations/reasoning.md
 *   (docs.mistral.ai, via context7 /mistralai/platform-docs-public): with reasoning on, `message.content`
 *   is "a list of chunks instead of a plain string" — `type: "thinking"` (trace) and `type: "text"`
 *   (final answer). Only the text chunks are the answer.
 *
 * Shared adapter rules (plan A5): LlmHttpClient transport (target check, pinning, no redirects,
 * never a retry), shared normalizer, `reasoning_content` ignored, no text → `empty`, no logging.
 */
class OpenAiCompatibleProviderTest extends TestCase {
    use RecordingLlmHttp;

    private const DNS = [
        'api.openai.com' => ['162.159.140.245'],
        'api.mistral.ai' => ['104.18.10.20'],
        'openrouter.ai' => ['104.18.2.115'],
        'llm.example.com' => ['192.168.1.20'],
    ];
    private const FIXTURE = 'openai-compatible-request.json';

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

    public function testParsesContentAndUsageIgnoringReasoningContent(): void {
        $fixture = self::llmFixture(self::FIXTURE);
        $provider = $this->provider([$this->httpResponse(200, (string)json_encode($fixture['response']['body']))]);

        $result = $provider->complete($this->connection('mistral', 'mistral-large-latest'), new LlmRequest('', 'Hallo'));

        $this->assertSame($fixture['response']['text'], $result->text);
        $this->assertStringNotContainsString('REASONING-MARKER', $result->text);
        $this->assertSame($fixture['response']['tokensIn'], $result->tokensIn);
        $this->assertSame($fixture['response']['tokensOut'], $result->tokensOut);
        $this->assertSame('mistral-large-latest', $result->model, 'the configured model, as in the other adapters');
    }

    public function testImplementsTheNewProviderContract(): void {
        $this->assertInstanceOf(LlmProviderInterface::class, $this->provider([]));
    }

    // ─── token parameter and temperature (ModelCapabilities) ─────────────

    public function testReasoningModelGetsMaxCompletionTokensAndNoTemperature(): void {
        $provider = $this->provider([$this->ok('Antwort')]);
        $provider->complete($this->connection('openai', 'o3-2025-04-16'), new LlmRequest('S', 'U', 0.3, 900));
        $body = json_decode($this->calls[0][1]['body'], true);
        $this->assertSame(900, $body['max_completion_tokens']);
        $this->assertArrayNotHasKey('max_tokens', $body);
        $this->assertArrayNotHasKey('temperature', $body);
    }

    public function testStandardModelGetsMaxTokensAndTemperature(): void {
        $provider = $this->provider([$this->ok('Antwort')]);
        $provider->complete($this->connection('mistral', 'mistral-small-latest'), new LlmRequest('S', 'U', 0.3, 900));
        $body = json_decode($this->calls[0][1]['body'], true);
        $this->assertSame(900, $body['max_tokens']);
        $this->assertSame(0.3, $body['temperature']);
        $this->assertArrayNotHasKey('max_completion_tokens', $body);
    }

    public function testOnlyOpenRouterGetsRoutingParameters(): void {
        foreach (['openai', 'mistral', 'ionos'] as $preset) {
            $provider = $this->provider([$this->ok('Antwort')]);
            $this->calls = [];
            $provider->complete($this->connection($preset, 'some-model'), new LlmRequest('S', 'U'));
            $this->assertArrayNotHasKey('provider', json_decode($this->calls[0][1]['body'], true), $preset);
        }
    }

    // ─── OpenRouter: fixed routing or no request ─────────────────────────

    /** @return array<string, array{?array<string, mixed>}> */
    public static function badOpenRouterRouting(): array {
        return [
            'no routing' => [null],
            'empty order' => [['order' => [], 'allowFallbacks' => false]],
            'two providers' => [['order' => ['openai', 'together'], 'allowFallbacks' => false]],
            'blank provider' => [['order' => ['  '], 'allowFallbacks' => false]],
            'provider not a string' => [['order' => [7], 'allowFallbacks' => false]],
            'fallbacks allowed' => [['order' => ['openai'], 'allowFallbacks' => true]],
        ];
    }

    /**
     * fromArray() already refuses these; the adapter must not rely on it, because a snapshot can be
     * built with the constructor.
     *
     * @dataProvider badOpenRouterRouting
     * @param array<string, mixed>|null $routing
     */
    public function testOpenRouterWithoutFixedRoutingIsConfigAndSendsNothing(?array $routing): void {
        $provider = $this->provider([$this->ok('Antwort')]);
        $conn = new LlmConnection(1, true, 'openai_compatible', 'openrouter', 'https://openrouter.ai/api/v1',
            'meta-llama/llama-3.3-70b-instruct', false, false, $routing, null);
        $this->assertProviderCategory('config', fn() => $provider->complete($conn, new LlmRequest('S', 'U')));
        $this->assertCount(0, $this->calls);
    }

    public function testOpenRouterAlwaysSendsFallbacksAsLiteralFalse(): void {
        $provider = $this->provider([$this->ok('Antwort')]);
        $conn = LlmConnection::fromArray(['enabled' => true, 'provider' => 'openai_compatible', 'preset' => 'openrouter',
            'baseUrl' => 'https://openrouter.ai/api/v1', 'model' => 'anthropic/claude-sonnet-4.5',
            'openRouterRouting' => ['order' => ['anthropic'], 'allowFallbacks' => false]]);
        $provider->complete($conn, new LlmRequest('S', 'U'));
        $body = json_decode($this->calls[0][1]['body'], true);
        $this->assertSame(['order' => ['anthropic'], 'allow_fallbacks' => false], $body['provider']);
        $this->assertStringContainsString('"allow_fallbacks":false', $this->calls[0][1]['body']);
    }

    // ─── key handling ─────────────────────────────────────────────────────

    /** @return array<string, array{string, ?string}> */
    public static function missingKeys(): array {
        $cases = [];
        foreach (['openai', 'mistral', 'ionos', 'openrouter'] as $preset) {
            $cases["$preset null"] = [$preset, null];
            $cases["$preset blank"] = [$preset, '   '];
        }
        return $cases;
    }

    /** @dataProvider missingKeys */
    public function testMissingKeyIsConfigAndSendsNothing(string $preset, ?string $key): void {
        $provider = $this->provider([$this->ok('Antwort')], $key);
        $this->assertProviderCategory('config', fn() => $provider->complete($this->connection($preset, 'some-model'), new LlmRequest('S', 'U')));
        $this->assertCount(0, $this->calls);
    }

    /** @return array<string, array{?string}> */
    public static function customWithoutKey(): array {
        return ['null' => [null], 'blank' => ['  ']];
    }

    /** @dataProvider customWithoutKey */
    public function testCustomWithoutKeySendsNoAuthorizationHeader(?string $key): void {
        $provider = $this->provider([$this->ok('Antwort')], $key);
        $provider->complete($this->customConnection(), new LlmRequest('S', 'U'));
        $this->assertCount(1, $this->calls);
        $this->assertArrayNotHasKey('Authorization', $this->calls[0][1]['headers']);
    }

    public function testCustomWithKeySendsBearer(): void {
        $provider = $this->provider([$this->ok('Antwort')], ' custom-key ');
        $provider->complete($this->customConnection(), new LlmRequest('S', 'U'));
        $this->assertSame('Bearer custom-key', $this->calls[0][1]['headers']['Authorization']);
    }

    public function testKeyTravelsOnlyInTheHeader(): void {
        $provider = $this->provider([$this->ok('Antwort')], 'KEY-MARKER-123');
        $provider->complete($this->connection('openai', 'gpt-4.1'), new LlmRequest('', 'Hallo'));
        [$url, $opts] = $this->calls[0];
        $this->assertSame('Bearer KEY-MARKER-123', $opts['headers']['Authorization']);
        $this->assertStringNotContainsString('KEY-MARKER', $url);
        $this->assertStringNotContainsString('KEY-MARKER', $opts['body']);
    }

    // ─── target, URL, timeout ─────────────────────────────────────────────

    public function testBaseUrlPathPrefixIsKeptAndTrailingSlashStripped(): void {
        $provider = $this->provider([$this->ok('Antwort')]);
        $conn = LlmConnection::fromArray(['enabled' => true, 'provider' => 'openai_compatible', 'preset' => 'ionos',
            'baseUrl' => 'https://api.openai.com/proxy/v1/', 'model' => 'm']);
        $provider->complete($conn, new LlmRequest('S', 'U'));
        $this->assertSame('https://api.openai.com/proxy/v1/chat/completions', $this->calls[0][0]);
    }

    public function testCloudPresetsUse45SecondsAndCustom90(): void {
        $provider = $this->provider([$this->ok('Antwort'), $this->ok('Antwort')]);
        $provider->complete($this->connection('openai', 'gpt-4.1'), new LlmRequest('S', 'U'));
        $provider->complete($this->customConnection(), new LlmRequest('S', 'U'));
        $this->assertSame(45, $this->calls[0][1]['timeout']);
        $this->assertSame(90, $this->calls[1][1]['timeout']);
    }

    public function testRequestIsPinnedAndRedirectsAreNotFollowed(): void {
        $provider = $this->provider([$this->ok('Antwort')]);
        $provider->complete($this->connection('openai', 'gpt-4.1'), new LlmRequest('S', 'U'));
        $this->assertFalse($this->calls[0][1]['allow_redirects']);
        $this->assertSame(['api.openai.com:443:162.159.140.245'], $this->calls[0][1]['curl'][CURLOPT_RESOLVE]);
        $this->assertSame(['allow_local_address' => false], $this->calls[0][1]['nextcloud']);
    }

    public function testCustomLanAddressWithoutInternalFlagIsRejectedBeforeSending(): void {
        $provider = $this->provider([$this->ok('Antwort')]);
        $conn = LlmConnection::fromArray(['enabled' => true, 'provider' => 'openai_compatible', 'preset' => 'custom',
            'baseUrl' => 'https://llm.example.com/v1', 'model' => 'm', 'allowInternalNetwork' => false]);
        $this->assertProviderCategory('config', fn() => $provider->complete($conn, new LlmRequest('S', 'U')));
        $this->assertCount(0, $this->calls);
    }

    public function testOtherProviderConnectionIsConfigAndSendsNothing(): void {
        $provider = $this->provider([$this->ok('Antwort')]);
        $gemini = LlmConnection::fromArray(['enabled' => true, 'provider' => 'gemini', 'baseUrl' => 'https://api.openai.com/v1',
            'model' => 'gpt-4.1']);
        $this->assertProviderCategory('config', fn() => $provider->complete($gemini, new LlmRequest('S', 'U')));
        $this->assertCount(0, $this->calls);
    }

    // ─── parsing ──────────────────────────────────────────────────────────

    public function testMistralTextChunksAreTheAnswerAndThinkingIsDropped(): void {
        $provider = $this->provider([$this->httpResponse(200, (string)json_encode(['choices' => [['finish_reason' => 'stop', 'message' => [
            'role' => 'assistant',
            'content' => [
                ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'REASONING-MARKER Gedanken']]],
                ['type' => 'text', 'text' => 'Teil eins, '],
                ['type' => 'text', 'text' => 'Teil zwei.'],
            ],
        ]]]]))]);
        $text = $provider->complete($this->connection('mistral', 'magistral-medium-latest'), new LlmRequest('S', 'U'))->text;
        $this->assertSame('Teil eins, Teil zwei.', $text);
    }

    public function testReasoningInThinkTagsIsRemovedFromTheAnswer(): void {
        // deepseek-r1 & co. behind a custom endpoint return their reasoning inline
        $provider = $this->provider([$this->ok("<think>interne Gedanken</think>\nDie Antwort.")]);
        $this->assertSame('Die Antwort.', $provider->complete($this->customConnection(), new LlmRequest('S', 'U'))->text);
    }

    /** @return array<string, array{string}> */
    public static function emptyBodies(): array {
        return [
            'empty content' => ['{"choices":[{"finish_reason":"stop","message":{"role":"assistant","content":"   "}}]}'],
            'null content' => ['{"choices":[{"finish_reason":"stop","message":{"role":"assistant","content":null}}]}'],
            'no choices' => ['{"choices":[]}'],
            'not json' => ['<html>ok</html>'],
            'only reasoning_content' => ['{"choices":[{"finish_reason":"stop","message":{"role":"assistant","content":"","reasoning_content":"nur Gedanken"}}]}'],
            'cut off at the limit' => ['{"choices":[{"finish_reason":"length","message":{"role":"assistant","content":""}}],"usage":{"prompt_tokens":10,"completion_tokens":1200}}'],
            'only think tags' => ['{"choices":[{"finish_reason":"stop","message":{"role":"assistant","content":"<think>nur Gedanken</think>"}}]}'],
            'only thinking chunks' => ['{"choices":[{"finish_reason":"stop","message":{"role":"assistant","content":[{"type":"thinking","thinking":[{"type":"text","text":"x"}]}]}}]}'],
            'content not text' => ['{"choices":[{"finish_reason":"stop","message":{"role":"assistant","content":42}}]}'],
        ];
    }

    /** @dataProvider emptyBodies */
    public function testNoAnswerTextIsEmpty(string $body): void {
        $provider = $this->provider([$this->httpResponse(200, $body)]);
        $this->assertProviderCategory('empty', fn() => $provider->complete($this->connection('openai', 'gpt-4.1'), new LlmRequest('S', 'U')));
        $this->assertCount(1, $this->calls);
    }

    public function testMissingUsageCountsAsZero(): void {
        $provider = $this->provider([$this->ok('Hi')]);
        $result = $provider->complete($this->connection('openai', 'gpt-4.1'), new LlmRequest('S', 'U'));
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
            $this->httpResponse($status, '{"error":{"message":"PROMPT-MARKER nope","type":"invalid_request_error"}}'),
            $this->ok('zweiter Versuch'),
        ]);
        $e = $this->assertProviderCategory($category,
            fn() => $provider->complete($this->connection('openai', 'gpt-4.1'), new LlmRequest('', 'PROMPT-MARKER')));
        $this->assertCount(1, $this->calls, 'a failed request is never sent a second time');
        $this->assertStringNotContainsString('PROMPT-MARKER', $e->getMessage());
    }

    public function testTimeoutIsNotRetried(): void {
        $provider = $this->provider([
            new \RuntimeException('cURL error 28: Operation timed out after 45001 milliseconds'),
            $this->ok('zweiter Versuch'),
        ]);
        $this->assertProviderCategory('timeout',
            fn() => $provider->complete($this->connection('openai', 'gpt-4.1'), new LlmRequest('S', 'U')));
        $this->assertCount(1, $this->calls);
    }

    public function testAdapterHasNoLogger(): void {
        $ctor = (new \ReflectionClass(OpenAiCompatibleProvider::class))->getConstructor();
        $this->assertNotNull($ctor);
        foreach ($ctor->getParameters() as $param) {
            $this->assertNotSame(LoggerInterface::class, (string)$param->getType());
        }
    }

    public function testNothingIsLoggedOrPrinted(): void {
        // the constructor check above cannot see error_log()/echo or a logger fetched from the server
        $markers = ['PROMPT-MARKER-7f3a', 'SYSTEM-MARKER-2b8e', 'ANSWER-MARKER-5d1c', 'KEY-MARKER-9c1d', 'ERRBODY-MARKER-4a6f'];
        $request = new LlmRequest('SYSTEM-MARKER-2b8e', 'PROMPT-MARKER-7f3a');
        $provider = $this->provider([
            $this->ok('ANSWER-MARKER-5d1c'),
            $this->httpResponse(401, '{"error":{"message":"ERRBODY-MARKER-4a6f KEY-MARKER-9c1d"}}'),
            $this->httpResponse(200, '{"choices":[{"finish_reason":"length","message":{"content":"","reasoning_content":"ANSWER-MARKER-5d1c"}}]}'),
        ], 'KEY-MARKER-9c1d');
        $conn = $this->connection('openai', 'gpt-4.1');

        $this->assertAdapterWritesNoContent(OpenAiCompatibleProvider::class, [
            fn() => $provider->complete($conn, $request),
            fn() => $provider->complete($conn, $request),
            fn() => $provider->complete($conn, $request),
        ], $markers);
        $this->assertCount(3, $this->calls);
    }

    // ─── helpers ───────────────────────────────────────────────────────────

    /** @param list<\OCP\Http\Client\IResponse|\Throwable> $outcomes */
    private function provider(array $outcomes, ?string $apiKey = 'test-key'): OpenAiCompatibleProvider {
        $keys = $this->createMock(ApiKeyStore::class);
        $keys->method('get')->willReturn($apiKey);
        return new OpenAiCompatibleProvider($this->recordingHttp(self::DNS, $outcomes), $keys, new LlmOutputNormalizer(),
            new ModelCapabilities());
    }

    private function connection(string $preset, string $model): LlmConnection {
        $base = [
            'openai' => 'https://api.openai.com/v1',
            'mistral' => 'https://api.mistral.ai/v1',
            'ionos' => 'https://api.openai.com/v1', // any public host of the fake resolver
            'openrouter' => 'https://openrouter.ai/api/v1',
        ][$preset];
        $a = ['rev' => 1, 'enabled' => true, 'provider' => 'openai_compatible', 'preset' => $preset, 'baseUrl' => $base, 'model' => $model];
        if ($preset === 'openrouter') {
            $a['openRouterRouting'] = ['order' => ['openai'], 'allowFallbacks' => false];
        }
        return LlmConnection::fromArray($a);
    }

    private function customConnection(): LlmConnection {
        return LlmConnection::fromArray(['rev' => 1, 'enabled' => true, 'provider' => 'openai_compatible', 'preset' => 'custom',
            'baseUrl' => 'http://llm.example.com:8080/v1', 'model' => 'deepseek-r1:14b', 'processingLocal' => true,
            'allowInternalNetwork' => true]);
    }

    private function ok(string $text): \OCP\Http\Client\IResponse {
        return $this->httpResponse(200, (string)json_encode(['choices' => [['index' => 0, 'finish_reason' => 'stop',
            'message' => ['role' => 'assistant', 'content' => $text]]]]));
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
