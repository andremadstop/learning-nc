<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Regression lock for Codeberg issue #2 ("Could not resolve OCA\Learning\Controller\AiController").
 *
 * Nextcloud derives class and method names from STRINGS in two places, and the PSR-4 autoloader
 * then resolves them against the filesystem CASE-SENSITIVELY:
 *
 *   1. routes.php  — `'name' => 'ai#available'` becomes `OCA\Learning\Controller\AiController`
 *                    plus method `available()`, via RouteConfig::buildControllerName()
 *                    (= underScoreToCamelCase(ucfirst($c)) . 'Controller') and buildActionName().
 *                    The file was called `AIController.php`, so every `ai#*` route threw a
 *                    QueryNotFoundException (HTTP 500) on every request — for five months and
 *                    23 tagged releases, from v2.7.0 until the fix.
 *   2. info.xml    — <command>, <job>, <admin>, <personal>, <provider>, <setting> hold FQCNs.
 *
 * Nothing else covers this. PHPStan sees no defect (the class is well-formed), the call graph
 * reports the controller as unreferenced (nothing calls it in PHP — the router does, by string),
 * and the controllers' own unit tests `use` the real class name, so they load and pass while
 * every HTTP request fails. The API test hid it too: it accepted "200 OR 500".
 *
 * The route list is read by REQUIRING routes.php and walking the returned array, not by matching
 * source text — an earlier regex version of this test silently skipped the one route written with
 * double quotes, which is exactly the kind of gap that makes a guard worse than useless.
 *
 * @group regression-class-resolution
 */
class ClassResolutionTest extends TestCase {

    private const APP_ROOT = __DIR__ . '/../../..';
    private const CONTROLLER_NS = 'OCA\\Learning\\Controller\\';

    /**
     * Mirrors OC\AppFramework\Routing\RouteConfig::buildControllerName().
     */
    private function buildControllerName(string $controller): string {
        return $this->underScoreToCamelCase(ucfirst($controller)) . 'Controller';
    }

    /**
     * Mirrors OC\AppFramework\Routing\RouteConfig::buildActionName().
     */
    private function buildActionName(string $action): string {
        return $this->underScoreToCamelCase($action);
    }

    private function underScoreToCamelCase(string $value): string {
        return preg_replace_callback('/_(.)/', static fn (array $m): string => strtoupper($m[1]), $value);
    }

