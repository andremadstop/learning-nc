<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service\Llm;

use OCA\Learning\Service\Llm\LlmConnection;
use OCA\Learning\Service\Llm\LlmHttpClient;
use OCA\Learning\Service\Llm\LlmProviderException;
use OCA\Learning\Service\Llm\TargetGuard;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\Http\Client\LocalServerException;
use PHPUnit\Framework\TestCase;

/**
 * 5.6.0 Task A3: every provider request goes through one guarded client — target checked and the
 * checked IP pinned for exactly this request, no redirects, no retries, bounded request and
 * response size, and failures reported as a category without the provider's body in the message.
 */
class LlmHttpClientTest extends TestCase {
    private const MISTRAL = 'https://api.mistral.ai/v1/chat/completions';

    /** @var list<array{string, array<string, mixed>}> */
    private array $calls = [];

    public function testRequestUsesPinnedIpAndNoRedirects(): void {
        $client = $this->client(['api.mistral.ai' => ['203.0.113.7']], [$this->response(200, '{"ok":true}')]);
        $result = $client->postJson($this->cloud(), self::MISTRAL, ['Authorization' => 'Bearer x'], ['a' => 1], 45);

        $this->assertSame(['status' => 200, 'json' => ['ok' => true], 'bodyBytes' => 11], $result);
        $this->assertCount(1, $this->calls);
        [$url, $opts] = $this->calls[0];
        $this->assertSame(self::MISTRAL, $url);
        $this->assertFalse($opts['allow_redirects']);
        $this->assertSame(['api.mistral.ai:443:203.0.113.7'], $opts['curl'][CURLOPT_RESOLVE]);
        $this->assertFalse($opts['http_errors'], 'status must come back as a response, not a Guzzle exception');
        $this->assertSame(45, $opts['timeout']);
        $this->assertSame(5, $opts['connect_timeout']);
        $this->assertSame(['allow_local_address' => false], $opts['nextcloud']);
        $this->assertSame('{"a":1}', $opts['body']);
        $this->assertSame('Bearer x', $opts['headers']['Authorization']);
        $this->assertSame('application/json', $opts['headers']['Content-Type']);
    }

    public function testNoRetryOn503(): void {
        $client = $this->client(['api.mistral.ai' => ['203.0.113.7']], [
            $this->response(503, '{"error":"busy"}'),
            $this->response(200, '{"ok":true}'),
        ]);
        $this->assertCategory('overloaded', fn() => $client->postJson($this->cloud(), self::MISTRAL, [], [], 45));
        $this->assertCount(1, $this->calls, 'a failed request must never be sent a second time');
    }

    public function testAdminDetailRedactsKeyLikeStrings(): void {
        $body = '{"error":{"message":"Incorrect API key provided: sk-abcdefghijklmnopqrstuvwxyz123 for org"}}';
        $client = $this->client(['api.mistral.ai' => ['203.0.113.7']], [$this->response(401, $body)]);
        $e = $this->assertCategory('auth', fn() => $client->postJson($this->cloud(), self::MISTRAL, [], [], 45));
        $this->assertStringContainsString('[redacted]', $e->adminDetail);
        $this->assertStringNotContainsString('sk-abcdefghijklmnopqrstuvwxyz123', $e->adminDetail);
        $this->assertStringContainsString('Incorrect API key provided', $e->adminDetail);
        $this->assertStringNotContainsString('Incorrect API key', $e->getMessage(), 'message never carries the provider body');
    }

    public function testAdminDetailRedactsBeforeTruncating(): void {
        // the key straddles the 200-character cut: truncating first would leave a <24-char fragment unredacted
        $key = 'sk-' . str_repeat('Q', 40);
        $body = str_repeat('x ', 94) . $key . ' tail';
        $client = $this->client(['api.mistral.ai' => ['203.0.113.7']], [$this->response(400, $body)]);
        $e = $this->assertCategory('bad_request', fn() => $client->postJson($this->cloud(), self::MISTRAL, [], [], 45));
        $this->assertStringNotContainsString('QQQQQQ', $e->adminDetail);
        $this->assertLessThanOrEqual(200, mb_strlen($e->adminDetail));
    }

