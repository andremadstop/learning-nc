<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration\Support;

use OCP\IRequest;
use Psr\Container\ContainerInterface;

/**
 * Builds app objects as a request would get them: every constructor dependency from the app
 * container, except the ones a test replaces (by parameter type) to step into the middle of a run.
 */
trait ContainerObjects {
    /**
     * @param class-string $class
     * @param array<string, mixed> $overrides parameter type (class name) => object to inject
     * @return list<mixed>
     */
    private static function constructorArgs(ContainerInterface $container, string $class, ?string $uid, array $overrides = []): array {
        $args = [];
        foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $p) {
            $type = $p->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : '';
            $args[] = match (true) {
                array_key_exists($typeName, $overrides) => $overrides[$typeName],
                $p->getName() === 'appName' => 'learning',
                $p->getName() === 'userId' || $p->getName() === 'UserId' => $uid,
                default => $container->get($typeName),
            };
        }
        return $args;
    }

    /**
     * A controller for $uid with a request that answers getParam() from $params.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, mixed> $overrides
     * @param array<string, mixed> $params
     * @return T
     */
    private function controllerFor(ContainerInterface $container, string $class, ?string $uid, array $overrides = [], array $params = []): object {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default);
        return new $class(...self::constructorArgs($container, $class, $uid, $overrides + [IRequest::class => $request]));
    }
}
