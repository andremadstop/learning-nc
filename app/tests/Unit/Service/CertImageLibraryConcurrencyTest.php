<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\CertBackgroundLibrary;
use OCA\Learning\Service\CertImageLibrary;
use OCA\Learning\Service\CertLibraryConflictException;
use OCA\Learning\Service\CertLogoLibrary;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #102: two administrators changing a certificate image library at the same time. The list
 * lives in the app config, which Nextcloud caches per request: each library below is one request
 * with its own cache (it reads the stored config once and keeps it until clearCache()), all of them
 * on the same stored config, the same app data and the same locks (Nextcloud's database lock: a
 * taken lock throws at once, it does not wait).
 */
#[AllowMockObjectsWithoutExpectations]
class CertImageLibraryConcurrencyTest extends TestCase {
    private const LOGO_LOCK = 'learning/cert-library/cert_logo_library';

    /** @var array<string, string> the app config as stored */
    private array $stored = [];
    /** @var array<string, array<string, string>> files per app-data folder */
    private array $files = [];
    /** @var array<string, true> locks held */
    private array $locks = [];
    /** a write to the app config throws (the database is gone mid-request) */
    private bool $failWrites = false;

    /**
     * One request: a library with its own app-config cache.
     *
     * @param class-string<CertImageLibrary> $class
     */
    private function request(string $class = CertLogoLibrary::class, bool $noWait = false): CertImageLibrary {
        $cache = null;
        $load = function () use (&$cache): void {
            $cache ??= $this->stored;
        };
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(function (string $app, string $key, string $default = '') use (&$cache, $load): string {
            $load();
            return $cache[$key] ?? $default;
        });
        $config->method('setAppValue')->willReturnCallback(function (string $app, string $key, string $value) use (&$cache, $load): void {
            $this->assertWriteLocked($key);
            $load();
            $this->stored[$key] = $value;
            $cache[$key] = $value;
        });
        $config->method('deleteAppValue')->willReturnCallback(function (string $app, string $key) use (&$cache, $load): void {
            $this->assertWriteLocked($key);
            $load();
            unset($this->stored[$key], $cache[$key]);
        });
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('clearCache')->willReturnCallback(function () use (&$cache): void {
            $cache = null;
        });

        $locking = $this->createMock(ILockingProvider::class);
        $locking->method('acquireLock')->willReturnCallback(function (string $path, int $type): void {
            $this->assertSame(ILockingProvider::LOCK_EXCLUSIVE, $type);
            $this->assertLessThanOrEqual(64, strlen($path), 'oc_file_locks.key holds 64 characters');
            if (isset($this->locks[$path])) {
                throw new LockedException($path);
            }
            $this->locks[$path] = true;
        });
        $locking->method('releaseLock')->willReturnCallback(function (string $path): void {
            unset($this->locks[$path]);
        });

        $appData = $this->createMock(IAppData::class);
        $appData->method('getFolder')->willReturnCallback(function (string $name) {
            if (!isset($this->files[$name])) {
                throw new NotFoundException($name);
            }
            return $this->folder($name);
        });
        $appData->method('newFolder')->willReturnCallback(function (string $name) {
            $this->files[$name] ??= [];
            return $this->folder($name);
        });
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1760000000);

