<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\ARateLimit;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use PHPUnit\Framework\TestCase;

/**
 * Nextcloud's RateLimitingMiddleware finds a limit only through
 * ReflectionMethod::getAttributes(OCP\AppFramework\Http\Attribute\UserRateLimit|AnonRateLimit::class,
 * IS_INSTANCEOF). An attribute under any other name is silently ignored, so this test reads the
 * controllers the same way and holds them against the reviewed inventory in
 * tests/Support/rate-limits.json (both directions, limit and period included).
 */
final class RateLimitInventoryTest extends TestCase {
    private const ATTRIBUTE_NS = 'OCP\\AppFramework\\Http\\Attribute\\';

    /** @return array{groups: array<string, string>, methods: array<string, array{kind: string, limit: ?int, period: int, group: string}>} */
    private static function inventory(): array {
        $raw = file_get_contents(__DIR__ . '/../../Support/rate-limits.json');
        self::assertIsString($raw);
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return $data;
    }

    /** @return array<string, \ReflectionMethod> "FooController::bar" => method */
    private static function controllerMethods(): array {
        $methods = [];
        foreach (glob(__DIR__ . '/../../../lib/Controller/*.php') ?: [] as $file) {
            $class = 'OCA\\Learning\\Controller\\' . basename($file, '.php');
            if (!class_exists($class)) {
                continue; // traits/helpers without a class of that name
            }
            $ref = new \ReflectionClass($class);
            if (!$ref->isSubclassOf(\OCP\AppFramework\Controller::class) || $ref->isAbstract()) {
                continue;
            }
            foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
                if ($m->getDeclaringClass()->getName() === $class && !$m->isConstructor()) {
                    $methods[$ref->getShortName() . '::' . $m->getName()] = $m;
                }
            }
        }
        self::assertGreaterThan(40, count($methods), 'controller scan found too few methods');
        return $methods;
    }

    public function testEveryRateLimitAttributeIsOneTheMiddlewareReads(): void {
        foreach (self::controllerMethods() as $key => $m) {
            foreach ($m->getAttributes() as $attr) {
                $name = $attr->getName();
                $this->assertStringNotContainsString('\\Http\\Attributes\\', $name,
                    "$key: Nextcloud has no Http\\Attributes namespace, $name is never read");
                if (str_ends_with($name, 'RateLimit')) {
                    $this->assertStringStartsWith(self::ATTRIBUTE_NS, $name, $key);
                    $this->assertTrue(class_exists($name), "$key: $name does not exist");
                    $this->assertTrue(is_subclass_of($name, ARateLimit::class), "$key: $name is no ARateLimit");
                }
            }
        }
    }

    public function testAttributesMatchTheReviewedInventory(): void {
        $inventory = self::inventory()['methods'];
        $methods = self::controllerMethods();
        $found = [];
        foreach ($methods as $key => $m) {
            foreach ($m->getAttributes(ARateLimit::class, \ReflectionAttribute::IS_INSTANCEOF) as $attr) {
                $limit = $attr->newInstance();
                $this->assertInstanceOf(ARateLimit::class, $limit);
                $kind = $limit instanceof UserRateLimit ? 'user' : ($limit instanceof AnonRateLimit ? 'anon' : '?');
                $found[$key] = ['kind' => $kind, 'limit' => $limit->getLimit(), 'period' => $limit->getPeriod()];
            }
        }
        $this->assertNotEmpty($found, 'no rate limit is visible to the middleware');

        foreach ($found as $key => $actual) {
            $this->assertArrayHasKey($key, $inventory, "$key has a rate limit that is not in rate-limits.json");
            $expected = $inventory[$key];
            $this->assertSame(
                ['kind' => $expected['kind'], 'limit' => $expected['limit'], 'period' => $expected['period']],
                $actual, "$key differs from rate-limits.json");
        }
        foreach ($inventory as $key => $expected) {
            $this->assertArrayHasKey($key, $methods, "rate-limits.json lists $key, which is no controller method");
            if ($expected['limit'] === null) {
                $this->assertArrayNotHasKey($key, $found, "$key is meant to stay unlimited");
            } else {
                $this->assertArrayHasKey($key, $found, "$key lost its rate limit");
            }
        }
    }

    public function testEveryInventoryEntryNamesAKnownGroup(): void {
        $data = self::inventory();
        foreach ($data['methods'] as $key => $entry) {
            $this->assertArrayHasKey($entry['group'], $data['groups'], $key);
            $this->assertContains($entry['kind'], ['user', 'anon'], $key);
            $this->assertGreaterThan(0, $entry['period'], $key);
            if ($entry['limit'] !== null) {
                $this->assertGreaterThan(0, $entry['limit'], $key);
            }
        }
    }

    /**
     * An SSE stream keeps its PHP worker for up to SseController::STREAM_TIMEOUT_SECONDS
     * (set_time_limit(0), ignore_user_abort), so the per-account limit is the only thing that
     * caps how many workers one account binds at once: limit × ceil(timeout / period). At 120/min
     * that was about 240 — more than a typical php-fpm pool, one learner could stall the server.
     */
    public function testStreamLimitsBoundTheWorkersOneAccountCanHold(): void {
        $maxConcurrentStreamsPerAccount = 20;
        $timeout = (new \ReflectionClassConstant(\OCA\Learning\Controller\SseController::class, 'STREAM_TIMEOUT_SECONDS'))->getValue();
        $this->assertIsInt($timeout);
        $this->assertGreaterThan(0, $timeout);

        $streams = 0;
        foreach (self::inventory()['methods'] as $key => $entry) {
            if ($entry['group'] !== 'stream') {
                continue;
            }
            $streams++;
            $this->assertStringStartsWith('SseController::', $key, "$key: only SseController holds streams");
            $this->assertSame('user', $entry['kind'], $key);
            $this->assertNotNull($entry['limit'], "$key: a stream must never be unlimited");
            $concurrent = $entry['limit'] * (int)ceil($timeout / $entry['period']);
            $this->assertLessThanOrEqual($maxConcurrentStreamsPerAccount, $concurrent,
                "$key lets one account hold $concurrent PHP workers in open streams");
        }
        $this->assertSame(2, $streams, 'duelStream and gameshowStream are the stream group');
    }

    public function testNoSourceFileImportsThePluralAttributeNamespace(): void {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../../lib', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $src = (string)file_get_contents($file->getPathname());
            $this->assertStringNotContainsString('OCP\\AppFramework\\Http\\Attributes\\', $src, $file->getPathname());
        }
    }
}