    /** @return array<string, array{int, string}> */
    public static function statusCategories(): array {
        return [
            '400' => [400, 'bad_request'], '404' => [404, 'bad_request'], '422' => [422, 'bad_request'],
            '401' => [401, 'auth'], '403' => [403, 'auth'], '429' => [429, 'rate_limit'],
            '503' => [503, 'overloaded'], '529' => [529, 'overloaded'],
            '500' => [500, 'network'], '502' => [502, 'network'], '302' => [302, 'network'],
        ];
    }

    /** @dataProvider statusCategories */
    public function testStatusMapsToCategory(int $status, string $category): void {
        $client = $this->client(['api.mistral.ai' => ['203.0.113.7']], [$this->response($status, 'nope')]);
        $this->assertCategory($category, fn() => $client->postJson($this->cloud(), self::MISTRAL, [], [], 45));
        $this->assertCount(1, $this->calls);
    }

    public function testRejectedTargetNeverSends(): void {
        // DNS rebinding: the configured public name now answers with a LAN address
        $client = $this->client(['api.mistral.ai' => ['192.168.1.20']], [$this->response(200, '{}')]);
        $this->assertCategory('config', fn() => $client->postJson($this->cloud(), self::MISTRAL, [], [], 45));
        $this->assertCount(0, $this->calls);
    }

    public function testInternalFlagAllowsLocalTargetAndIsPassedToNextcloud(): void {
        $conn = $this->local();
        $client = $this->client(['ollama' => ['172.18.0.4']], [$this->response(200, '{"done":true}')]);
        $client->postJson($conn, 'http://ollama:11434/api/chat', [], ['m' => 'x'], 90);
        [, $opts] = $this->calls[0];
        $this->assertSame(['allow_local_address' => true], $opts['nextcloud']);
        $this->assertSame(['ollama:11434:172.18.0.4'], $opts['curl'][CURLOPT_RESOLVE]);
    }

    public function testIpv6PinUsesBrackets(): void {
        $client = $this->client(['api.mistral.ai' => ['2001:db8::7']], [$this->response(200, '{}')]);
        $client->postJson($this->cloud(), self::MISTRAL, [], [], 45);
        $this->assertSame(['api.mistral.ai:443:[2001:db8::7]'], $this->calls[0][1]['curl'][CURLOPT_RESOLVE]);
    }

    public function testOversizedRequestBodyNeverSends(): void {
        $client = $this->client(['api.mistral.ai' => ['203.0.113.7']], [$this->response(200, '{}')]);
        $big = ['p' => str_repeat('a', 200 * 1024)];
        $this->assertCategory('bad_request', fn() => $client->postJson($this->cloud(), self::MISTRAL, [], $big, 45));
        $this->assertCount(0, $this->calls);
    }

    public function testUnencodableBodyNeverSends(): void {
        $client = $this->client(['api.mistral.ai' => ['203.0.113.7']], [$this->response(200, '{}')]);
        $this->assertCategory('bad_request', fn() => $client->postJson($this->cloud(), self::MISTRAL, [], ['p' => "\xB1\x31"], 45));
        $this->assertCount(0, $this->calls);
    }

    public function testOversizedResponseRejected(): void {
        $big = '{"t":"' . str_repeat('a', 2 * 1024 * 1024) . '"}';
        $client = $this->client(['api.mistral.ai' => ['203.0.113.7']], [$this->response(200, $big)]);
        $this->assertCategory('bad_request', fn() => $client->postJson($this->cloud(), self::MISTRAL, [], [], 45));
    }

