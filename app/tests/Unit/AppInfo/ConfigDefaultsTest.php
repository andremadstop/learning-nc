<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\AppInfo;

use OCA\Learning\AppInfo\ConfigDefaults;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * 164-07 post-impl review locks (HIGH 4 + MED 5): the SHARED config parsers.
 *
 * HIGH 4 — a negative recert_grace_days would flip the close-cutoff into the future
 * (closeExpiredPeriods closes still-valid certs) and make deriveLifecycleState mark
 * unexpired certs 'overdue'. Negatives must fall back to the shipped default.
 *
 * MED 5 — reminder send decision and lifecycle 'expiring' window must share ONE
 * threshold source; garbage/empty config falls back to the shipped [30, 7].
 */
class ConfigDefaultsTest extends TestCase {

    private function configReturning(string $value): IConfig {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturn($value);
        return $config;
    }

    private function configEchoingDefault(): IConfig {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            static fn (string $app, string $key, string $default = ''): string => $default
        );
        return $config;
    }

    public function testGraceDaysDefault(): void {
        $this->assertSame(
            (int)ConfigDefaults::RECERT_GRACE_DAYS_DEFAULT,
            ConfigDefaults::graceDays($this->configEchoingDefault())
        );
    }

    public function testGraceDaysAcceptsZeroAndPositive(): void {
        $this->assertSame(0, ConfigDefaults::graceDays($this->configReturning('0')),
            'grace 0 = close immediately at expiry — a valid explicit choice');
        $this->assertSame(30, ConfigDefaults::graceDays($this->configReturning('30')));
    }

    public function testGraceDaysRejectsNegative(): void {
        $this->assertSame(
            (int)ConfigDefaults::RECERT_GRACE_DAYS_DEFAULT,
            ConfigDefaults::graceDays($this->configReturning('-30')),
            'HIGH 4: a negative grace must NEVER flip the cutoff into the future'
        );
    }

    public function testThresholdsDefault(): void {
        $this->assertSame([30, 7], ConfigDefaults::reminderThresholds($this->configEchoingDefault()));
    }

    public function testThresholdsCustomSortedDescending(): void {
        $this->assertSame([60, 14], ConfigDefaults::reminderThresholds($this->configReturning('14,60')));
    }

    public function testThresholdsGarbageFallsBackToDefaults(): void {
        $this->assertSame([30, 7], ConfigDefaults::reminderThresholds($this->configReturning('abc,-5,0')),
            'garbage/negative-only config must fall back to the shipped defaults, never []');
        $this->assertSame([30, 7], ConfigDefaults::reminderThresholds($this->configReturning('')),
            'empty config must fall back to the shipped defaults');
    }
}
