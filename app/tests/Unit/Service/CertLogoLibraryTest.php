<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

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
 * GitHub #78: the certificate logo library. PNG, JPEG and WebP by content; SVG, other files, files
 * dressed up as images and files over the limits are refused.
 *
 * ZC9 (pre-existing until 5.17.0): a logo was stored as uploaded, so the EXIF and GPS data of a
 * JPEG logo went with it onto every certificate and the public verification page. Like a
 * background, a logo is now decoded and encoded again before it is stored, and a course choosing a
 * logo stored before that gets a re-encoded copy.
 */
#[AllowMockObjectsWithoutExpectations]
class CertLogoLibraryTest extends TestCase {
    public const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAQAAAADCAIAAAA7ljmRAAAAE0lEQVR4nGM8wcXFAANMcBY6BwAhJgDiRtUPlQAAAABJRU5ErkJggg==';
    public const JPEG = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAADAAQDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwDgqKKK8M/VD//Z';
    public const WEBP = 'UklGRjwAAABXRUJQVlA4IDAAAADQAQCdASoEAAMAAUAmJaACdLoB+AADsAD+9hNf/izkYjs83/4E78Cd+BO/8AAAAAA=';

    /** @var array<string, string> app config of the test */
    private array $config = [];
    /** @var array<string, string>|null files of the cert-logos folder, null = no folder yet */
    private ?array $files = null;

