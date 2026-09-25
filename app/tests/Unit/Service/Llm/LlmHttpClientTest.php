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

    public function testConnectToMakesTheCheckedAddressAuthoritative(): void {
        // Nextcloud's DnsPinMiddleware appends its own CURLOPT_RESOLVE entries (later ones win) and an
        // HTTP proxy resolves the name itself; CURLOPT_CONNECT_TO with the checked IP literal beats both
        // (verified with libcurl 8.14: direct and proxy CONNECT go to the connect-to address).
        $client = $this->client(['api.mistral.ai' => ['203.0.113.7'], 'v6.example.com' => ['2001:db8::7']],
            [$this->response(200, '{}'), $this->response(200, '{}')]);
        $client->postJson($this->cloud(), self::MISTRAL, [], [], 45);
        $client->postJson($this->cloud(), 'https://v6.example.com:8443/v1', [], [], 45);
        $this->assertSame(['api.mistral.ai:443:203.0.113.7:443'], $this->calls[0][1]['curl'][CURLOPT_CONNECT_TO]);
        $this->assertSame(['v6.example.com:8443:[2001:db8::7]:8443'], $this->calls[1][1]['curl'][CURLOPT_CONNECT_TO]);
    }

    public function testResponseLimitIsEnforcedDuringTheTransfer(): void {
        $client = $this->client(['api.mistral.ai' => ['203.0.113.7']], [$this->response(200, '{}')]);
        $client->postJson($this->cloud(), self::MISTRAL, [], [], 45);
        $curl = $this->calls[0][1]['curl'];
        $this->assertSame(LlmHttpClient::MAX_RESPONSE_BYTES, $curl[CURLOPT_MAXFILESIZE]);
        $this->assertFalse($curl[CURLOPT_NOPROGRESS]);
        $progress = $curl[CURLOPT_XFERINFOFUNCTION];
        $this->assertSame(0, $progress(null, 0, LlmHttpClient::MAX_RESPONSE_BYTES, 0, 0), 'at the limit: continue');
        $this->assertNotSame(0, $progress(null, 0, LlmHttpClient::MAX_RESPONSE_BYTES + 1, 0, 0), 'over the limit: abort');
    }

    public function testNoTransparentDecompression(): void {
        // a small gzip body could expand far beyond 2 MB after the byte limits were checked
        $client = $this->client(['api.mistral.ai' => ['203.0.113.7']], [$this->response(200, '{}')]);
        $client->postJson($this->cloud(), self::MISTRAL, [], [], 45);
        $this->assertFalse($this->calls[0][1]['decode_content']);
        $this->assertSame('identity', $this->calls[0][1]['headers']['Accept-Encoding']);
    }

    public function testCompressedResponseRejected(): void {
        $client = $this->client(['api.mistral.ai' => ['203.0.113.7']], [$this->response(200, "\x1f\x8b\x08", ['Content-Encoding' => 'gzip'])]);
        $this->assertCategory('bad_request', fn() => $client->postJson($this->cloud(), self::MISTRAL, [], [], 45));
    }

    public function testAbortedTransferIsBadRequest(): void {
        $client = $this->client(['api.mistral.ai' => ['203.0.113.7']], [new \RuntimeException('cURL error 42: Callback aborted')]);
        $this->assertCategory('bad_request', fn() => $client->postJson($this->cloud(), self::MISTRAL, [], [], 45));
    }

    public function testTransportDetailHasNoUrlOrAddress(): void {
        $client = $this->client(['api.mistral.ai' => ['203.0.113.7']], [new \RuntimeException(
            'cURL error 7: Failed to connect to 192.168.1.20 port 443 (fd00::1): refused for https://api.mistral.ai/v1?key=abc123')]);
        $e = $this->assertCategory('network', fn() => $client->postJson($this->cloud(), self::MISTRAL, [], [], 45));
        $this->assertStringContainsString('cURL error 7', $e->adminDetail);
        foreach (['192.168.1.20', 'fd00::1', 'abc123', 'mistral'] as $leak) {
            $this->assertStringNotContainsString($leak, $e->adminDetail);
        }
    }

    public function testAdminDetailRedactsTheSuppliedCredentials(): void {
        // shorter than 24 characters and with punctuation: the generic pattern alone would miss them
        $body = '{"error":{"message":"bad key abcd/efgh+ijkl= and x9Kq-short for q7Tok"}}';
        $client = $this->client(['api.mistral.ai' => ['203.0.113.7']], [$this->response(401, $body)]);
        $e = $this->assertCategory('auth', fn() => $client->postJson($this->cloud(), self::MISTRAL . '?api_key=q7Tok',
            ['Authorization' => 'Bearer abcd/efgh+ijkl=', 'x-api-key' => 'x9Kq-short'], [], 45));
        foreach (['abcd/efgh+ijkl=', 'x9Kq-short', 'q7Tok'] as $secret) {
            $this->assertStringNotContainsString($secret, $e->adminDetail);
        }
        $this->assertStringContainsString('bad key [redacted]', $e->adminDetail);
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

    /**
     * @param string|resource $body
     * @param array<string, string> $headers
     */
    private function response(int $status, $body, array $headers = []): IResponse {
        $r = $this->createMock(IResponse::class);
        $r->method('getStatusCode')->willReturn($status);
        $r->method('getBody')->willReturn($body);
        $r->method('getHeaders')->willReturn(array_map(fn(string $v) => [$v], $headers));
        $r->method('getHeader')->willReturnCallback(function (string $key) use ($headers): string {
            foreach ($headers as $name => $value) {
                if (strcasecmp($name, $key) === 0) {
                    return $value;
                }
            }
            return '';
        });
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
