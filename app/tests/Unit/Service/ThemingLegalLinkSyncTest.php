<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\OperatorInfo;
use OCA\Learning\Service\ThemingLegalLinkSync;
use OCP\IConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Audit 2026-11 (high): on every boot Learning pointed the Nextcloud imprint and privacy links at its
 * own pages whenever they were empty - on installations whose pages then named the app author's
 * company as operator. The links are now taken over only once an operator is configured, and a
 * link the admin set elsewhere is never overwritten.
 */
#[AllowMockObjectsWithoutExpectations]
class ThemingLegalLinkSyncTest extends TestCase {
    private const PRIVACY = 'https://cloud.example/apps/learning/privacy';
    private const IMPRINT = 'https://cloud.example/apps/learning/impressum';

    /** @var array<string, array<string, string>> */
    private array $values = [];
    /** @var list<string> */
    private array $writes = [];

    private function sync(): ThemingLegalLinkSync {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, $default = '') => $this->values[$app][$key] ?? $default
        );
        $config->method('setAppValue')->willReturnCallback(
            function (string $app, string $key, $value): void {
                $this->writes[] = $app . '.' . $key;
                $this->values[$app][$key] = (string)$value;
            }
        );
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('linkToRouteAbsolute')->willReturnCallback(
            static fn (string $route): string => match ($route) {
                'learning.page.privacy' => self::PRIVACY,
                'learning.page.impressum' => self::IMPRINT,
            }
        );
        return new ThemingLegalLinkSync($config, $urls, new OperatorInfo($config));
    }

    private function configureOperator(): void {
        $this->values['learning'] = [
            'operator_name' => 'Musterschule e. V.',
            'operator_address' => 'Schulweg 1, 12345 Musterstadt',
            'operator_email' => 'kontakt@musterschule.example',
        ];
    }

    public function testWithoutOperatorDetailsNoLinkIsWritten(): void {
        $this->sync()->sync();

        $this->assertSame([], $this->writes);
    }

    public function testIncompleteOperatorDetailsWriteNoLink(): void {
        $this->values['learning'] = ['operator_name' => 'Musterschule e. V.'];

        $this->sync()->sync();

        $this->assertSame([], $this->writes);
    }

    public function testWithOperatorDetailsEmptyLinksPointAtTheLearningPages(): void {
        $this->configureOperator();

        $this->sync()->sync();

        $this->assertSame(self::PRIVACY, $this->values['theming']['privacyUrl']);
        $this->assertSame(self::IMPRINT, $this->values['theming']['imprintUrl']);
    }

    public function testLinksTheAdminSetElsewhereAreNeverOverwritten(): void {
        $this->configureOperator();
        $this->values['theming'] = [
            'privacyUrl' => 'https://schule.example/datenschutz',
            'imprintUrl' => 'https://schule.example/impressum',
        ];

        $this->sync()->sync();

        $this->assertSame([], $this->writes);
        $this->assertSame('https://schule.example/datenschutz', $this->values['theming']['privacyUrl']);
        $this->assertSame('https://schule.example/impressum', $this->values['theming']['imprintUrl']);
    }

    public function testAnOwnLinkFromAnOldAddressIsRefreshed(): void {
        $this->configureOperator();
        $this->values['theming'] = [
            'privacyUrl' => 'http://old.example/apps/learning/privacy',
            'imprintUrl' => self::IMPRINT,
        ];

        $this->sync()->sync();

        $this->assertSame(['theming.privacyUrl'], $this->writes);
        $this->assertSame(self::PRIVACY, $this->values['theming']['privacyUrl']);
    }
}
