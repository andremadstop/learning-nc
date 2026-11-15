<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Service\LeagueService;
use OCP\IConfig;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #70: LeagueService takes the gamification switch through an optional XpService. The unit
 * test passes it by hand; this proves the real container injects it, so the league reads follow the
 * switch on a real Nextcloud and do not silently fall back to "on".
 */
final class LeagueGamificationOff70Test extends TestCase {
	public function testContainerInjectsTheGamificationSwitch(): void {
		$config = Server::get(IConfig::class);
		$before = $config->getAppValue('learning', 'gamification_enabled', 'yes');
		try {
			$service = Server::get(LeagueService::class);
			$off = new \ReflectionMethod($service, 'gamificationOff');

			$config->setAppValue('learning', 'gamification_enabled', 'no');
			$this->assertTrue($off->invoke($service), 'the container left out the XpService');

			$config->setAppValue('learning', 'gamification_enabled', 'yes');
			$this->assertFalse($off->invoke($service));
		} finally {
			$config->setAppValue('learning', 'gamification_enabled', $before);
		}
	}
}
