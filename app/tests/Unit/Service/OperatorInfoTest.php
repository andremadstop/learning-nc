<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\OperatorInfo;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Audit 2026-11 (high): the operator of a Learning installation (provider in the imprint, controller
 * in the privacy statement) was hard-coded in privacy-info.json, so every installation named the
 * app author's company. The operator is now an admin setting; "configured" means name, address and
 * a valid contact email are all present.
 */
#[AllowMockObjectsWithoutExpectations]
class OperatorInfoTest extends TestCase {
    /** @var array<string, string> */
    private array $appValues = [];

    private function info(): OperatorInfo {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, $default = '') => $app === 'learning' ? ($this->appValues[$key] ?? $default) : $default
        );
        $config->method('setAppValue')->willReturnCallback(
            function (string $app, string $key, $value): void {
                $this->assertSame('learning', $app);
                $this->appValues[$key] = (string)$value;
            }
        );
        $config->method('deleteAppValue')->willReturnCallback(
            function (string $app, string $key): void {
                $this->assertSame('learning', $app);
                unset($this->appValues[$key]);
            }
        );
        return new OperatorInfo($config);
    }

    /** @return array<string, string> */
    private function complete(): array {
        return [
            'name' => 'Musterschule e. V.',
            'address' => "Schulweg 1\n12345 Musterstadt",
            'register' => 'Amtsgericht Musterstadt, VR 123',
            'email' => 'datenschutz@musterschule.example',
            'content_responsible' => 'Erika Muster',
        ];
    }

    public function testAFreshInstallationHasNoOperator(): void {
        $info = $this->info();

        $this->assertFalse($info->isConfigured());
        $this->assertNull($info->publicView());
        $this->assertSame(['name' => '', 'address' => '', 'register' => '', 'email' => '', 'content_responsible' => ''], $info->load());
    }

    public function testSavedDetailsAreReadBackAndCountAsConfigured(): void {
        $info = $this->info();
        $info->save($this->complete());

        $this->assertTrue($info->isConfigured());
        $this->assertSame($this->complete(), $info->load());
        $this->assertSame($this->complete(), $info->publicView());
    }

    public function testOptionalFieldsMayStayEmpty(): void {
        $values = array_merge($this->complete(), ['register' => '', 'content_responsible' => '']);

        $this->assertSame([], OperatorInfo::validate($values));
        $info = $this->info();
        $info->save($values);
        $this->assertTrue($info->isConfigured());
    }

    public function testEveryRequiredFieldIsNamedWhenMissing(): void {
        $errors = OperatorInfo::validate(['name' => 'Schule', 'address' => '', 'register' => '', 'email' => '', 'content_responsible' => '']);

        $this->assertSame(['address' => 'required', 'email' => 'required'], $errors);
    }

    public function testAnInvalidEmailIsRefused(): void {
        $errors = OperatorInfo::validate(array_merge($this->complete(), ['email' => 'kein-mailziel']));

        $this->assertSame(['email' => 'invalid'], $errors);
    }

    public function testOverlongValuesAreRefused(): void {
        $errors = OperatorInfo::validate(array_merge($this->complete(), ['name' => str_repeat('a', 201)]));

        $this->assertSame(['name' => 'too_long'], $errors);
    }

    public function testAllFieldsEmptyIsValidAndClearsTheOperator(): void {
        $info = $this->info();
        $info->save($this->complete());

        $empty = OperatorInfo::normalize([]);
        $this->assertSame([], OperatorInfo::validate($empty));
        $info->save($empty);

        $this->assertFalse($info->isConfigured());
        $this->assertSame([], $this->appValues);
    }

    public function testNormalizeTrimsAndStripsControlCharactersButKeepsLineBreaksInTheAddress(): void {
        $normalized = OperatorInfo::normalize([
            'name' => "  Schule\x00\x1b[31m  ",
            'address' => "Weg 1\r\n\r\n\r\n12345 Ort\t",
            'email' => " a@b.example\n",
            'register' => ['not', 'a', 'string'],
            'unknown' => 'dropped',
        ]);

        $this->assertSame([
            'name' => 'Schule[31m',
            'address' => "Weg 1\n\n12345 Ort",
            'register' => '',
            'email' => 'a@b.example',
            'content_responsible' => '',
        ], $normalized);
    }

    public function testStoredIncompleteDetailsDoNotCountAsConfigured(): void {
        // e.g. someone set a single key with occ config:app:set
        $this->appValues['operator_name'] = 'Schule';
        $info = $this->info();

        $this->assertFalse($info->isConfigured());
        $this->assertNull($info->publicView());
    }
}
