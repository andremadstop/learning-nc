<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service\Llm\Provider;

require_once __DIR__ . '/../../../../Support/LegacyCurlRecorder.php';

use OCA\Learning\Service\GeminiProvider as LegacyGeminiProvider;
use OCA\Learning\Service\OllamaProvider as LegacyOllamaProvider;
use OCA\Learning\Tests\Support\LegacyCurlRecorder;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 5.6.0 Task A5 (fixture first): pins what the 5.5 providers send and how they read the answer,
 * against the live classes, before the adapters under lib/Service/Llm/Provider/ replace them.
 * The adapter tests check the new classes against the same fixtures, so any difference between
 * old and new request is either visible here or named there as intended.
 *
 * The old classes call cURL directly; LegacyCurlRecorder intercepts those calls.
 */
class LegacyProviderPayloadTest extends TestCase {

    protected function tearDown(): void {
        LegacyCurlRecorder::disarm();
    }

    /** @return array<string, array{string}> */
    public static function geminiCases(): array {
        return self::caseNames('gemini-request.json');
    }

    /** @return array<string, array{string}> */
    public static function ollamaCases(): array {
        return self::caseNames('ollama-request.json');
    }

    /** @dataProvider geminiCases */
    public function testLegacyGeminiRequestMatchesFixture(string $name): void {
        $fixture = self::fixture('gemini-request.json');
        $case = self::case($fixture, $name);
        LegacyCurlRecorder::arm((string)json_encode($fixture['response']['body']));

        $this->legacyGemini($fixture['apiKey'])->generateText($case['input']['userPrompt'], self::legacyContext($case['input']));

        $this->assertLegacyRequest($case['request']);
    }

    /** @dataProvider ollamaCases */
    public function testLegacyOllamaRequestMatchesFixture(string $name): void {
        $fixture = self::fixture('ollama-request.json');
        $case = self::case($fixture, $name);
        LegacyCurlRecorder::arm((string)json_encode($fixture['response']['body']));

        $this->legacyOllama($fixture['baseUrl'], $fixture['model'])
            ->generateText($case['input']['userPrompt'], self::legacyContext($case['input']));

        $this->assertLegacyRequest($case['request']);
    }

    public function testLegacyGeminiResponseParsingMatchesFixture(): void {
        $fixture = self::fixture('gemini-request.json');
        LegacyCurlRecorder::arm((string)json_encode($fixture['response']['body']));
        $text = $this->legacyGemini($fixture['apiKey'])->generateText('Hallo');
        $this->assertSame($fixture['response']['text'], $text);
    }

    public function testLegacyOllamaResponseParsingMatchesFixture(): void {
        $fixture = self::fixture('ollama-request.json');
        LegacyCurlRecorder::arm((string)json_encode($fixture['response']['body']));
        $text = $this->legacyOllama($fixture['baseUrl'], $fixture['model'])->generateText('Hallo');
        $this->assertSame($fixture['response']['text'], $text);
    }

    // ─── helpers ───────────────────────────────────────────────────────────

    /** @param array<string, mixed> $expected */
    private function assertLegacyRequest(array $expected): void {
        $this->assertCount(1, LegacyCurlRecorder::$requests);
        $sent = LegacyCurlRecorder::$requests[0];
        $opts = $sent['options'];

        $this->assertSame($expected['url'], $sent['url']);
        $this->assertTrue($opts[CURLOPT_POST]);
        $this->assertSame($expected['timeoutSec'], $opts[CURLOPT_TIMEOUT]);

        $headers = [];
        foreach ($opts[CURLOPT_HTTPHEADER] as $line) {
            [$name, $value] = explode(':', $line, 2);
            $headers[$name] = trim($value);
        }
        $this->assertSame($expected['headers'], $headers);

        // decoded, so types and key order count but not the byte formatting of the JSON
        $this->assertSame($expected['body'], json_decode($opts[CURLOPT_POSTFIELDS], true));
    }

    private function legacyGemini(string $apiKey): LegacyGeminiProvider {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            fn(string $app, string $key, string $default = '') => $key === 'gemini_api_key' ? $apiKey : $default);
        return new LegacyGeminiProvider($config, $this->createMock(LoggerInterface::class));
    }

    private function legacyOllama(string $baseUrl, string $model): LegacyOllamaProvider {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(fn(string $app, string $key, string $default = '') => match ($key) {
            'ai_ollama_url' => $baseUrl,
            'ai_ollama_model' => $model,
            default => $default,
        });
        return new LegacyOllamaProvider($config);
    }

    /**
     * The 5.5 context array for a fixture input; null means the caller did not pass the value.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function legacyContext(array $input): array {
        $context = ['system_prompt' => $input['systemPrompt']];
        if ($input['temperature'] !== null) {
            $context['temperature'] = $input['temperature'];
        }
        if ($input['maxOutputTokens'] !== null) {
            $context['max_output_tokens'] = $input['maxOutputTokens'];
        }
        return $context;
    }

    /** @return array<string, mixed> */
    public static function fixture(string $file): array {
        $data = json_decode((string)file_get_contents(__DIR__ . '/../../../../Fixtures/llm/' . $file), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }

    /**
     * @param array<string, mixed> $fixture
     * @return array<string, mixed>
     */
    public static function case(array $fixture, string $name): array {
        foreach ($fixture['cases'] as $case) {
            if ($case['name'] === $name) {
                return $case;
            }
        }
        self::fail("fixture case $name missing");
    }

    /** @return array<string, array{string}> */
    private static function caseNames(string $file): array {
        $names = [];
        foreach (self::fixture($file)['cases'] as $case) {
            $names[$case['name']] = [$case['name']];
        }
        return $names;
    }
}
