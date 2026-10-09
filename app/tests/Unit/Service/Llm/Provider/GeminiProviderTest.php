<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service\Llm\Provider;

require_once __DIR__ . '/../../../../Support/RecordingLlmHttp.php';

use OCA\Learning\Service\Llm\ApiKeyStore;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\LlmConnection;
use OCA\Learning\Service\Llm\LlmOutputNormalizer;
use OCA\Learning\Service\Llm\LlmRequest;
use OCA\Learning\Service\Llm\Provider\GeminiProvider;
use OCA\Learning\Service\Llm\Provider\LlmProviderInterface;
use OCA\Learning\Tests\Support\RecordingLlmHttp;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 5.6.0 Task A5a: the Gemini adapter sends what the 5.5 GeminiProvider sent — same URL form
 * (…/models/{model}:generateContent), same x-goog-api-key header, same body incl. defaults and
 * clamping — checked against tests/Fixtures/llm/gemini-request.json, which LegacyProviderPayloadTest
 * pinned against the old class until both were removed in Task B4.
 *
 * Intended differences to 5.5, each pinned below:
 * - transport is LlmHttpClient: target check + DNS pinning, no redirects, never a retry, and the
 *   client's own Accept / Accept-Encoding: identity headers;
 * - reasoning in <think>…</think> is removed from the answer (shared LlmOutputNormalizer);
 * - the model comes from the connection instead of a constant;
 * - failures are LlmProviderException with a category (missing key → config, without a request);
 * - no logging in the adapter (5.5 logged metrics; the gateway records metadata in B3).
 */
class GeminiProviderTest extends TestCase {
    use RecordingLlmHttp;

    private const DNS = ['generativelanguage.googleapis.com' => ['142.250.185.106']];

    /** @return array<string, array{string}> */
    public static function fixtureCases(): array {
        return self::llmFixtureCaseNames('gemini-request.json');
    }

    /** @dataProvider fixtureCases */
    public function testRequestMatchesLegacyFixture(string $name): void {
        $fixture = self::llmFixture('gemini-request.json');
        $case = self::llmFixtureCase($fixture, $name);
        $expected = $case['request'];
        $provider = $this->provider([$this->httpResponse(200, (string)json_encode($fixture['response']['body']))], $fixture['apiKey']);

        $provider->complete($this->connection(), self::request($case['input']));

        $this->assertCount(1, $this->calls);
        [$url, $opts] = $this->calls[0];
        $this->assertSame($expected['url'], $url);
        $this->assertSame($expected['timeoutSec'], $opts['timeout']);
        $this->assertSame($expected['body'], json_decode($opts['body'], true));

        // the 5.5 headers, plus exactly what LlmHttpClient adds for every provider
        $sent = $opts['headers'];
        ksort($sent);
        $want = $expected['headers'] + ['Accept' => 'application/json', 'Accept-Encoding' => 'identity'];
        ksort($want);
        $this->assertSame($want, $sent);
    }

    public function testParsesResponseLikeLegacy(): void {
        $fixture = self::llmFixture('gemini-request.json');
        $provider = $this->provider([$this->httpResponse(200, (string)json_encode($fixture['response']['body']))]);

        $result = $provider->complete($this->connection(), new LlmRequest('', 'Hallo'));

        $this->assertSame($fixture['response']['text'], $result->text);
        $this->assertSame($fixture['response']['tokensIn'], $result->tokensIn);
        $this->assertSame($fixture['response']['tokensOut'], $result->tokensOut);
        $this->assertSame('gemini-2.5-flash', $result->model);
    }

    public function testImplementsTheNewProviderContract(): void {
        $this->assertInstanceOf(LlmProviderInterface::class, $this->provider([]));
    }

    public function testModelComesFromConnection(): void {
        $provider = $this->provider([$this->ok('Antwort')]);
        $result = $provider->complete($this->connection('gemini-2.5-pro'), new LlmRequest('', 'Hallo'));
        $this->assertSame('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent', $this->calls[0][0]);
        $this->assertSame('gemini-2.5-pro', $result->model);
    }

    public function testModelCannotReshapeTheUrl(): void {
        $provider = $this->provider([$this->ok('Antwort')]);
        $provider->complete($this->connection('x/../y?key=1#z'), new LlmRequest('', 'Hallo'));
        $this->assertSame('https://generativelanguage.googleapis.com/v1beta/models/x%2F..%2Fy%3Fkey%3D1%23z:generateContent', $this->calls[0][0]);
    }

    public function testKeyTravelsOnlyInTheHeader(): void {
        $provider = $this->provider([$this->ok('Antwort')], 'KEY-MARKER-123');
        $provider->complete($this->connection(), new LlmRequest('', 'Hallo'));
        [$url, $opts] = $this->calls[0];
        $this->assertSame('KEY-MARKER-123', $opts['headers']['x-goog-api-key']);
        $this->assertStringNotContainsString('KEY-MARKER', $url);
        $this->assertStringNotContainsString('KEY-MARKER', $opts['body']);
    }

    public function testMissingKeyIsConfigAndSendsNothing(): void {
        $provider = $this->provider([$this->ok('Antwort')], null);
        $this->assertProviderCategory('config', fn() => $provider->complete($this->connection(), new LlmRequest('', 'Hallo')));
        $this->assertCount(0, $this->calls);
    }

