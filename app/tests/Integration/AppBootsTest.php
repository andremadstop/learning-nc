<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/** Smoke test: the app is installed and enabled in a real Nextcloud and its DI container boots. */
final class AppBootsTest extends TestCase {
	public function testAppEnabled(): void {
		$appManager = Server::get(IAppManager::class);

		$this->assertTrue($appManager->isEnabledForAnyone(Application::APP_ID), 'learning is not enabled');

		// simplexml_load_file() is blocked by Nextcloud's external entity loader; read the file first.
		$info = simplexml_load_string((string)file_get_contents(__DIR__ . '/../../appinfo/info.xml'));
		$this->assertNotFalse($info);
		$this->assertSame((string)$info->version, $appManager->getAppVersion(Application::APP_ID, false));

		$this->assertInstanceOf(Application::class, Server::get(Application::class));
	}
}
