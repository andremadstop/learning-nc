<?php
declare(strict_types=1);

require_once __DIR__ . '/Support/PhpUnitStubs.php';
require_once __DIR__ . '/Support/FakeInfrastructure.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'OCA\\Learning\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = dirname(__DIR__) . '/lib/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});
