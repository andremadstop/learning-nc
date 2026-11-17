<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OC\AppFramework\Middleware\Security\BruteForceMiddleware;
use OC\AppFramework\Utility\ControllerMethodReflector;
use OCA\Learning\Controller\ExportController;
use OCA\Learning\Controller\IcsController;
use OCA\Learning\Service\IcsService;
use OCA\Learning\Tests\Integration\Support\ContainerObjects;
use OCP\AppFramework\App;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\Security\Bruteforce\IThrottler;
use OCP\Security\Bruteforce\MaxDelayReached;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The public calendar feed through Nextcloud's own BruteForceMiddleware and Throttler (real server
 * classes, database backend): a valid token gets its calendar; an address that sends many malformed
 * tokens is refused with 429, valid token included, while other addresses keep their feed; and
 * well-formed unknown tokens (old subscriptions after a token regeneration) never block an address.
 * The per-address rate limit itself is in RateLimitEnforcedTest.
 */
final class IcsFeedThrottleTest extends TestCase {
    use ContainerObjects;

    private const UID = 'ics-throttle-probe-user';

    private ContainerInterface $container;
    private string $remoteAddress = '198.51.100.10';
    private string $validToken = '';

    protected function setUp(): void {
        $this->container = (new App('learning'))->getContainer();
        $this->clear();
        $token = Server::get(IcsService::class)->ensureCalendarToken(self::UID)['token'];
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->validToken = $token;
    }

    protected function tearDown(): void {
        $this->clear();
    }

    private function clear(): void {
        $db = Server::get(IDBConnection::class);
        $qb = $db->getQueryBuilder();
        $qb->delete('bruteforce_attempts')
            ->where($qb->expr()->eq('action', $qb->createNamedParameter(IcsService::FEED_THROTTLE_ACTION)))
            ->executeStatement();
        $qb = $db->getQueryBuilder();
        $qb->delete('learning_user_telos')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::UID)))
            ->executeStatement();
    }

    /** One request through the brute-force middleware; returns the HTTP status the client sees. */
    private function fetch(Controller $controller, string $method, string $token): int {
        $reflector = new ControllerMethodReflector(Server::get(LoggerInterface::class));
        $reflector->reflect($controller, $method);
        $request = $this->createMock(IRequest::class);
        $request->method('getRemoteAddress')->willReturnCallback(fn (): string => $this->remoteAddress);
        $mw = new BruteForceMiddleware($reflector, Server::get(IThrottler::class), $request,
            Server::get(LoggerInterface::class));
        try {
            $mw->beforeController($controller, $method);
        } catch (MaxDelayReached $e) {
            return $mw->afterException($controller, $method, $e)->getStatus();
        }
        $response = $controller->{$method}($token);
        $this->assertInstanceOf(Response::class, $response);
        return $mw->afterController($controller, $method, $response)->getStatus();
    }

    /** @return array{Controller, string} */
    private function route(string $which): array {
        return $which === 'feed'
            ? [$this->controllerFor($this->container, IcsController::class, null), 'feed']
            : [$this->controllerFor($this->container, ExportController::class, null), 'exportIcsPublic'];
    }

    public function testAValidTokenGetsTheCalendarOnBothRoutes(): void {
        foreach (['feed', 'legacy'] as $which) {
            [$controller, $method] = $this->route($which);
            $this->assertSame(200, $this->fetch($controller, $method, $this->validToken), $which);
        }
    }

    public function testManyMalformedTokensBlockThatAddressOnly(): void {
        [$feed, $feedMethod] = $this->route('feed');
        [$legacy, $legacyMethod] = $this->route('legacy');
        // Nextcloud blocks after more than 10 throttled attempts within 30 minutes (auth.bruteforce.max-attempts).
        // Ten probes, alternating routes, still get their 403 ...
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(403, $this->fetch($feed, $feedMethod, 'probe-' . $i));
            $this->assertSame(403, $this->fetch($legacy, $legacyMethod, 'probe-legacy-' . $i));
        }
        $this->assertSame(10, Server::get(IThrottler::class)->getAttempts($this->remoteAddress, IcsService::FEED_THROTTLE_ACTION));
        // ... the eleventh is answered with 429.
        $this->assertSame(429, $this->fetch($feed, $feedMethod, 'probe-11'));

        // The probing address is refused, valid token included, on both routes (one shared action).
        $this->assertSame(429, $this->fetch($feed, $feedMethod, $this->validToken));
        $this->assertSame(429, $this->fetch($legacy, $legacyMethod, $this->validToken));

        // Every other address keeps its feed.
        $this->remoteAddress = '198.51.100.11';
        $this->assertSame(200, $this->fetch($feed, $feedMethod, $this->validToken));
    }

    public function testWellFormedUnknownTokensNeverBlockAnAddress(): void {
        [$feed, $feedMethod] = $this->route('feed');
        $this->remoteAddress = '198.51.100.12';
        // An old subscription polling every minute for 20 minutes with a regenerated-away token.
        $stale = str_repeat('0', 64);
        $this->assertNotSame($this->validToken, $stale);
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame(403, $this->fetch($feed, $feedMethod, $stale));
        }
        $this->assertSame(0, Server::get(IThrottler::class)->getAttempts($this->remoteAddress, IcsService::FEED_THROTTLE_ACTION));
        $this->assertSame(200, $this->fetch($feed, $feedMethod, $this->validToken));
    }

    public function testV270SubscriptionsOnTheLegacyRouteNeverBlockAnAddress(): void {
        [$legacy, $legacyMethod] = $this->route('legacy');
        $this->remoteAddress = '198.51.100.13';
        // v2.7.0 tokens: base64url(user id) + '.' + HMAC hex. Two old subscriptions behind one NAT,
        // twenty polls in all: more than the 10 throttled attempts that would block the address.
        $b64url = static fn (string $uid): string => rtrim(strtr(base64_encode($uid), '+/', '-_'), '=');
        $tokens = [
            $b64url('alice') . '.' . hash_hmac('sha256', 'alice:n1', 'secret'),
            $b64url('bob') . '.' . hash_hmac('sha256', 'bob:n2', 'secret'),
        ];
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame(403, $this->fetch($legacy, $legacyMethod, $tokens[$i % 2]));
        }
        $this->assertSame(0, Server::get(IThrottler::class)->getAttempts($this->remoteAddress, IcsService::FEED_THROTTLE_ACTION));
        $this->assertSame(200, $this->fetch($legacy, $legacyMethod, $this->validToken));
        [$feed, $feedMethod] = $this->route('feed');
        $this->assertSame(200, $this->fetch($feed, $feedMethod, $this->validToken));
    }
}
