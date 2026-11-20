<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\CertBackgroundLibrary;
use OCA\Learning\Service\CertLogoLibrary;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #93: the certificate background library. PNG, JPEG and WebP by content within 2 MB and
 * 4000 pixels per edge; SVG and everything else refused. What is stored is a copy encoded again
 * with GD: metadata (EXIF with GPS position) and anything appended to the file do not survive.
 * The logo library keeps its own, smaller limits.
 */
#[AllowMockObjectsWithoutExpectations]
class CertBackgroundLibraryTest extends TestCase {
    /** @var array<string, string> app config of the test */
    private array $config = [];
    /** @var array<string, string>|null files of the cert-backgrounds folder, null = no folder yet */
    private ?array $files = null;

    private function library(): CertBackgroundLibrary {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(fn(string $app, string $key, string $default = ''): string => $this->config[$key] ?? $default);
        $config->method('setAppValue')->willReturnCallback(function (string $app, string $key, string $value): void {
            $this->config[$key] = $value;
        });
        $config->method('deleteAppValue')->willReturnCallback(function (string $app, string $key): void {
            unset($this->config[$key]);
        });

        $folder = $this->createMock(ISimpleFolder::class);
        $folder->method('fileExists')->willReturnCallback(fn(string $name): bool => isset($this->files[$name]));
        $folder->method('newFile')->willReturnCallback(function (string $name, $content = null) {
            $this->files[$name] = (string)$content;
            return $this->file($name);
        });
        $folder->method('getFile')->willReturnCallback(function (string $name) {
            if (!isset($this->files[$name])) {
                throw new NotFoundException($name);
            }
            return $this->file($name);
        });
        $appData = $this->createMock(IAppData::class);
        $appData->method('getFolder')->willReturnCallback(function (string $name) use ($folder) {
            $this->assertSame('cert-backgrounds', $name);
            if ($this->files === null) {
                throw new NotFoundException($name);
            }
            return $folder;
        });
        $appData->method('newFolder')->willReturnCallback(function (string $name) use ($folder) {
            $this->assertSame('cert-backgrounds', $name);
            $this->files ??= [];
            return $folder;
        });

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1760000000);
        return new CertBackgroundLibrary($appData, $config, $time);
    }

    private function file(string $name): ISimpleFile {
        $file = $this->createMock(ISimpleFile::class);
        $file->method('getContent')->willReturnCallback(fn(): string => $this->files[$name]);
        $file->method('putContent')->willReturnCallback(function ($content) use ($name): void {
            $this->files[$name] = (string)$content;
        });
        $file->method('delete')->willReturnCallback(function () use ($name): void {
            unset($this->files[$name]);
        });
        return $file;
    }

    /** A small image drawn with GD in the given type; with $alpha a half-transparent pixel at 0,0. */
    private static function image(string $mime, int $width = 6, int $height = 4, bool $alpha = false): string {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int)imagecolorallocate($image, 30, 90, 160));
        if ($alpha) {
            imagesetpixel($image, 0, 0, (int)imagecolorallocatealpha($image, 200, 10, 10, 64));
        }
        ob_start();
        match ($mime) {
            'image/png' => imagepng($image),
            'image/jpeg' => imagejpeg($image, null, 95),
            default => imagewebp($image, null, 95),
        };
        return (string)ob_get_clean();
    }

    /** A PNG with only a header: tiny file, any dimensions. */
    private static function pngHeader(int $width, int $height): string {
        $ihdr = pack('NNCCCCC', $width, $height, 8, 0, 0, 0, 0);
        return "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));
    }

    /** @return array<string, array{string}> */
    public static function acceptedTypes(): array {
        return ['PNG' => ['image/png'], 'JPEG' => ['image/jpeg'], 'WebP' => ['image/webp']];
    }

    #[DataProvider('acceptedTypes')]
    public function testAcceptsTheThreeTypesAndStoresAReencodedCopy(string $mime): void {
        $library = $this->library();
        $upload = self::image($mime);

        $entry = $library->add('Frame', $upload);

        $this->assertSame($mime, $entry['mime']);
        $stored = $this->files[$entry['id']] ?? null;
        $this->assertIsString($stored);
        $this->assertSame(hash('sha256', $stored), $entry['id'], 'the id is the sha256 of the stored copy');
        $this->assertSame(CertBackgroundLibrary::reencode($upload, $mime), $stored);
        $this->assertSame(['mime' => $mime, 'bytes' => $stored], $library->content($entry['id']));
        $this->assertSame([$entry], $library->list());
        $this->assertArrayHasKey('cert_background_library', $this->config);
        $this->assertArrayNotHasKey('cert_logo_library', $this->config, 'the logo library is a different list');
        $size = getimagesizefromstring($stored);
        $this->assertSame([6, 4], [$size[0] ?? 0, $size[1] ?? 0]);
    }

    public function testExifAndGpsDataDoNotSurvive(): void {
        $jpeg = self::image('image/jpeg');
        // an APP1 Exif segment right after SOI, carrying a GPS marker
        $payload = "Exif\0\0" . 'MM' . "\0\x2a" . 'GPSLatitude=52.5200N;GPSLongitude=13.4050E;Camera=Example';
        $withExif = "\xFF\xD8" . "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2);
        $this->assertNotFalse(getimagesizefromstring($withExif), 'the test file is a valid JPEG');

        $entry = $this->library()->add('Photo', $withExif);
        $stored = $this->files[$entry['id']];

        $this->assertStringNotContainsString('GPSLatitude', $stored);
        $this->assertStringNotContainsString('Exif', $stored);
        $this->assertStringNotContainsString('Example', $stored);
    }

    public function testAnythingAppendedToTheFileIsDropped(): void {
        $polyglot = self::image('image/png') . "PK\x03\x04" . '<?php echo "polyglot"; ?><script>alert(1)</script>';
        $entry = $this->library()->add('Polyglot', $polyglot);
        $stored = $this->files[$entry['id']];

        $this->assertStringNotContainsString('polyglot', $stored);
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString("PK\x03\x04", $stored);
    }

    /**
     * A 30 × 10 JPEG, blue with a red 6 × 6 block in the top-left corner, with an APP1 Exif segment
     * whose IFD0 holds only the orientation tag (0x0112). GD cannot write EXIF, so it is spliced in.
     */
    private static function jpegWithOrientation(int $orientation, bool $littleEndian = false): string {
        $image = imagecreatetruecolor(30, 10);
        imagefilledrectangle($image, 0, 0, 29, 9, (int)imagecolorallocate($image, 20, 40, 220));
        imagefilledrectangle($image, 0, 0, 5, 5, (int)imagecolorallocate($image, 230, 20, 20));
        ob_start();
        imagejpeg($image, null, 95);
        $jpeg = (string)ob_get_clean();
        $s = $littleEndian ? 'v' : 'n';
        $l = $littleEndian ? 'V' : 'N';
        $tiff = ($littleEndian ? 'II' : 'MM') . pack($s, 42) . pack($l, 8)
            . pack($s, 1) . pack($s, 0x0112) . pack($s, 3) . pack($l, 1) . pack($s, $orientation) . "\0\0"
            . pack($l, 0);
        $payload = "Exif\0\0" . $tiff;
        return "\xFF\xD8" . "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2);
    }

    /** Whether the pixel is the red of the corner block (JPEG is lossy: a range, not a value). */
    private static function isRed(\GdImage $image, int $x, int $y): bool {
        $rgb = imagecolorat($image, $x, $y);
        return (($rgb >> 16) & 0xFF) > 150 && ($rgb & 0xFF) < 110;
    }

    /** @return array<string, array{int, bool, int, int, int, int}> orientation, little endian, width, height, red x, red y */
    public static function orientations(): array {
        return [
            '1 as stored' => [1, false, 30, 10, 2, 2],
            '3 upside down' => [3, false, 30, 10, 27, 7],
            '6 turned right (phone held upright)' => [6, false, 10, 30, 7, 2],
            '6 little endian' => [6, true, 10, 30, 7, 2],
            '8 turned left' => [8, false, 10, 30, 2, 27],
            '2 mirrored' => [2, false, 30, 10, 27, 2],
        ];
    }

    /**
     * The stored copy has no EXIF any more, so it must already be the right way up: a photo taken
     * with the phone held upright (orientation 6) came out lying on its side before (C10 review).
     */
    #[DataProvider('orientations')]
    public function testTheExifOrientationIsAppliedToThePixels(int $orientation, bool $littleEndian, int $width, int $height, int $redX, int $redY): void {
        $upload = self::jpegWithOrientation($orientation, $littleEndian);
        $this->assertNotFalse(getimagesizefromstring($upload), 'the test file is a valid JPEG');

        $entry = $this->library()->add('Photo', $upload);
        $stored = $this->files[$entry['id']];
        $image = imagecreatefromstring($stored);
        $this->assertNotFalse($image);

        $this->assertSame([$width, $height], [imagesx($image), imagesy($image)]);
        $this->assertTrue(self::isRed($image, $redX, $redY), 'the red corner is where the photo shows it');
        $this->assertStringNotContainsString('Exif', $stored);
    }

    /** @return array<string, array{string}> */
    public static function brokenExif(): array {
        $cut = static fn(string $tiff): string => "Exif\0\0" . $tiff;
        return [
            'IFD offset beyond the segment' => [$cut('MM' . pack('n', 42) . pack('N', 4000))],
            'entry count beyond the segment' => [$cut('II' . pack('v', 42) . pack('V', 8) . pack('v', 500))],
            'no TIFF header' => [$cut('XX' . str_repeat("\0", 10))],
            'orientation out of range' => [$cut('MM' . pack('n', 42) . pack('N', 8) . pack('n', 1)
                . pack('n', 0x0112) . pack('n', 3) . pack('N', 1) . pack('n', 99) . "\0\0" . pack('N', 0))],
            'orientation with the wrong type' => [$cut('MM' . pack('n', 42) . pack('N', 8) . pack('n', 1)
                . pack('n', 0x0112) . pack('n', 4) . pack('N', 1) . pack('N', 6) . pack('N', 0))],
        ];
    }

    /** A broken Exif segment never costs the upload: it is stored as drawn, without warnings. */
    #[DataProvider('brokenExif')]
    public function testABrokenExifSegmentStoresTheImageAsItIs(string $payload): void {
        $plain = self::jpegWithOrientation(1);
        $jpeg = "\xFF\xD8" . "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($plain, 2);
        $this->assertNotFalse(getimagesizefromstring($jpeg), 'the test file is a valid JPEG');

        $entry = $this->library()->add('Photo', $jpeg);
        $image = imagecreatefromstring($this->files[$entry['id']]);
        $this->assertNotFalse($image);

        $this->assertSame([30, 10], [imagesx($image), imagesy($image)]);
        $this->assertTrue(self::isRed($image, 2, 2));
    }

    public function testTransparencyIsKept(): void {
        foreach (['image/png', 'image/webp'] as $mime) {
            $stored = CertBackgroundLibrary::reencode(self::image($mime, 6, 4, true), $mime);
            $image = imagecreatefromstring($stored);
            $this->assertNotFalse($image);
            $alpha = (imagecolorat($image, 0, 0) >> 24) & 0x7F;
            $this->assertGreaterThan(30, $alpha, $mime . ': the half-transparent pixel stayed transparent');
            $this->assertSame(0, (imagecolorat($image, 3, 2) >> 24) & 0x7F, $mime . ': the opaque pixels stayed opaque');
        }
    }

    /** @return array<string, array{string}> */
    public static function refusedFiles(): array {
        return [
            'SVG' => ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="10" height="10"/></svg>'],
            'SVG with an XML prolog' => ['<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"/>'],
            'HTML' => ['<html><script>alert(1)</script></html>'],
            'PNG signature on text' => ["\x89PNG\r\n\x1a\n" . 'abc'],
            'PNG header without pixels' => [self::pngHeader(10, 10)],
            'zero pixels' => [self::pngHeader(0, 0)],
            'GIF' => ['GIF89a' . str_repeat("\0", 20)],
            'empty' => [''],
        ];
    }

    #[DataProvider('refusedFiles')]
    public function testRefusesEverythingElse(string $bytes): void {
        $library = $this->library();
        try {
            $library->add('Background', $bytes);
            $this->fail('accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('unsupported_type', $e->getMessage());
        }
        $this->assertSame([], $library->list());
        $this->assertEmpty($this->files ?? []);
    }

    public function testRefusesFilesOverTwoMegabytes(): void {
        $tooBig = self::image('image/png') . str_repeat("\0", CertBackgroundLibrary::MAX_BYTES);
        $this->expectExceptionMessage('too_large');
        $this->library()->add('Background', $tooBig);
    }

    public function testRefusesMoreThan4000PixelsPerEdgeBeforeDecoding(): void {
        foreach ([[4001, 1], [1, 4001]] as [$width, $height]) {
            try {
                $this->library()->add('Background', self::pngHeader($width, $height));
                $this->fail('accepted');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('too_large', $e->getMessage());
            }
        }
    }

    /** Inventory: the shared code did not move the logo limits (also used for the Theming logo copy). */
    public function testTheLogoLibraryKeepsItsSmallerLimits(): void {
        $header = self::pngHeader(3500, 10);
        try {
            CertBackgroundLibrary::detectImage($header);
        } catch (\InvalidArgumentException $e) {
            $this->fail('a background may have 3500 pixels: ' . $e->getMessage());
        }
        $this->expectExceptionMessage('too_large');
        CertLogoLibrary::detectImage($header);
    }

    public function testTheSameImageOnlyOnceAndAtMostTheLimit(): void {
        $library = $this->library();
        $library->add('One', self::image('image/png'));
        try {
            $library->add('Again', self::image('image/png'));
            $this->fail('accepted twice');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('duplicate', $e->getMessage());
        }

        $list = [];
        for ($i = 0; $i < CertBackgroundLibrary::MAX_BACKGROUNDS; $i++) {
            $list[] = ['id' => str_pad(dechex($i), 64, 'a', STR_PAD_LEFT), 'name' => 'B' . $i, 'mime' => 'image/png', 'size' => 1, 'created_at' => 1];
        }
        $this->config['cert_background_library'] = (string)json_encode($list);
        $this->expectExceptionMessage('limit_reached');
        $library->add('Too many', self::image('image/jpeg'));
    }

    public function testRenameAndDelete(): void {
        $library = $this->library();
        $entry = $library->add('Frame', self::image('image/png'));

        $library->rename($entry['id'], '  Gold frame ');
        $this->assertSame('Gold frame', $library->find($entry['id'])['name'] ?? null);

        $library->delete($entry['id']);
        $this->assertSame([], $library->list());
        $this->assertArrayNotHasKey($entry['id'], $this->files ?? []);
        $this->assertArrayNotHasKey('cert_background_library', $this->config);

        $this->expectException(NotFoundException::class);
        $library->delete($entry['id']);
    }

    public function testAFileChangedOnDiskIsNotServed(): void {
        $library = $this->library();
        $entry = $library->add('Frame', self::image('image/png'));
        $this->files[$entry['id']] = '<svg xmlns="http://www.w3.org/2000/svg"/>';

        $this->expectException(NotFoundException::class);
        $library->content($entry['id']);
    }
}
