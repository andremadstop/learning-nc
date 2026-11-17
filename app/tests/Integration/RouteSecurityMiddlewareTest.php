<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OC\AppFramework\Middleware\Security\Exceptions\CrossSiteRequestForgeryException;
use OC\AppFramework\Middleware\Security\Exceptions\NotAdminException;
use OC\AppFramework\Middleware\Security\Exceptions\NotLoggedInException;
use OC\AppFramework\Middleware\Security\SecurityMiddleware;
use OC\AppFramework\Utility\ControllerMethodReflector;
use OC\Settings\AuthorizedGroupMapper;
use OCP\App\IAppManager;
use OCP\Group\ISubAdmin;
use OCP\IGroupManager;
use OCP\INavigationManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Security\Ip\IRemoteAddress;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * GitHub #65: the routes moved from PHPDoc annotations to PHP attributes. This runs every controller
 * method of the app through Nextcloud's own SecurityMiddleware (real server class, real
 * ControllerMethodReflector) twice, and holds the outcome against tests/Support/route-security.json,
 * the inventory taken from the annotation-era code:
 *  - a visitor without a session: only the public pages pass, everything else asks for a login;
 *  - a signed-in non-admin without a request token: admin-only routes refuse, routes that check the
 *    token refuse with the CSRF error, the rest (navigation downloads, public pages) pass.
 * The unit test RouteSecurityInventoryTest checks the same inventory by reflection; this one shows
 * that the server reads the attributes the same way.
 */
final class RouteSecurityMiddlewareTest extends TestCase {
    private ControllerMethodReflector $reflector;

    /** @return array<string, array<string, mixed>> */
    private static function inventory(): array {
        $raw = file_get_contents(__DIR__ . '/../Support/route-security.json');
        self::assertIsString($raw);
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data['methods']);
        return $data['methods'];
    }

    private function middleware(bool $loggedIn): SecurityMiddleware {
        $request = $this->createMock(IRequest::class);
        $request->method('passesCSRFCheck')->willReturn(false);   // no request token sent
        $request->method('passesStrictCookieCheck')->willReturn(true);
        $request->method('getHeader')->willReturn('');

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('route-security-probe');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($loggedIn ? $user : null);
        $session->method('isLoggedIn')->willReturn($loggedIn);

        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isAdmin')->willReturn(false);
        $subAdmin = $this->createMock(ISubAdmin::class);
        $subAdmin->method('isSubAdmin')->willReturn(false);
        $apps = $this->createMock(IAppManager::class);
        $apps->method('getAppPath')->willReturn('/var/www/html/apps/learning');
        $apps->method('isEnabledForUser')->willReturn(true);
        $authorized = $this->createMock(AuthorizedGroupMapper::class);
        $authorized->method('findAllClassesForUser')->willReturn([]);
        $remote = $this->createMock(IRemoteAddress::class);
        $remote->method('allowsAdminActions')->willReturn(true);

        return new SecurityMiddleware(
            $request,
            $this->reflector,
            $this->createMock(INavigationManager::class),
            $this->createMock(IURLGenerator::class),
            new NullLogger(),
            'learning',
            $loggedIn,
            $groups,
            $subAdmin,
            $apps,
            Server::get(IFactory::class)->get('lib'),
            $authorized,
            $session,
            $remote,
        );
    }


    /** Short class name of the exception beforeController() threw, or 'pass'. */
    private function outcome(bool $loggedIn, string $key): string {
        [$short, $method] = explode('::', $key);
        $class = 'OCA\\Learning\\Controller\\' . $short;
        // The middleware only reflects on the controller; no constructor, no dependencies needed.
        $controller = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $this->reflector = new ControllerMethodReflector(new NullLogger());
        $this->reflector->reflect($controller, $method);
        try {
            $this->middleware($loggedIn)->beforeController($controller, $method);
            return 'pass';
        } catch (NotLoggedInException|NotAdminException|CrossSiteRequestForgeryException $e) {
            return (new \ReflectionClass($e))->getShortName();
        }
    }

    public function testEveryMethodMeetsTheMiddlewareAsBeforeTheMoveToAttributes(): void {
        $wrong = [];
        $count = ['anon-pass' => 0, 'csrf' => 0, 'admin' => 0, 'user-pass' => 0];
        foreach (self::inventory() as $key => $entry) {
            $public = isset($entry['PublicPage']);
            $noCsrf = isset($entry['NoCSRFRequired']);
            $noAdmin = isset($entry['NoAdminRequired']);

            $anon = $public ? 'pass' : 'NotLoggedInException';
            $user = match (true) {
                !$public && !$noAdmin => 'NotAdminException',
                !$noCsrf => 'CrossSiteRequestForgeryException',
                default => 'pass',
            };
            $count['anon-pass'] += $anon === 'pass' ? 1 : 0;
            $count['admin'] += $user === 'NotAdminException' ? 1 : 0;
            $count['csrf'] += $user === 'CrossSiteRequestForgeryException' ? 1 : 0;
            $count['user-pass'] += $user === 'pass' ? 1 : 0;

            foreach (['visitor' => [false, $anon], 'non-admin without token' => [true, $user]] as $who => [$loggedIn, $expected]) {
                $actual = $this->outcome($loggedIn, $key);
                if ($actual !== $expected) {
                    $wrong[] = "$key, $who: expected $expected, got $actual";
                }
            }
        }
        $this->assertSame([], $wrong);
        // The inventory is not trivially uniform: each outcome occurs.
        $this->assertSame(6, $count['anon-pass']);
        $this->assertGreaterThan(0, $count['admin']);
        $this->assertGreaterThan(200, $count['csrf']);
        $this->assertGreaterThan(20, $count['user-pass']);
    }
}
