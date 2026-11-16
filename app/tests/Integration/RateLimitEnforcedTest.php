<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OC\AppFramework\Middleware\Security\RateLimitingMiddleware;
use OC\AppFramework\Utility\ControllerMethodReflector;
use OC\Security\Ip\BruteforceAllowList;
use OC\Security\RateLimiting\Exception\RateLimitExceededException;
use OC\Security\RateLimiting\Limiter;
use OCA\Learning\Controller\DataExportController;
use OCA\Learning\Controller\PublicVerifyController;
use OCA\Learning\Tests\Integration\Support\ContainerObjects;
use OCP\AppFramework\App;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The app's rate limits against Nextcloud's own RateLimitingMiddleware and Limiter (database
 * backend, real server classes, no stubs): a per-user limit answers 429 once it is used up, the
 * public verification page is limited per address, and the polling/heartbeat endpoints the app
 * repeats by itself never run into a limit. Also checks that every attribute on every controller
 * method names a class the server actually has.
 */
final class RateLimitEnforcedTest extends TestCase {
    use ContainerObjects;

    private const UID = 'ratelimit-probe-user';

    private ContainerInterface $container;
    private IUserSession $userSession;
    private ?IUser $user = null;
    private string $remoteAddress = '203.0.113.10';

    protected function setUp(): void {
        $this->container = (new App('learning'))->getContainer();
        $this->userSession = Server::get(IUserSession::class);
        $this->clearLimiter();
        $users = Server::get(IUserManager::class);
        $users->get(self::UID)?->delete();
        $this->user = $users->createUser(self::UID, bin2hex(random_bytes(16)) . 'Aa1!') ?: null;
        $this->assertNotNull($this->user);
    }

    protected function tearDown(): void {
        $this->userSession->setUser(null);
        $this->user?->delete();
        $this->clearLimiter();
    }

    private function clearLimiter(): void {
        $qb = Server::get(IDBConnection::class)->getQueryBuilder();
        $qb->delete('ratelimit_entries')->executeStatement();
    }

    private function middleware(ControllerMethodReflector $reflector): RateLimitingMiddleware {
        $request = $this->createMock(IRequest::class);
        $request->method('getRemoteAddress')->willReturnCallback(fn (): string => $this->remoteAddress);
        $request->method('getHeader')->willReturnCallback(
            static fn (string $name): string => strtolower($name) === 'accept' ? 'application/json' : '');
        return new RateLimitingMiddleware(
            $request,
            $this->userSession,
            $reflector,
            Server::get(Limiter::class),
            Server::get(ISession::class),
            Server::get(IAppConfig::class),
            Server::get(IConfig::class),
            Server::get(BruteforceAllowList::class),
            Server::get(LoggerInterface::class),
        );
    }

    /**
     * Calls the middleware $times times for $method and returns how many calls passed and the
     * status of the first refused one (0 if none was refused).
     *
     * @return array{passed: int, status: int}
     */
    private function hit(Controller $controller, string $method, int $times): array {
        $reflector = new ControllerMethodReflector(Server::get(LoggerInterface::class));
        $reflector->reflect($controller, $method);
        $mw = $this->middleware($reflector);
        $passed = 0;
        for ($i = 0; $i < $times; $i++) {
            try {
                $mw->beforeController($controller, $method);
                $passed++;
            } catch (RateLimitExceededException $e) {
                $response = $mw->afterException($controller, $method, $e);
                $this->assertInstanceOf(DataResponse::class, $response);
                return ['passed' => $passed, 'status' => $response->getStatus()];
            }
        }
        return ['passed' => $passed, 'status' => 0];
    }

    /** @param class-string $class */
    private function controller(string $class): Controller {
        $c = $this->controllerFor($this->container, $class, self::UID);
        $this->assertInstanceOf(Controller::class, $c);
        return $c;
    }

    public function testAUserLimitAnswers429OnceItIsUsedUp(): void {
        $this->userSession->setUser($this->user);
        // DataExportController::myData: 3 per minute and user (tests/Support/rate-limits.json).
        $result = $this->hit($this->controller(DataExportController::class), 'myData', 10);
        $this->assertSame(['passed' => 3, 'status' => 429], $result);
    }

    public function testThePublicVerifyPageIsLimitedPerAddress(): void {
        $this->userSession->setUser(null);
        $controller = $this->controller(PublicVerifyController::class);
        $result = $this->hit($controller, 'verify', 200);
        $this->assertSame(['passed' => 150, 'status' => 429], $result);

        // Another address has its own budget.
        $this->remoteAddress = '203.0.113.11';
        $this->assertSame(['passed' => 5, 'status' => 0], $this->hit($controller, 'verify', 5));
    }

    public function testPollingAndHeartbeatEndpointsAreNeverLimited(): void {
        $this->userSession->setUser($this->user);
        $raw = file_get_contents(__DIR__ . '/../Support/rate-limits.json');
        $this->assertIsString($raw);
        $inventory = json_decode($raw, true, 512, JSON_THROW_ON_ERROR)['methods'];
        $live = array_keys(array_filter($inventory, static fn (array $e): bool => $e['group'] === 'live'));
        $this->assertContains('VideoProgressController::heartbeat', $live);
        foreach ($live as $key) {
            [$short, $method] = explode('::', $key);
            /** @var class-string $class */
            $class = 'OCA\\Learning\\Controller\\' . $short;
            // Two state polls a second for a minute (the duel/gameshow fallback) plus reserve.
            $this->assertSame(['passed' => 150, 'status' => 0],
                $this->hit($this->controller($class), $method, 150), $key);
        }
    }

    public function testEveryControllerAttributeIsAClassOfThisServer(): void {
        $checked = 0;
        foreach (glob('/var/www/html/apps/learning/lib/Controller/*.php') ?: [] as $file) {
            $class = 'OCA\\Learning\\Controller\\' . basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }
            foreach ((new \ReflectionClass($class))->getMethods() as $m) {
                foreach ($m->getAttributes() as $attr) {
                    $this->assertTrue(class_exists($attr->getName()), $class . '::' . $m->getName() . ': ' . $attr->getName());
                    $checked++;
                }
            }
        }
        $this->assertGreaterThan(200, $checked);
    }
}
