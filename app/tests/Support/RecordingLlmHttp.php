<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Support;

use OCA\Learning\Service\Llm\LlmHttpClient;
use OCA\Learning\Service\Llm\LlmProviderException;
use OCA\Learning\Service\Llm\TargetGuard;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;

/**
 * For provider adapter tests: a real LlmHttpClient (final, so not mockable) over a mocked
 * Nextcloud client that records every post() and answers from a queue, with a fake resolver.
 * Use inside a PHPUnit TestCase.
 */
trait RecordingLlmHttp {
    /** @var list<array{string, array<string, mixed>}> */
    private array $calls = [];

    /**
     * @param array<string, list<string>> $dns
     * @param list<IResponse|\Throwable> $outcomes one per post() call, in order
     */
    private function recordingHttp(array $dns, array $outcomes): LlmHttpClient {
        $http = $this->createMock(IClient::class);
        $http->method('post')->willReturnCallback(function (string $url, array $opts) use (&$outcomes) {
            $this->calls[] = [$url, $opts];
            $next = array_shift($outcomes);
            if ($next === null) {
                throw new \LogicException('unexpected extra request');
            }
            if ($next instanceof \Throwable) {
                throw $next;
            }
            return $next;
        });
        $service = $this->createMock(IClientService::class);
        $service->method('newClient')->willReturn($http);
        return new LlmHttpClient($service, new TargetGuard(fn(string $h) => $dns[$h] ?? []));
    }

    private function httpResponse(int $status, string $body): IResponse {
        $r = $this->createMock(IResponse::class);
        $r->method('getStatusCode')->willReturn($status);
        $r->method('getBody')->willReturn($body);
        $r->method('getHeaders')->willReturn([]);
        $r->method('getHeader')->willReturn('');
        return $r;
    }

    /**
     * A request fixture from tests/Fixtures/llm/ (pinned against the 5.5 providers by LegacyProviderPayloadTest until Task B4 removed them).
     *
     * @return array<string, mixed>
     */
    private static function llmFixture(string $file): array {
        $data = json_decode((string)file_get_contents(__DIR__ . '/../Fixtures/llm/' . $file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException("fixture $file is not an object");
        }
        return $data;
    }

    /**
     * @param array<string, mixed> $fixture
     * @return array<string, mixed>
     */
    private static function llmFixtureCase(array $fixture, string $name): array {
        foreach ($fixture['cases'] as $case) {
            if ($case['name'] === $name) {
                return $case;
            }
        }
        throw new \RuntimeException("fixture case $name missing");
    }

    /** @return array<string, array{string}> */
    private static function llmFixtureCaseNames(string $file): array {
        $names = [];
        foreach (self::llmFixture($file)['cases'] as $case) {
            $names[$case['name']] = [$case['name']];
        }
        return $names;
    }

    /**
     * No prompt, answer, key or provider error text leaves the adapter other than in the request.
     *
     * Two checks, because neither alone is enough:
     * - statically, the adapter source uses no logging or output sink at all (no PSR logger, no
     *   Nextcloud server lookup that could fetch one, no error_log/syslog/echo/print/file write);
     * - at runtime, the given calls (success and failure paths) run with PHP's error log redirected
     *   to a temp file and output buffered, and none of the markers may appear in either.
     *
     * @param class-string $adapterClass
     * @param list<callable(): mixed> $runs each run may throw; exceptions are part of the path
     * @param list<string> $markers
     */
    private function assertAdapterWritesNoContent(string $adapterClass, array $runs, array $markers): void {
        $file = (new \ReflectionClass($adapterClass))->getFileName();
        $this->assertIsString($file);
        $source = (string)file_get_contents($file);
        // strip comments, so the doc text ("no logging here") cannot mask or trigger a match
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        $this->assertDoesNotMatchRegularExpression(
            '/Logger|\bILogger\b|Server::get|\\\\OC::|\b(error_log|syslog|openlog|trigger_error|user_error|echo|print|printf|vprintf|fprintf|'
            . 'var_dump|var_export|print_r|debug_zval_dump|file_put_contents|fwrite|fputs|fopen)\b/i',
            $code,
            "$adapterClass must not log or write anything",
        );

        $log = tempnam(sys_get_temp_dir(), 'llm-adapter-log');
        $this->assertIsString($log);
        $oldLog = ini_set('error_log', $log);
        $oldLogErrors = ini_set('log_errors', '1');
        ob_start();
        try {
            foreach ($runs as $run) {
                try {
                    $run();
                } catch (LlmProviderException) {
                    // failure paths are part of what is checked
                }
            }
        } finally {
            $output = (string)ob_get_clean();
            ini_set('error_log', $oldLog === false ? '' : $oldLog);
            ini_set('log_errors', $oldLogErrors === false ? '0' : $oldLogErrors);
            $written = (string)file_get_contents($log);
            unlink($log);
        }
        foreach ($markers as $marker) {
            $this->assertStringNotContainsString($marker, $written, "error log contains $marker");
            $this->assertStringNotContainsString($marker, $output, "output contains $marker");
        }
    }

    private function assertProviderCategory(string $category, callable $fn): LlmProviderException {
        try {
            $fn();
        } catch (LlmProviderException $e) {
            $this->assertSame($category, $e->category);
            return $e;
        }
        $this->fail("expected LlmProviderException($category)");
    }
}