    /**
     * Case-sensitive existence check — is_file() alone passes on case-insensitive filesystems,
     * so it would go green on a Mac while production on ext4 stays broken.
     */
    private function fileExistsCaseSensitive(string $path): bool {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            return false;
        }
        return in_array(basename($path), scandir($dir) ?: [], true);
    }

    /**
     * Every route, as the router will read it: the declared name plus the class and method it
     * resolves to.
     *
     * @return array<int, array{name: string, class: string, action: string, path: string}>
     */
    private function routeTargets(): array {
        $routes = require self::APP_ROOT . '/appinfo/routes.php';
        $targets = [];
        foreach ($routes['routes'] as $index => $route) {
            // No silent skipping: a route without a name, or with a name this test cannot split,
            // is a route nobody is checking. Fail instead of quietly shrinking the coverage.
            if (!isset($route['name']) || substr_count($route['name'], '#') !== 1) {
                throw new \RuntimeException(sprintf(
                    'routes.php entry #%d (%s) has no parseable "controller#action" name — extend '
                        . 'this test rather than leaving that route unchecked.',
                    $index,
                    isset($route['name']) ? $route['name'] : json_encode($route)
                ));
            }
            [$controller, $action] = explode('#', $route['name'], 2);
            $class = $this->buildControllerName($controller);
            $targets[] = [
                'name' => $route['name'],
                'class' => $class,
                'action' => $this->buildActionName($action),
                'path' => self::APP_ROOT . '/lib/Controller/' . $class . '.php',
            ];
        }
        return $targets;
    }

    /**
     * routes.php currently declares only the plain 'routes' list, and this test understands exactly
     * that shape. Nextcloud also accepts 'resources' (which derives controller names from the
     * resource key) and 'ocs' — both would silently escape the checks below, as would routes
     * declared with #[FrontpageRoute]/#[ApiRoute] attributes, which do not appear in this file at
     * all. Fail loudly when a new key shows up so the next person extends this test instead of
     * quietly losing the coverage.
     */
    public function testRoutesFileOnlyUsesShapesThisTestUnderstands(): void {
        $routes = require self::APP_ROOT . '/appinfo/routes.php';
        $this->assertIsArray($routes);
        $unknown = array_values(array_diff(array_keys($routes), ['routes']));
        $this->assertSame(
            [],
            $unknown,
            'routes.php gained key(s) [' . implode(', ', $unknown) . '] that this test does not inspect. '
                . 'Extend routeTargets() to cover them — otherwise controllers reachable through them '
                . 'are unprotected against the case mismatch this test exists to prevent.'
        );
    }

    public function testEveryRouteResolvesToAnExistingControllerFile(): void {
        $targets = $this->routeTargets();
        $this->assertGreaterThan(200, count($targets), 'sanity: routes.php should declare many routes');

        $missing = [];
        foreach ($targets as $t) {
            if (!$this->fileExistsCaseSensitive($t['path'])) {
                $missing[$t['class']] = sprintf("route '%s' needs lib/Controller/%s.php", $t['name'], $t['class']);
            }
        }

        $this->assertSame([], array_values($missing), "Routes point at controller files that do not exist (exact case):\n" . implode("\n", $missing));
    }

    public function testEveryRouteControllerDeclaresTheDerivedClassName(): void {
        $wrong = [];
        foreach ($this->routeTargets() as $t) {
            if (!$this->fileExistsCaseSensitive($t['path'])) {
                continue; // reported by the test above
            }
            $source = file_get_contents($t['path']) ?: '';
            if (!preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+' . preg_quote($t['class'], '/') . '\b/m', $source)) {
                $wrong[$t['class']] = sprintf('%s.php must declare "class %s"', $t['class'], $t['class']);
            }
        }

        $this->assertSame([], array_values($wrong), "Controller files declare a class name the router cannot resolve:\n" . implode("\n", $wrong));
    }

    /**
     * A typo in the action half ('ai#availble') fails the same way at runtime, and the checks above
     * would not see it — the controller file exists either way.
     */
    public function testEveryRouteResolvesToAnExistingControllerMethod(): void {
        $missing = [];
        $checked = 0;
        $unloadable = [];
        foreach ($this->routeTargets() as $t) {
            $fqcn = self::CONTROLLER_NS . $t['class'];
            if (!class_exists($fqcn)) {
                // Recorded, not skipped: the file may exist under the right name while the class
                // inside it sits in the wrong namespace, and the per-file checks above cannot see
                // that. Skipping here would let the global count guard stay green.
                $unloadable[$fqcn] = sprintf("route '%s': %s is not autoloadable", $t['name'], $fqcn);
                continue;
            }
            $checked++;
            $reflection = new ReflectionClass($fqcn);
            if (!$reflection->hasMethod($t['action'])) {
                $missing[] = sprintf("route '%s' needs %s::%s()", $t['name'], $t['class'], $t['action']);
                continue;
            }
            // The dispatcher can only call public methods; a private one resolves in reflection
            // but fails at runtime.
            if (!$reflection->getMethod($t['action'])->isPublic()) {
                $missing[] = sprintf("route '%s': %s::%s() must be public", $t['name'], $t['class'], $t['action']);
            }
        }

        $this->assertSame([], array_values($unloadable), "Route controllers that do not autoload:\n" . implode("\n", $unloadable));
        // Without this, a bootstrap that cannot autoload OCA\Learning would skip every route and
        // still report green — the same "failure looks like success" shape as the bug this file
        // guards against.
        $this->assertGreaterThan(200, $checked, 'this test inspected almost nothing — autoloading is broken, not the routes');
        $this->assertSame([], $missing, "Routes point at controller methods that do not exist:\n" . implode("\n", $missing));
    }

    public function testEveryInfoXmlClassResolvesToAnExistingFileAndDeclaration(): void {
        $xml = simplexml_load_file(self::APP_ROOT . '/appinfo/info.xml');
        $this->assertNotFalse($xml, 'appinfo/info.xml must parse');

        $classes = [];
        foreach ($xml->xpath('//*[starts-with(normalize-space(text()), "OCA\\Learning\\")]') ?: [] as $node) {
            $classes[] = trim((string)$node);
        }
        $classes = array_values(array_unique($classes));
        // Not assertNotEmpty: an xpath that silently stops matching (namespace change, reformatted
        // info.xml) would leave one class behind and still pass. Same "green means nothing was
        // inspected" trap the route-method test guards against.
        $this->assertGreaterThanOrEqual(
            16,
            count($classes),
            'info.xml declares fewer FQCNs than expected — the xpath probably stopped matching rather than the app shrinking'
        );

        $problems = [];
        foreach ($classes as $class) {
            $relative = str_replace('\\', '/', substr($class, strlen('OCA\\Learning\\')));
            $path = self::APP_ROOT . '/lib/' . $relative . '.php';
            if (!$this->fileExistsCaseSensitive($path)) {
                $problems[] = sprintf('%s needs lib/%s.php', $class, $relative);
                continue;
            }
            $shortName = basename($relative);
            $source = file_get_contents($path) ?: '';
            if (!preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+' . preg_quote($shortName, '/') . '\b/m', $source)) {
                $problems[] = sprintf('lib/%s.php must declare "class %s"', $relative, $shortName);
                continue;
            }
            // File and class name can both be right while the namespace is not — Nextcloud
            // resolves the FQCN, so the namespace has to match the path.
            //
            // Checked by reading the declaration, NOT with class_exists(): loading these classes
            // pulls in server interfaces (OCP\Settings\ISettings and friends) that the unit-test
            // stub set does not ship, so class_exists() raises a fatal error instead of returning
            // false — an environment gap, not a defect in the app.
            $expectedNamespace = 'OCA\\Learning' . (str_contains($relative, '/') ? '\\' . str_replace('/', '\\', dirname($relative)) : '');
            if (!preg_match('/^\s*namespace\s+' . preg_quote($expectedNamespace, '/') . '\s*;/m', $source)) {
                $problems[] = sprintf('lib/%s.php must declare "namespace %s;" for %s to resolve', $relative, $expectedNamespace, $class);
            }
        }

        $this->assertSame([], $problems, "info.xml declares classes that do not resolve (exact case):\n" . implode("\n", $problems));
    }
}
