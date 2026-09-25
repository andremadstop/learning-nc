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
     * A request fixture from tests/Fixtures/llm/ (pinned against the 5.5 providers by LegacyProviderPayloadTest).
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
