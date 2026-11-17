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
 * The public calendar feed (ics#feed) and its legacy alias (export#exportIcsPublic): only a token
 * that cannot be a feed token feeds the brute-force throttle. A well-formed unknown token is what an
 * old subscription keeps polling after the token was regenerated; throttling it would block every
 * valid subscriber behind the same address (Nextcloud refuses the whole action for that address
 * after 10 throttled attempts in 30 minutes). The middleware side runs in
 * tests/Integration/IcsFeedThrottleTest.php.
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

    #[DataProvider('routes')]
    public function testAMalformedTokenIsThrottled(string $route): void {
        foreach (['not-a-token', str_repeat('A', 64), str_repeat('a', 63), ''] as $token) {
            $response = $this->call($route, $token);
            $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus(), $token);
            $this->assertTrue($response->isThrottled(), "'$token' must feed the brute-force throttle");
            $this->assertSame(['action' => IcsService::FEED_THROTTLE_ACTION], $response->getThrottleMetadata());
        }
    }

    #[DataProvider('routes')]
    public function testAWellFormedUnknownTokenIsRefusedWithoutThrottling(string $route): void {
        $response = $this->call($route, self::STALE);
        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $this->assertFalse($response->isThrottled());
    }

    #[DataProvider('routes')]
    public function testAValidTokenGetsTheCalendar(string $route): void {
        $response = $this->call($route, self::VALID);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertFalse($response->isThrottled());
    }

    public function testBothRoutesShareOneBruteForceAction(): void {
        foreach ([[IcsController::class, 'feed'], [ExportController::class, 'exportIcsPublic']] as [$class, $method]) {
            $attrs = (new \ReflectionMethod($class, $method))->getAttributes();
            $names = array_map(static fn (\ReflectionAttribute $a): string => $a->getName(), $attrs);
            $this->assertContains('OCP\\AppFramework\\Http\\Attribute\\BruteForceProtection', $names, "$class::$method");
            foreach ($attrs as $a) {
                if ($a->getName() === 'OCP\\AppFramework\\Http\\Attribute\\BruteForceProtection') {
                    $this->assertSame(['action' => IcsService::FEED_THROTTLE_ACTION], $a->getArguments());
                }
            }
        }
    }
}
