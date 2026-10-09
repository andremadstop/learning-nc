<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\AdminAiController;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\LlmConnection;
use OCA\Learning\Service\Llm\LlmGateway;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * 5.7.0 Task B5: the admin's connection test (POST /api/admin/ai/test). Admin-only, rate-limited,
 * only the backends 5.7.0 routes (Gemini, Ollama), and the key never comes back.
 */
class AdminAiControllerTest extends TestCase {
    private const KEY = 'AIzaCANDIDATE-KEY-0f3c9e';

    private LlmGateway&MockObject $gateway;

    protected function setUp(): void {
        $this->gateway = $this->createMock(LlmGateway::class);
    }

    private function controller(): AdminAiController {
        return new AdminAiController('learning', $this->createMock(IRequest::class), $this->gateway);
    }

    private static function ok(): array {
        return ['ok' => true, 'model' => 'gemini-2.5-flash', 'latencyMs' => 12, 'category' => null, 'detail' => null];
    }

    public function testGeminiCandidateIsTestedWithTheCandidateKey(): void {
        $this->gateway->expects($this->once())->method('testConnection')
            ->with(
                $this->callback(fn (LlmConnection $c) => $c->provider === 'gemini'
                    && $c->enabled
                    && $c->connectionFingerprint() === ConnectionStore::legacyGemini(true)->connectionFingerprint()),
                self::KEY
            )
            ->willReturn(self::ok());

        $resp = $this->controller()->test('gemini', '', '', self::KEY);

        $this->assertSame(200, $resp->getStatus());
        $this->assertTrue($resp->getData()['ok']);
        $this->assertSame('gemini-2.5-flash', $resp->getData()['model']);
    }

    public function testOllamaCandidateUsesTheGivenEndpointAndNeverAKey(): void {
        $this->gateway->expects($this->once())->method('testConnection')
            ->with(
                $this->callback(fn (LlmConnection $c) => $c->provider === 'ollama'
                    && $c->baseUrl === 'http://ollama.lan:11434' && $c->model === 'qwen3'),
                null
            )
            ->willReturn(self::ok());

        $resp = $this->controller()->test('ollama', 'http://ollama.lan:11434', 'qwen3', self::KEY);

        $this->assertSame(200, $resp->getStatus());
    }

    /** @return array<string, array{string}> */
    public static function unavailableProviders(): array {
        return [
            'openai_compatible (5.8.0)' => ['openai_compatible'],
            'anthropic (5.8.0)' => ['anthropic'],
            'unknown' => ['deepseek'],
            'disabled: nothing to test' => ['disabled'],
            'empty' => [''],
        ];
    }

    /** @dataProvider unavailableProviders */
    public function testProvidersNotOfferedIn570AreRejectedWithoutARequest(string $provider): void {
        $this->gateway->expects($this->never())->method('testConnection');

        $resp = $this->controller()->test($provider, 'http://127.0.0.1:18080/v1', 'gpt-x', self::KEY);

        $this->assertSame(400, $resp->getStatus());
        $this->assertStringNotContainsString(self::KEY, (string)json_encode($resp->getData()));
    }

    public function testAnInvalidOllamaEndpointIsRejectedWithoutARequest(): void {
        $this->gateway->expects($this->never())->method('testConnection');

        $resp = $this->controller()->test('ollama', 'ftp://ollama.lan', 'qwen3');

        $this->assertSame(400, $resp->getStatus());
    }

    public function testTheKeyNeverComesBackEvenIfAnErrorDetailCarriesIt(): void {
        $this->gateway->method('testConnection')->willReturn([
            'ok' => false, 'model' => null, 'latencyMs' => 40, 'category' => 'auth',
            'detail' => 'HTTP 400: API key not valid: ' . self::KEY,
        ]);

        $resp = $this->controller()->test('gemini', '', '', self::KEY);

        $this->assertSame(200, $resp->getStatus());
        $this->assertFalse($resp->getData()['ok']);
        $this->assertSame('auth', $resp->getData()['category']);
        $this->assertStringNotContainsString(self::KEY, (string)json_encode($resp->getData()));
    }

    public function testIsAdminOnlyAndRateLimitedToFivePerMinute(): void {
        $m = new \ReflectionMethod(AdminAiController::class, 'test');

        $doc = (string)$m->getDocComment();
        $this->assertStringNotContainsString('@NoAdminRequired', $doc);
        $this->assertStringNotContainsString('@PublicPage', $doc);
        $this->assertStringNotContainsString('@NoCSRFRequired', $doc);
        $names = array_map(static fn (\ReflectionAttribute $a) => $a->getName(), $m->getAttributes());
        $this->assertNotContains('OCP\AppFramework\Http\Attributes\NoAdminRequired', $names);
        $this->assertNotContains('OCP\AppFramework\Http\Attributes\PublicPage', $names);

        $limits = $m->getAttributes(UserRateLimit::class);
        $this->assertCount(1, $limits);
        $this->assertSame(['limit' => 5, 'period' => 60], $limits[0]->getArguments());
    }

    public function testRouteIsRegisteredAsPost(): void {
        $routes = require __DIR__ . '/../../../appinfo/routes.php';
        $match = array_values(array_filter($routes['routes'], static fn (array $r) => $r['name'] === 'adminAi#test'));
        $this->assertCount(1, $match);
        $this->assertSame('/api/admin/ai/test', $match[0]['url']);
        $this->assertSame('POST', $match[0]['verb']);
    }
}