    public function testOtherProviderConnectionIsConfigAndSendsNothing(): void {
        $provider = $this->provider([$this->ok('Antwort')]);
        $ollama = LlmConnection::fromArray(['enabled' => true, 'provider' => 'ollama', 'baseUrl' => 'https://generativelanguage.googleapis.com/v1beta',
            'model' => 'gemini-2.5-flash']);
        $this->assertProviderCategory('config', fn() => $provider->complete($ollama, new LlmRequest('', 'Hallo')));
        $this->assertCount(0, $this->calls);
    }

    /** @return array<string, array{string}> */
    public static function emptyBodies(): array {
        return [
            'empty text' => ['{"candidates":[{"content":{"parts":[{"text":"   "}]}}]}'],
            'no candidates' => ['{"promptFeedback":{"blockReason":"SAFETY"}}'],
            'not json' => ['<html>ok</html>'],
            'only reasoning' => [(string)json_encode(['candidates' => [['content' => ['parts' => [['text' => '<think>nur Gedanken</think>']]]]]])],
        ];
    }

    /** @dataProvider emptyBodies */
    public function testNoAnswerTextIsEmpty(string $body): void {
        $provider = $this->provider([$this->httpResponse(200, $body)]);
        $this->assertProviderCategory('empty', fn() => $provider->complete($this->connection(), new LlmRequest('', 'Hallo')));
        $this->assertCount(1, $this->calls);
    }

    public function testReasoningIsRemovedFromTheAnswer(): void {
        // intended change: 5.5 passed <think> blocks through to the learner
        $provider = $this->provider([$this->ok("<think>interne Gedanken</think>\nDie Antwort.")]);
        $this->assertSame('Die Antwort.', $provider->complete($this->connection(), new LlmRequest('', 'Hallo'))->text);
    }

    public function testMissingUsageCountsAsZero(): void {
        $provider = $this->provider([$this->httpResponse(200, '{"candidates":[{"content":{"parts":[{"text":"Hi"}]}}]}')]);
        $result = $provider->complete($this->connection(), new LlmRequest('', 'Hallo'));
        $this->assertSame(0, $result->tokensIn);
        $this->assertSame(0, $result->tokensOut);
    }

    /** @return array<string, array{int, string}> */
    public static function errorStatuses(): array {
        return ['401' => [401, 'auth'], '403' => [403, 'auth'], '429' => [429, 'rate_limit'],
            '503' => [503, 'overloaded'], '529' => [529, 'overloaded'], '400' => [400, 'bad_request'], '500' => [500, 'network']];
    }

    /** @dataProvider errorStatuses */
    public function testErrorStatusMapsToCategoryWithoutRetry(int $status, string $category): void {
        $provider = $this->provider([
            $this->httpResponse($status, '{"error":{"message":"PROMPT-MARKER nope"}}'),
            $this->ok('zweiter Versuch'),
        ]);
        $e = $this->assertProviderCategory($category, fn() => $provider->complete($this->connection(), new LlmRequest('', 'PROMPT-MARKER')));
        $this->assertCount(1, $this->calls, 'a failed request is never sent a second time');
        $this->assertStringNotContainsString('PROMPT-MARKER', $e->getMessage());
    }

    public function testRedirectsAreNotFollowed(): void {
        $provider = $this->provider([$this->ok('Antwort')]);
        $provider->complete($this->connection(), new LlmRequest('', 'Hallo'));
        $this->assertFalse($this->calls[0][1]['allow_redirects']);
        $this->assertSame(['generativelanguage.googleapis.com:443:142.250.185.106'], $this->calls[0][1]['curl'][CURLOPT_RESOLVE]);
    }

    public function testAdapterHasNoLogger(): void {
        // no prompt, answer or provider error body can end up in a log from here
        $ctor = (new \ReflectionClass(GeminiProvider::class))->getConstructor();
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
            $this->httpResponse(200, '{"candidates":[{"content":{"parts":[{"text":"<think>ANSWER-MARKER-5d1c</think>"}]}}]}'),
        ], 'KEY-MARKER-9c1d');

        $this->assertAdapterWritesNoContent(GeminiProvider::class, [
            fn() => $provider->complete($this->connection(), $request),
            fn() => $provider->complete($this->connection(), $request),
            fn() => $provider->complete($this->connection(), $request),
        ], $markers);
        $this->assertCount(3, $this->calls);
    }

    // ─── helpers ───────────────────────────────────────────────────────────

    /** @param list<\OCP\Http\Client\IResponse|\Throwable> $outcomes */
    private function provider(array $outcomes, ?string $apiKey = 'test-key'): GeminiProvider {
        $keys = $this->createMock(ApiKeyStore::class);
        $keys->method('get')->willReturn($apiKey);
        return new GeminiProvider($this->recordingHttp(self::DNS, $outcomes), $keys, new LlmOutputNormalizer());
    }

    private function connection(string $model = ConnectionStore::GEMINI_MODEL): LlmConnection {
        // exactly the endpoint the migrated 5.5 key is bound to
        return LlmConnection::fromArray(array_merge(ConnectionStore::legacyGemini(true)->toArray(), ['model' => $model]));
    }

    private function ok(string $text): \OCP\Http\Client\IResponse {
        return $this->httpResponse(200, (string)json_encode(['candidates' => [['content' => ['parts' => [['text' => $text]]]]]]));
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
