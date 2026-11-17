<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Tests\Support\RouteSecurityInventory;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #65: the routes declared @PublicPage, @NoCSRFRequired, @NoAdminRequired and
 * @BruteForceProtection as PHPDoc annotations, which Nextcloud reads only as a deprecated fallback
 * (ControllerMethodReflector logs "should use the #[…] attribute instead"). They are PHP attributes
 * now. Two things can go wrong in such a move, and both are silent at runtime:
 *  - a route loses or gains a marker (a public page that asks for a login, a download that answers
 *    412 for the missing CSRF token, an admin route that anyone may call);
 *  - an attribute is written under a name Nextcloud never reads (missing `use` import → it
 *    resolves to OCA\Learning\Controller\NoAdminRequired and is ignored).
 * tests/Support/route-security.json is the inventory of the annotation-era code; this test holds
 * the current code against it in both directions.
 */
final class RouteSecurityInventoryTest extends TestCase {
    private const CONTROLLER_DIR = __DIR__ . '/../../../lib/Controller';
    private const ATTRIBUTE_NS = 'OCP\\AppFramework\\Http\\Attribute\\';
    /** Nextcloud 33, lib/public/AppFramework/Http/Attribute. */
    private const NEXTCLOUD_ATTRIBUTES = [
        'AnonRateLimit', 'ApiRoute', 'AppApiAdminAccessWithoutUser', 'AuthorizedAdminSetting',
        'BruteForceProtection', 'CORS', 'ExAppRequired', 'FrontpageRoute', 'IgnoreOpenAPI',
        'NoAdminRequired', 'NoCSRFRequired', 'OpenAPI', 'PasswordConfirmationRequired', 'PublicPage',
        'RequestHeader', 'Route', 'StrictCookiesRequired', 'SubAdminRequired', 'UserRateLimit',
        'UseSession',
    ];

    /** @return array<string, array<string, mixed>> */
    private static function reviewed(): array {
        $raw = file_get_contents(__DIR__ . '/../../Support/route-security.json');
        self::assertIsString($raw);
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['methods']);
        return $data['methods'];
    }

    public function testNoControllerMethodDeclaresAnythingThroughAPhpDocAnnotation(): void {
        $methods = RouteSecurityInventory::controllerMethods(self::CONTROLLER_DIR);
        $this->assertGreaterThan(300, count($methods), 'controller scan found too few methods');
        $found = [];
        foreach ($methods as $key => $m) {
            // Every "* @Name" line the reflector extracts, prose after the name included: such a line
            // either acts as a deprecated annotation or only looks like one (@AdminRequired never did).
            foreach (RouteSecurityInventory::annotations($m) as $name => $rest) {
                $found[] = "$key: @$name$rest";
            }
        }
        $this->assertSame([], $found, 'use the #[…] attribute from OCP\\AppFramework\\Http\\Attribute instead');
    }

    public function testEveryRouteKeepsItsPublicCsrfAdminAndThrottleBehaviour(): void {
        $reviewed = self::reviewed();
        $current = RouteSecurityInventory::scan(self::CONTROLLER_DIR);

        $this->assertSame([], array_values(array_diff(array_keys($reviewed), array_keys($current))),
            'methods in route-security.json that no controller has any more');
        $this->assertSame([], array_values(array_diff(array_keys($current), array_keys($reviewed))),
            'controller methods missing from route-security.json');
        foreach ($reviewed as $key => $expected) {
            $this->assertSame($expected, $current[$key], "$key differs from route-security.json");
        }
    }

    public function testThePublicSurfaceIsExactlyTheseSixPages(): void {
        $public = [];
        foreach (self::reviewed() as $key => $entry) {
            if (isset($entry['PublicPage'])) {
                $public[] = $key;
                // A public page is reached without a session, so it cannot carry a request token.
                $this->assertTrue($entry['NoCSRFRequired'] ?? false, "$key is public but checks CSRF");
            }
        }
        $this->assertSame([
            'DidController::index',
            'ExportController::exportIcsPublic',
            'IcsController::feed',
            'PageController::impressum',
            'PageController::privacy',
            'PublicVerifyController::verify',
        ], $public);
    }

    public function testEveryAttributeOnAControllerIsOneNextcloudCanRead(): void {
        $checked = 0;
        foreach (glob(self::CONTROLLER_DIR . '/*.php') ?: [] as $file) {
            $class = 'OCA\\Learning\\Controller\\' . basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }
            $ref = new \ReflectionClass($class);
            foreach ([$ref, ...$ref->getMethods()] as $target) {
                $where = $target instanceof \ReflectionMethod ? $class . '::' . $target->getName() : $class;
                foreach ($target->getAttributes() as $attr) {
                    // An attribute written without its `use` import resolves to the controller's own namespace.
                    $this->assertStringStartsWith(self::ATTRIBUTE_NS, $attr->getName(),
                        "$where: #[{$attr->getName()}] is no Nextcloud attribute (missing use import?), Nextcloud ignores it");
                    // Unit tests run on OCP stubs, so class_exists() cannot vouch for the name; it is
                    // held against Nextcloud 33's lib/public/AppFramework/Http/Attribute instead, and
                    // RateLimitEnforcedTest checks class_exists() on a real server.
                    $this->assertContains(substr($attr->getName(), strlen(self::ATTRIBUTE_NS)), self::NEXTCLOUD_ATTRIBUTES,
                        "$where: #[{$attr->getName()}] is no attribute of Nextcloud 33");
                    $checked++;
                }
            }
        }
        $this->assertGreaterThan(500, $checked);
    }

    public function testTheScanSeesBothFormsAndTheAnnotationWinsForBruteForce(): void {
        $probe = new class {
            /**
             * Text before.
             *
             * @NoAdminRequired
             * @BruteForceProtection(action=fromDoc)
             */
            #[\OCP\AppFramework\Http\Attribute\NoCSRFRequired]
            #[\OCP\AppFramework\Http\Attribute\BruteForceProtection(action: 'fromAttribute')]
            public function both(): void {
            }

            /**
             * @PublicPage is prose here, but the reflector reads it as the annotation all the same.
             */
            #[NoAdminRequired] // unimported: resolves to a class that does not exist
            public function prose(): void {
            }
        };
        $this->assertSame([
            'BruteForceProtection' => ['fromDoc'],
            'NoAdminRequired' => true,
            'NoCSRFRequired' => true,
        ], RouteSecurityInventory::describe(new \ReflectionMethod($probe, 'both')));
        $this->assertSame(['PublicPage' => true],
            RouteSecurityInventory::describe(new \ReflectionMethod($probe, 'prose')));
    }
}
