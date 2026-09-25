<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service\Llm\Provider;

require_once __DIR__ . '/../../../../Support/RecordingLlmHttp.php';

use OCA\Learning\Service\Llm\LlmConnection;
use OCA\Learning\Service\Llm\LlmOutputNormalizer;
use OCA\Learning\Service\Llm\LlmRequest;
use OCA\Learning\Service\Llm\Provider\LlmProviderInterface;
use OCA\Learning\Service\Llm\Provider\OllamaProvider;
use OCA\Learning\Tests\Support\RecordingLlmHttp;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 5.6.0 Task A5b: the Ollama adapter sends what the 5.5 OllamaProvider sent — POST
 * {baseUrl}/api/generate with model, prompt, system, stream:false, options.temperature and
 * options.num_predict — checked against tests/Fixtures/llm/ollama-request.json, which
 * LegacyProviderPayloadTest pins against the old class.
 *
 * Intended differences to 5.5, each pinned below:
 * - transport is LlmHttpClient: target check + DNS pinning (a LAN address only with
 *   allowInternalNetwork), no redirects, never a retry, plus the client's Accept headers;
 * - the answer goes through the shared normalizer (5.5 only trimmed): <think> reasoning, as
 *   deepseek-r1/qwen3 return it, is removed;
 * - URL and model come from the connection snapshot instead of live app config;
 * - token counts are reported (prompt_eval_count / eval_count);
 * - failures are LlmProviderException with a category; no logging in the adapter.
 */
class OllamaProviderTest extends TestCase {
    use RecordingLlmHttp;

    private const DNS = ['ollama' => ['172.18.0.4'], 'llm.example.com' => ['192.168.1.20'], 'api.example.com' => ['203.0.113.9']];

    /** @return array<string, array{string}> */
    public static function fixtureCases(): array {
        return self::llmFixtureCaseNames('ollama-request.json');
    }

    /** @dataProvider fixtureCases */
    public function testRequestMatchesLegacyFixture(string $name): void {
        $fixture = self::llmFixture('ollama-request.json');
        $case = self::llmFixtureCase($fixture, $name);
        $expected = $case['request'];
        $provider = $this->provider([$this->httpResponse(200, (string)json_encode($fixture['response']['body']))]);

        $provider->complete($this->connection($fixture['baseUrl'], $fixture['model']), self::request($case['input']));

        $this->assertCount(1, $this->calls);
        [$url, $opts] = $this->calls[0];
        $this->assertSame($expected['url'], $url);
        $this->assertSame($expected['timeoutSec'], $opts['timeout']);
        $this->assertSame($expected['body'], json_decode($opts['body'], true));

        // the 5.5 headers (no auth header at all), plus exactly what LlmHttpClient adds for every provider
        $sent = $opts['headers'];
        ksort($sent);
        $want = $expected['headers'] + ['Accept' => 'application/json', 'Accept-Encoding' => 'identity'];
        ksort($want);
        $this->assertSame($want, $sent);
    }

    public function testParsesResponseLikeLegacy(): void {
        $fixture = self::llmFixture('ollama-request.json');
        $provider = $this->provider([$this->httpResponse(200, (string)json_encode($fixture['response']['body']))]);

        $result = $provider->complete($this->connection($fixture['baseUrl'], $fixture['model']), new LlmRequest('', 'Hallo'));

        $this->assertSame($fixture['response']['text'], $result->text);
        $this->assertSame($fixture['response']['tokensIn'], $result->tokensIn);
        $this->assertSame($fixture['response']['tokensOut'], $result->tokensOut);
        $this->assertSame($fixture['model'], $result->model);
    }

    public function testImplementsTheNewProviderContract(): void {
        $this->assertInstanceOf(LlmProviderInterface::class, $this->provider([]));
    }

    public function testBaseUrlPathPrefixIsKept(): void {
        // Ollama behind a reverse proxy under a sub-path, as 5.5 accepted it
        $provider = $this->provider([$this->ok('Antwort')]);
        $provider->complete($this->connection('http://ollama:11434/proxy/ollama/', 'llama3'), new LlmRequest('', 'Hallo'));
        $this->assertSame('http://ollama:11434/proxy/ollama/api/generate', $this->calls[0][0]);
    }

    public function testLocalTargetIsPinnedAndAllowedOnlyWithInternalFlag(): void {
        $provider = $this->provider([$this->ok('Antwort')]);
        $provider->complete($this->connection('http://ollama:11434', 'llama3'), new LlmRequest('', 'Hallo'));
        $this->assertSame(['ollama:11434:172.18.0.4'], $this->calls[0][1]['curl'][CURLOPT_RESOLVE]);
        $this->assertSame(['allow_local_address' => true], $this->calls[0][1]['nextcloud']);
        $this->assertFalse($this->calls[0][1]['allow_redirects']);
    }