        if ($noWait) {
            return new class($appData, $config, $time, $appConfig, $locking) extends CertLogoLibrary {
                protected function lockWaitMs(): int {
                    return 0;
                }
            };
        }
        return new $class($appData, $config, $time, $appConfig, $locking);
    }

    private function assertWriteLocked(string $key): void {
        if ($this->failWrites) {
            throw new \RuntimeException('database gone');
        }
        $this->assertArrayHasKey('learning/cert-library/' . $key, $this->locks, 'the list is written only under the library lock');
    }

    private function folder(string $folderName): ISimpleFolder {
        $folder = $this->createMock(ISimpleFolder::class);
        $folder->method('fileExists')->willReturnCallback(fn(string $name): bool => isset($this->files[$folderName][$name]));
        $folder->method('newFile')->willReturnCallback(function (string $name, $content = null) use ($folderName) {
            $this->files[$folderName][$name] = (string)$content;
            return $this->file($folderName, $name);
        });
        $folder->method('getFile')->willReturnCallback(function (string $name) use ($folderName) {
            if (!isset($this->files[$folderName][$name])) {
                throw new NotFoundException($name);
            }
            return $this->file($folderName, $name);
        });
        return $folder;
    }

    private function file(string $folderName, string $name): ISimpleFile {
        $file = $this->createMock(ISimpleFile::class);
        $file->method('getContent')->willReturnCallback(fn(): string => $this->files[$folderName][$name]);
        $file->method('putContent')->willReturnCallback(function ($content) use ($folderName, $name): void {
            $this->files[$folderName][$name] = (string)$content;
        });
        $file->method('delete')->willReturnCallback(function () use ($folderName, $name): void {
            unset($this->files[$folderName][$name]);
        });
        return $file;
    }

    /** A small PNG of one colour: a different colour is a different image (another id). */
    private static function png(int $red): string {
        $image = imagecreatetruecolor(4, 3);
        imagefilledrectangle($image, 0, 0, 3, 2, (int)imagecolorallocate($image, $red, 90, 160));
        ob_start();
        imagepng($image);
        return (string)ob_get_clean();
    }

    /** @return list<string> */
    private function storedNames(): array {
        return array_column($this->request()->list(), 'name');
    }

    public function testTwoAdministratorsAddingAtOnceKeepBothImages(): void {
        $first = $this->request();
        $second = $this->request();
        // both pages show the empty library: both requests have read the list
        $this->assertSame([], $first->list());
        $this->assertSame([], $second->list());

        $a = $first->add('Primary', self::png(10));
        $b = $second->add('Wordmark', self::png(200));

        $this->assertSame(['Primary', 'Wordmark'], $this->storedNames(), 'the second addition does not write back the list its request read first');
        $this->assertArrayHasKey($a['id'], $this->files['cert-logos']);
        $this->assertArrayHasKey($b['id'], $this->files['cert-logos']);
        $this->assertSame([], $this->locks, 'every lock is released');
    }

    public function testTheDuplicateCheckSeesTheListAsStoredNow(): void {
        $first = $this->request();
        $second = $this->request();
        $first->list();
        $second->list();

        $first->add('Primary', self::png(10));
        try {
            $second->add('Primary again', self::png(10));
            $this->fail('the same image was added twice');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('duplicate', $e->getMessage());
        }
        $this->assertSame(['Primary'], $this->storedNames());
    }

    public function testARenameOnAListChangedSinceIsRefusedAndChangesNothing(): void {
        $setup = $this->request();
        $logo = $setup->add('Primary', self::png(10));

        $first = $this->request();
        $second = $this->request();
        $seen = CertImageLibrary::revisionOf($first->list());
        $this->assertSame($seen, CertImageLibrary::revisionOf($second->list()));

        $second->rename($logo['id'], 'Main logo', $seen);
        $before = $this->stored;
        try {
            $first->rename($logo['id'], 'Logo (horizontal)', $seen);
            $this->fail('a rename on an outdated list was applied');
        } catch (CertLibraryConflictException $e) {
            $this->assertSame('library_changed', $e->getMessage());
        }
        $this->assertSame($before, $this->stored, 'the list is unchanged');
        $this->assertSame(['Main logo'], $this->storedNames());
        $this->assertSame([], $this->locks);

        // after reloading, the same rename goes through
        $fresh = $this->request();
        $fresh->rename($logo['id'], 'Logo (horizontal)', CertImageLibrary::revisionOf($fresh->list()));
        $this->assertSame(['Logo (horizontal)'], $this->storedNames());
    }

    public function testADeleteOnAListChangedSinceIsRefusedAndTheImageStays(): void {
        $setup = $this->request();
        $logo = $setup->add('Primary', self::png(10));
        $page = $this->request();
        $seen = CertImageLibrary::revisionOf($page->list());

        // meanwhile another administrator adds a logo
        $this->request()->add('Wordmark', self::png(200));

        try {
            $page->delete($logo['id'], $seen);
            $this->fail('a delete on an outdated list was applied');
        } catch (CertLibraryConflictException $e) {
            $this->assertSame('library_changed', $e->getMessage());
        }
        $this->assertSame(['Primary', 'Wordmark'], $this->storedNames());
        $this->assertArrayHasKey($logo['id'], $this->files['cert-logos'], 'the file stays with its entry');
    }

    public function testAChangeWithoutRevisionIsRefused(): void {
        $logo = $this->request()->add('Primary', self::png(10));
        $library = $this->request();

        foreach (['rename' => fn() => $library->rename($logo['id'], 'Other', ''), 'delete' => fn() => $library->delete($logo['id'], '')] as $what => $change) {
            try {
                $change();
                $this->fail($what . ' without revision was applied');
            } catch (CertLibraryConflictException $e) {
                $this->assertSame('library_changed', $e->getMessage());
            }
        }
        $this->assertSame(['Primary'], $this->storedNames());
    }

    public function testATakenLockGivesUpWithLibraryBusyAndWritesNothing(): void {
        $this->locks[self::LOGO_LOCK] = true;

        try {
            $this->request(noWait: true)->add('Primary', self::png(10));
            $this->fail('the library was changed while another change held its lock');
        } catch (CertLibraryConflictException $e) {
            $this->assertSame('library_busy', $e->getMessage());
        }
        $this->assertSame([], $this->stored);
        $this->assertSame([], $this->files['cert-logos'] ?? [], 'no file without list entry');
        $this->assertSame([self::LOGO_LOCK => true], $this->locks, 'the other change keeps its lock');

        // the background library has a lock of its own
        $this->request(CertBackgroundLibrary::class)->add('Frame', self::png(10));
        $this->assertCount(1, $this->request(CertBackgroundLibrary::class)->list());
    }

    public function testAFileWhoseEntryCouldNotBeSavedIsDeletedAgain(): void {
        $library = $this->request();
        $this->failWrites = true;
        try {
            $library->add('Primary', self::png(10));
            $this->fail('the add reported success without saving the list');
        } catch (\RuntimeException $e) {
            $this->assertSame('database gone', $e->getMessage());
        }
        $this->assertSame([], $this->files['cert-logos'], 'no file without list entry');
        $this->assertSame([], $this->locks, 'the lock is released after a failure');
    }

    public function testTheRevisionFollowsTheList(): void {
        $library = $this->request();
        $empty = CertImageLibrary::revisionOf($library->list());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $empty);
        $logo = $library->add('Primary', self::png(10));
        $one = CertImageLibrary::revisionOf($library->list());
        $this->assertNotSame($empty, $one);
        $library->rename($logo['id'], 'Main', $one);
        $this->assertNotSame($one, CertImageLibrary::revisionOf($library->list()), 'a rename changes the revision');
        $this->assertSame(CertImageLibrary::revisionOf($library->list()), CertImageLibrary::revisionOf($this->request()->list()), 'every request computes the same revision');
    }
}
