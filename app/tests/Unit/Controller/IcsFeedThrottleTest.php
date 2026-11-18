<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\ExportController;
use OCA\Learning\Controller\IcsController;
use OCA\Learning\Service\DataMobilityService;
use OCA\Learning\Service\IcsService;
use OCA\Learning\Service\QuestionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The public calendar feed (ics#feed) and its legacy alias (export#exportIcsPublic) carry no
 * brute-force throttle. Tokens carry 256 bits and anything that is not 64 lowercase hex is refused
 * before any lookup, so there is nothing to guess; Nextcloud's throttle, though, refuses the whole
 * action for an address, valid tokens included, after more than 10 throttled requests in 30 minutes,
 * so a throttle there only lets one person behind a shared address lock everyone there out of their
 * feed. Only the per-address rate limit bounds the polling (RateLimitEnforcedTest). The middleware
 * side runs in tests/Integration/IcsFeedThrottleTest.php.
 */
#[AllowMockObjectsWithoutExpectations]
final class IcsFeedThrottleTest extends TestCase {
    private const VALID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const STALE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private function icsService(): IcsService {
        $service = $this->createMock(IcsService::class);
        $service->method('renderCalendarForToken')->willReturnCallback(
            static fn (string $token): ?string => $token === self::VALID ? "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n" : null);
        return $service;
    }

    /** @return array<string, array{string}> */
    public static function routes(): array {
        return ['ics#feed' => ['feed'], 'export#exportIcsPublic' => ['legacy']];
    }

    private function call(string $route, string $token): Response {
        $request = $this->createMock(IRequest::class);
        if ($route === 'feed') {
            return (new IcsController('learning', $request, $this->icsService(), null))->feed($token);
        }
        $controller = new ExportController('learning', $request,
            $this->createMock(QuestionService::class), $this->createMock(DataMobilityService::class),
            $this->icsService(), null);
        return $controller->exportIcsPublic($token);
    }

    /** @return list<string> tokens as v2.7.0's ExportController::buildIcsToken built them */
    private static function legacyTokens(): array {
        $b64url = static fn (string $uid): string => rtrim(strtr(base64_encode($uid), '+/', '-_'), '=');
        return [
            $b64url('alice') . '.' . hash_hmac('sha256', 'alice:nonce', 'secret'),
            $b64url('Max Müller?>') . '.' . hash_hmac('sha256', 'x', 'y'),
        ];
    }

    #[DataProvider('routes')]
    public function testNoRefusedTokenIsThrottled(string $route): void {
        $tokens = array_merge(['not-a-token', str_repeat('A', 64), str_repeat('a', 63), '', self::STALE],
            self::legacyTokens());
        foreach ($tokens as $token) {
            $response = $this->call($route, $token);
            $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus(), $token);
            $this->assertFalse($response->isThrottled(), "'$token' must not feed a brute-force throttle");
        }
    }

    #[DataProvider('routes')]
    public function testAValidTokenGetsTheCalendar(string $route): void {
        $response = $this->call($route, self::VALID);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertFalse($response->isThrottled());
    }

    /**
     * BruteForceMiddleware::beforeController delays or refuses every request to a route with this
     * attribute once its address has throttled attempts, so the attribute alone is the lockout.
     */
    public function testNeitherRouteCarriesBruteForceProtection(): void {
        foreach ([[IcsController::class, 'feed'], [ExportController::class, 'exportIcsPublic']] as [$class, $method]) {
            $names = array_map(static fn (\ReflectionAttribute $a): string => $a->getName(),
                (new \ReflectionMethod($class, $method))->getAttributes());
            $this->assertNotContains('OCP\\AppFramework\\Http\\Attribute\\BruteForceProtection', $names, "$class::$method");
            $this->assertContains('OCP\\AppFramework\\Http\\Attribute\\AnonRateLimit', $names, "$class::$method");
            $doc = (string)(new \ReflectionMethod($class, $method))->getDocComment();
            $this->assertStringNotContainsStringIgnoringCase('@BruteForceProtection', $doc, "$class::$method");
        }
    }
}