    public function testLanAddressWithoutInternalFlagIsRejectedBeforeSending(): void {
        // intended change: 5.5 connected to whatever the URL resolved to
        $provider = $this->provider([$this->ok('Antwort')]);
        $conn = LlmConnection::fromArray(['enabled' => true, 'provider' => 'ollama', 'baseUrl' => 'https://llm.example.com',
            'model' => 'llama3', 'allowInternalNetwork' => false]);
        $this->assertProviderCategory('config', fn() => $provider->complete($conn, new LlmRequest('', 'Hallo')));
        $this->assertCount(0, $this->calls);
    }

    public function testOtherProviderConnectionIsConfigAndSendsNothing(): void {
        $provider = $this->provider([$this->ok('Antwort')]);
        // public target, so only the provider check can stop it
        $gemini = LlmConnection::fromArray(['enabled' => true, 'provider' => 'gemini', 'baseUrl' => 'https://api.example.com',
            'model' => 'llama3']);
        $this->assertProviderCategory('config', fn() => $provider->complete($gemini, new LlmRequest('', 'Hallo')));
        $this->assertCount(0, $this->calls);
    }

    /** @return array<string, array{string}> */
    public static function emptyBodies(): array {
        return [
            'empty text' => ['{"response":"   ","done":true}'],
            'no response field' => ['{"done":true}'],
            'not json' => ['<html>ok</html>'],
            'only reasoning' => ['{"response":"<think>nur Gedanken</think>","done":true}'],
        ];
    }

    /** @dataProvider emptyBodies */
    public function testNoAnswerTextIsEmpty(string $body): void {
        $provider = $this->provider([$this->httpResponse(200, $body)]);
        $this->assertProviderCategory('empty', fn() => $provider->complete($this->connection('http://ollama:11434', 'llama3'), new LlmRequest('', 'Hallo')));
        $this->assertCount(1, $this->calls);
    }

    public function testReasoningIsRemovedFromTheAnswer(): void {
        // intended change: 5.5 only trimmed, so deepseek-r1/qwen3 reasoning reached the learner
        $provider = $this->provider([$this->ok("<think>interne Gedanken</think>\nDie Antwort.")]);
        $this->assertSame('Die Antwort.', $provider->complete($this->connection('http://ollama:11434', 'llama3'), new LlmRequest('', 'Hallo'))->text);
    }

    public function testMissingUsageCountsAsZero(): void {
        $provider = $this->provider([$this->ok('Hi')]);
        $result = $provider->complete($this->connection('http://ollama:11434', 'llama3'), new LlmRequest('', 'Hallo'));
        $this->assertSame(0, $result->tokensIn);
        $this->assertSame(0, $result->tokensOut);
    }

    /** @return array<string, array{int, string}> */
    public static function errorStatuses(): array {
        return ['401' => [401, 'auth'], '403' => [403, 'auth'], '429' => [429, 'rate_limit'],
            '503' => [503, 'overloaded'], '529' => [529, 'overloaded'], '404' => [404, 'bad_request'], '500' => [500, 'network']];
    }

    /** @dataProvider errorStatuses */
    public function testErrorStatusMapsToCategoryWithoutRetry(int $status, string $category): void {
        $provider = $this->provider([
            $this->httpResponse($status, '{"error":"PROMPT-MARKER model not found"}'),
            $this->ok('zweiter Versuch'),
        ]);
        $e = $this->assertProviderCategory($category,
            fn() => $provider->complete($this->connection('http://ollama:11434', 'llama3'), new LlmRequest('', 'PROMPT-MARKER')));
        $this->assertCount(1, $this->calls, 'a failed request is never sent a second time');
        $this->assertStringNotContainsString('PROMPT-MARKER', $e->getMessage());
    }

    public function testTimeoutIsNotRetried(): void {
        $provider = $this->provider([
            new \RuntimeException('cURL error 28: Operation timed out after 90001 milliseconds'),
            $this->ok('zweiter Versuch'),
        ]);
        $this->assertProviderCategory('timeout',
            fn() => $provider->complete($this->connection('http://ollama:11434', 'llama3'), new LlmRequest('', 'Hallo')));
        $this->assertCount(1, $this->calls);
    }

    public function testAdapterHasNoLogger(): void {
        $ctor = (new \ReflectionClass(OllamaProvider::class))->getConstructor();
        $this->assertNotNull($ctor);
        foreach ($ctor->getParameters() as $param) {
            $this->assertNotSame(LoggerInterface::class, (string)$param->getType());
        }
    }

    // ─── helpers ───────────────────────────────────────────────────────────

    /** @param list<\OCP\Http\Client\IResponse|\Throwable> $outcomes */
    private function provider(array $outcomes): OllamaProvider {
        return new OllamaProvider($this->recordingHttp(self::DNS, $outcomes), new LlmOutputNormalizer());
    }

    private function connection(string $baseUrl, string $model): LlmConnection {
        return LlmConnection::fromArray(['rev' => 1, 'enabled' => true, 'provider' => 'ollama', 'baseUrl' => $baseUrl,
            'model' => $model, 'processingLocal' => true, 'allowInternalNetwork' => true]);
    }

    private function ok(string $text): \OCP\Http\Client\IResponse {
        return $this->httpResponse(200, (string)json_encode(['model' => 'llama3', 'response' => $text, 'done' => true]));
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