    public function testOversizedStreamResponseRejected(): void {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, str_repeat('a', 2 * 1024 * 1024 + 10));
        rewind($stream);
        $client = $this->client(['api.mistral.ai' => ['203.0.113.7']], [$this->response(200, $stream)]);
        $this->assertCategory('bad_request', fn() => $client->postJson($this->cloud(), self::MISTRAL, [], [], 45));
    }

    public function testStreamResponseIsRead(): void {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, '{"x":[1,2]}');
        rewind($stream);
        $client = $this->client(['api.mistral.ai' => ['203.0.113.7']], [$this->response(200, $stream)]);
        $this->assertSame(['status' => 200, 'json' => ['x' => [1, 2]], 'bodyBytes' => 11],
            $client->postJson($this->cloud(), self::MISTRAL, [], [], 45));
    }

    public function testNonJsonSuccessBodyGivesNullJson(): void {
        $client = $this->client(['api.mistral.ai' => ['203.0.113.7']], [$this->response(200, '<html>hi</html>')]);
        $this->assertSame(['status' => 200, 'json' => null, 'bodyBytes' => 15],
            $client->postJson($this->cloud(), self::MISTRAL, [], [], 45));
    }

    public function testLocalServerExceptionIsConfig(): void {
        $client = $this->client(['api.mistral.ai' => ['203.0.113.7']], [new LocalServerException('Host violates local access rules')]);
        $this->assertCategory('config', fn() => $client->postJson($this->cloud(), self::MISTRAL, [], [], 45));
        $this->assertCount(1, $this->calls);
    }

    public function testTimeoutIsTimeoutAndNotRetried(): void {
        $client = $this->client(['api.mistral.ai' => ['203.0.113.7']], [
            new \RuntimeException('cURL error 28: Operation timed out after 45001 milliseconds with 0 bytes received'),
            $this->response(200, '{}'),
        ]);
        $this->assertCategory('timeout', fn() => $client->postJson($this->cloud(), self::MISTRAL, [], [], 45));
        $this->assertCount(1, $this->calls);
    }

    public function testConnectFailureIsNetwork(): void {
        $client = $this->client(['api.mistral.ai' => ['203.0.113.7']], [new \RuntimeException('cURL error 7: Failed to connect to api.mistral.ai')]);
        $e = $this->assertCategory('network', fn() => $client->postJson($this->cloud(), self::MISTRAL, [], [], 45));
        $this->assertSame('network', $e->getMessage());
    }

    public function testExceptionMessageNeverContainsBody(): void {
        $client = $this->client(['api.mistral.ai' => ['203.0.113.7']], [$this->response(429, '{"error":{"message":"PROMPT-MARKER quota"}}')]);
        $e = $this->assertCategory('rate_limit', fn() => $client->postJson($this->cloud(), self::MISTRAL, [], [], 45));
        $this->assertStringNotContainsString('PROMPT-MARKER', $e->getMessage());
        $this->assertStringContainsString('PROMPT-MARKER quota', $e->adminDetail);
    }

    // ─── helpers ───────────────────────────────────────────────────────────

    private function cloud(): LlmConnection {
        return LlmConnection::fromArray(['rev' => 1, 'enabled' => true, 'provider' => 'openai_compatible', 'preset' => 'mistral',
            'baseUrl' => 'https://api.mistral.ai/v1', 'model' => 'mistral-small-latest']);
    }

    private function local(): LlmConnection {
        return LlmConnection::fromArray(['rev' => 1, 'enabled' => true, 'provider' => 'ollama',
            'baseUrl' => 'http://ollama:11434', 'model' => 'llama3', 'processingLocal' => true, 'allowInternalNetwork' => true]);
    }

    /**
     * @param array<string, list<string>> $dns
     * @param list<IResponse|\Throwable> $outcomes one per post() call, in order
     */
    private function client(array $dns, array $outcomes): LlmHttpClient {
        $http = $this->createMock(IClient::class);
        $http->method('post')->willReturnCallback(function (string $url, array $opts) use (&$outcomes) {
            $this->calls[] = [$url, $opts];
            $next = array_shift($outcomes);
            if ($next instanceof \Throwable) {
                throw $next;
            }
            return $next;
        });
        $service = $this->createMock(IClientService::class);
        $service->method('newClient')->willReturn($http);
        return new LlmHttpClient($service, new TargetGuard(fn(string $h) => $dns[$h] ?? []));
    }

    /** @param string|resource $body */
    private function response(int $status, $body): IResponse {
        $r = $this->createMock(IResponse::class);
        $r->method('getStatusCode')->willReturn($status);
        $r->method('getBody')->willReturn($body);
        $r->method('getHeaders')->willReturn([]);
        $r->method('getHeader')->willReturn('');
        return $r;
    }

    private function assertCategory(string $category, callable $fn): LlmProviderException {
        try {
            $fn();
        } catch (LlmProviderException $e) {
            $this->assertSame($category, $e->category);
            return $e;
        }
        $this->fail("expected LlmProviderException($category)");
    }
}
