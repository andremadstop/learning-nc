<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\ThemingLogoSource;
use OCP\App\IAppManager;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #78 (review): which Theming logo a certificate may keep a copy of. Only PNG, JPEG and
 * WebP (an SVG only through Theming's own raster copy logo.png); anything else, or a read error,
 * gives null and the certificate shows the live Theming logo.
 */
#[AllowMockObjectsWithoutExpectations]
class ThemingLogoSourceTest extends TestCase {
    /** @param array<string, string> $files name => bytes in theming's global/images */
    private function source(array $files, string $logoMime = 'image/png', bool $themingOn = true): ThemingLogoSource {
        $images = $this->createMock(ISimpleFolder::class);
        $images->method('fileExists')->willReturnCallback(fn(string $n): bool => isset($files[$n]));
        $images->method('getFile')->willReturnCallback(function (string $n) use ($files): ISimpleFile {
            if (!isset($files[$n])) {
                throw new NotFoundException($n);
            }
            $file = $this->createMock(ISimpleFile::class);
            $file->method('getContent')->willReturn($files[$n]);
            return $file;
        });
        $global = $this->createMock(ISimpleFolder::class);
        $global->method('getFolder')->with('images')->willReturn($images);
        $appData = $this->createMock(IAppData::class);
        $appData->method('getFolder')->with('global')->willReturn($global);
        $factory = $this->createMock(IAppDataFactory::class);
        $factory->method('get')->with('theming')->willReturn($appData);
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            fn(string $app, string $key, string $default = ''): string => $app === 'theming' && $key === 'logoMime' ? $logoMime : $default
        );
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForAnyone')->willReturn($themingOn);
        return new ThemingLogoSource($factory, $config, $apps, $this->createMock(LoggerInterface::class));
    }

    public function testAPngLogoIsCopied(): void {
        $png = CertLayoutServiceTest::png(200, 60);
        $this->assertSame(['mime' => 'image/png', 'bytes' => $png], $this->source(['logo' => $png])->current());
    }

    public function testAnSvgLogoUsesThemingsRasterCopy(): void {
        $png = CertLayoutServiceTest::png(200, 60);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $this->assertSame(['mime' => 'image/png', 'bytes' => $png], $this->source(['logo' => $svg, 'logo.png' => $png], 'image/svg+xml')->current());
        $this->assertNull($this->source(['logo' => $svg], 'image/svg+xml')->current(), 'never the SVG itself');
    }

    public function testNoCustomLogoOrThemingOffGivesNone(): void {
        $png = CertLayoutServiceTest::png();
        $this->assertNull($this->source(['logo' => $png], '')->current());
        $this->assertNull($this->source(['logo' => $png], 'image/png', false)->current());
        $this->assertNull($this->source([], 'image/png')->current());
    }

    public function testAReadErrorNeverStopsTheIssuance(): void {
        $factory = $this->createMock(IAppDataFactory::class);
        $factory->method('get')->willThrowException(new \RuntimeException('storage down'));
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturn('image/png');
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForAnyone')->willReturn(true);

        $this->assertNull((new ThemingLogoSource($factory, $config, $apps, $this->createMock(LoggerInterface::class)))->current());
    }
}
