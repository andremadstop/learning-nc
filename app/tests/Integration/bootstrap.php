<?php
/**
 * Integration bootstrap: boots the real Nextcloud server the app is installed in.
 * No OCP stubs — run only via scripts/nc-integration.sh (app at /var/www/html/apps/learning).
 */
declare(strict_types=1);

require_once '/var/www/html/lib/base.php';

// Guard: OCP must come from the server, never from stubs (vendor/nextcloud/ocp, tests/Support).
$ocpFile = (new ReflectionClass(\OCP\IDBConnection::class))->getFileName();
if (!is_string($ocpFile) || !str_starts_with($ocpFile, '/var/www/html/lib/public/')) {
	throw new RuntimeException('Integration bootstrap: OCP is not the real server API (' . var_export($ocpFile, true) . ')');
}

// Register the app's autoloader and boot it the way a request would.
$appManager = \OCP\Server::get(\OCP\App\IAppManager::class);
if ($appManager->isEnabledForAnyone('learning')) {
	$appManager->loadApp('learning');
}

require_once __DIR__ . '/Support/MockLlmServer.php';

// The run script creates the mock's request log; abort now if it is missing or unreadable.
\OCA\Learning\Tests\Integration\Support\MockLlmServer::requests();