    private function library(): CertLogoLibrary {
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
            $this->assertSame('cert-logos', $name);
            if ($this->files === null) {
                throw new NotFoundException($name);
            }
            return $folder;
        });
        $appData->method('newFolder')->willReturnCallback(function (string $name) use ($folder) {
            $this->files ??= [];
            return $folder;
        });

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1760000000);
        return new CertLogoLibrary($appData, $config, $time);
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

    /** @return array<string, array{string, string}> */
    public static function acceptedImages(): array {
        return [
            'PNG' => [self::PNG, 'image/png'],
            'JPEG' => [self::JPEG, 'image/jpeg'],
            'WebP' => [self::WEBP, 'image/webp'],
        ];
    }

    #[DataProvider('acceptedImages')]
    public function testAcceptsTheThreeTypesByContent(string $b64, string $mime): void {
        $library = $this->library();
        $upload = (string)base64_decode($b64);
        $entry = $library->add('Primary logo', $upload);

        $this->assertSame($mime, $entry['mime']);
        $stored = $this->files[$entry['id']] ?? null;
        $this->assertIsString($stored);
        // ZC9: a re-encoded copy, in the type of the upload; the id is the sha256 of what is stored
        $this->assertSame(CertLogoLibrary::reencode($upload, $mime), $stored);
        $this->assertSame(hash('sha256', $stored), $entry['id']);
        $this->assertTrue($entry['reencoded']);
        $this->assertSame([$entry], $library->list());
        $this->assertSame(['mime' => $mime, 'bytes' => $stored], $library->content($entry['id']));
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

    /** A JPEG with an APP1 Exif segment right after SOI, carrying a GPS marker. */
    private static function jpegWithGps(): string {
        $jpeg = self::image('image/jpeg');
        $payload = "Exif\0\0" . 'MM' . "\0\x2a" . 'GPSLatitude=52.5200N;GPSLongitude=13.4050E;Camera=Example';
        return "\xFF\xD8" . "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2);
    }

    /** ZC9: before 5.17.0 the logo was stored as uploaded, GPS position included. */
    public function testExifAndGpsDataDoNotSurvive(): void {
        $withExif = self::jpegWithGps();
        $this->assertNotFalse(getimagesizefromstring($withExif), 'the test file is a valid JPEG');

        $entry = $this->library()->add('Photo logo', $withExif);
        $stored = $this->files[$entry['id']];

        $this->assertSame('image/jpeg', $entry['mime']);
        $this->assertStringNotContainsString('GPSLatitude', $stored);
        $this->assertStringNotContainsString('Exif', $stored);
        $this->assertStringNotContainsString('Example', $stored);
    }

    public function testAnythingAppendedToTheFileIsDropped(): void {
        $polyglot = self::image('image/png') . "PK\x03\x04" . '<?php echo "polyglot"; ?><script>alert(1)</script>';
        $entry = $this->library()->add('Polyglot', $polyglot);
        $stored = $this->files[$entry['id']];

        $this->assertSame('image/png', $entry['mime'], 'a PNG stays a PNG');
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $stored);
        $this->assertStringNotContainsString('polyglot', $stored);
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString("PK\x03\x04", $stored);
    }

    /** The copy has no EXIF, so a logo photographed upright (orientation 6) must already stand upright. */
    public function testTheExifOrientationIsAppliedToThePixels(): void {
        $image = imagecreatetruecolor(30, 10);
        imagefilledrectangle($image, 0, 0, 29, 9, (int)imagecolorallocate($image, 20, 40, 220));
        imagefilledrectangle($image, 0, 0, 5, 5, (int)imagecolorallocate($image, 230, 20, 20));
        ob_start();
        imagejpeg($image, null, 95);
        $jpeg = (string)ob_get_clean();
        $tiff = 'MM' . pack('n', 42) . pack('N', 8) . pack('n', 1) . pack('n', 0x0112) . pack('n', 3) . pack('N', 1) . pack('n', 6) . "\0\0" . pack('N', 0);
        $payload = "Exif\0\0" . $tiff;
        $upload = "\xFF\xD8" . "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2);

        $entry = $this->library()->add('Upright', $upload);
        $stored = imagecreatefromstring($this->files[$entry['id']]);
        $this->assertNotFalse($stored);

        $this->assertSame([10, 30], [imagesx($stored), imagesy($stored)]);
        $rgb = imagecolorat($stored, 7, 2);
        $this->assertTrue((($rgb >> 16) & 0xFF) > 150 && ($rgb & 0xFF) < 110, 'the red corner is where the photo shows it');
    }

    public function testTransparencyIsKept(): void {
        foreach (['image/png', 'image/webp'] as $mime) {
            $this->files = null;
            $this->config = [];
            $entry = $this->library()->add('Transparent', self::image($mime, 6, 4, true));
            $this->assertSame($mime, $entry['mime']);
            $image = imagecreatefromstring($this->files[$entry['id']]);
            $this->assertNotFalse($image);
            $this->assertGreaterThan(30, (imagecolorat($image, 0, 0) >> 24) & 0x7F, $mime . ': the half-transparent pixel stayed transparent');
            $this->assertSame(0, (imagecolorat($image, 3, 2) >> 24) & 0x7F, $mime . ': the opaque pixels stayed opaque');
        }
    }

    /** The limit holds for what is stored: a re-encoded copy over 1 MB is refused like an upload over it. */
    public function testAReencodedCopyOverTheLimitIsTooLarge(): void {
        mt_srand(7);
        $image = imagecreatetruecolor(1400, 1400);
        for ($y = 0; $y < 1400; $y++) {
            for ($x = 0; $x < 1400; $x++) {
                imagesetpixel($image, $x, $y, mt_rand(0, 0xFFFFFF));
            }
        }
        ob_start();
        imagejpeg($image, null, 30);
        $upload = (string)ob_get_clean();
        $this->assertLessThan(CertLogoLibrary::MAX_BYTES, strlen($upload), 'the upload itself is within the limit');

        try {
            $this->library()->add('Noise', $upload);
            $this->fail('accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('too_large', $e->getMessage());
        }
        $this->assertSame([], $this->library()->list());
    }

    /**
     * ZC9: a logo stored before 5.17.0 stays as it is in the library (no migration), but a course
     * that chooses it gets a re-encoded copy; a logo stored since is copied as it is.
     */
    public function testACourseGetsAReencodedCopyOfALogoStoredBefore(): void {
        $old = self::jpegWithGps();
        $id = hash('sha256', $old);
        $this->files = [$id => $old];
        $this->config['cert_logo_library'] = (string)json_encode([['id' => $id, 'name' => 'Old', 'mime' => 'image/jpeg', 'size' => strlen($old), 'created_at' => 1]]);
        $library = $this->library();

        $this->assertFalse($library->find($id)['reencoded'] ?? null);
        $copy = $library->courseCopy($id);
        $this->assertSame('image/jpeg', $copy['mime']);
        $this->assertStringNotContainsString('GPSLatitude', $copy['bytes']);
        $this->assertStringNotContainsString('Exif', $copy['bytes']);
        $this->assertSame($old, $this->files[$id], 'the library keeps the logo as it was stored');

        $new = $library->add('New', self::image('image/png'));
        $this->assertSame(['mime' => 'image/png', 'bytes' => $this->files[$new['id']]], $library->courseCopy($new['id']), 'stored since: copied as it is');

        // the flag survives a rename (rename and delete write the list back)
        $library->rename($new['id'], 'Renamed');
        $this->assertTrue($library->find($new['id'])['reencoded'] ?? null);

        $this->expectException(NotFoundException::class);
        $library->courseCopy(str_repeat('f', 64));
    }

    /** @return array<string, array{string}> */
    public static function refusedFiles(): array {
        return [
            'SVG' => ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="10" height="10"/></svg>'],
            'SVG with an XML prolog' => ['<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"/>'],
            'HTML' => ['<html><script>alert(1)</script></html>'],
            'PNG signature on text' => ["\x89PNG\r\n\x1a\n" . 'abc'],
            'JPEG signature on a PNG body' => ["\xFF\xD8\xFF" . substr((string)base64_decode(self::PNG), 3)],
            'GIF' => ['GIF89a' . str_repeat("\0", 20)],
            'empty' => [''],
        ];
    }

    #[DataProvider('refusedFiles')]
    public function testRefusesEverythingElse(string $bytes): void {
        $library = $this->library();
        try {
            $library->add('Logo', $bytes);
            $this->fail('accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('unsupported_type', $e->getMessage());
        }
        $this->assertSame([], $library->list());
        $this->assertEmpty($this->files ?? []);
    }

    public function testRefusesTooLargeFilesAndImages(): void {
        $tooBig = (string)base64_decode(self::PNG) . str_repeat("\0", CertLogoLibrary::MAX_BYTES);
        try {
            $this->library()->add('Logo', $tooBig);
            $this->fail('accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('too_large', $e->getMessage());
        }

        // a 3001 × 1 PNG: small file, too many pixels
        $ihdr = pack('NNCCCCC', 3001, 1, 8, 0, 0, 0, 0);
        $png = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));
        $this->expectExceptionMessage('too_large');
        $this->library()->add('Logo', $png);
    }

    public function testNamesAreRequiredAndShort(): void {
        $library = $this->library();
        foreach (['', '   ', str_repeat('x', CertLogoLibrary::NAME_MAX + 1)] as $name) {
            try {
                $library->add($name, (string)base64_decode(self::PNG));
                $this->fail('accepted ' . $name);
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('invalid_name', $e->getMessage());
            }
        }
        $entry = $library->add("  Square\n logo ", (string)base64_decode(self::PNG));
        $this->assertSame('Square  logo', $entry['name']);
    }

    /** GitHub #77 (review L2): direction overrides and other invisible characters leave the name. */
    public function testNamesLoseDirectionAndInvisibleCharacters(): void {
        $entry = $this->library()->add("ACME\u{202E}\u{2066}\u{200F}\u{200B} Logo", (string)base64_decode(self::PNG));
        $this->assertSame('ACME Logo', $entry['name']);
        try {
            $this->library()->add("\u{202E}\u{200B}", (string)base64_decode(self::PNG));
            $this->fail('a name of invisible characters only was accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('invalid_name', $e->getMessage());
        }
    }

    public function testTheSameFileOnlyOnce(): void {
        $library = $this->library();
        $library->add('Primary', (string)base64_decode(self::PNG));
        $this->expectExceptionMessage('duplicate');
        $library->add('Again', (string)base64_decode(self::PNG));
    }

    public function testAtMostTheLimit(): void {
        $list = [];
        for ($i = 0; $i < CertLogoLibrary::MAX_LOGOS; $i++) {
            $list[] = ['id' => hash('sha256', (string)$i), 'name' => 'L' . $i, 'mime' => 'image/png', 'size' => 1, 'created_at' => 1];
        }
        $this->config['cert_logo_library'] = (string)json_encode($list);
        $this->expectExceptionMessage('limit_reached');
        $this->library()->add('One more', (string)base64_decode(self::JPEG));
    }

    public function testRenameAndDelete(): void {
        $library = $this->library();
        $a = $library->add('Primary', (string)base64_decode(self::PNG));
        $b = $library->add('Wordmark', (string)base64_decode(self::WEBP));

        $library->rename($a['id'], 'Primary (horizontal)');
        $this->assertSame('Primary (horizontal)', $library->find($a['id'])['name'] ?? null);

        $library->delete($a['id']);
        $this->assertSame([$b['id']], array_column($library->list(), 'id'));
        $this->assertArrayNotHasKey($a['id'], $this->files ?? []);

        $library->delete($b['id']);
        $this->assertArrayNotHasKey('cert_logo_library', $this->config, 'an empty library leaves no config behind');

        $this->expectException(NotFoundException::class);
        $library->delete($a['id']);
    }

    public function testAFileChangedOnDiskIsNotServed(): void {
        $library = $this->library();
        $entry = $library->add('Primary', (string)base64_decode(self::PNG));
        $this->files[$entry['id']] = '<svg onload="alert(1)"/>';

        $this->expectException(NotFoundException::class);
        $library->content($entry['id']);
    }

    public function testAMalformedIndexIsAnEmptyLibrary(): void {
        $this->config['cert_logo_library'] = '[{"id":"../../etc/passwd","name":"x","mime":"image/png"},{"id":"' . str_repeat('a', 64) . '","mime":"image/svg+xml"}]';
        $this->assertSame([], $this->library()->list());
    }
}
