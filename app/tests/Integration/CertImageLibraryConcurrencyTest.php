<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Service\CertImageLibrary;
use OCA\Learning\Service\CertLibraryConflictException;
use OCA\Learning\Service\CertLogoLibrary;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #102 against a real Nextcloud on both engines (scripts/nc-integration.sh): the real app
 * config keeps what this request read in its cache, and the real lock provider holds the library
 * lock. Another administrator's change is written straight into oc_appconfig, past this request's
 * cache, the way a parallel request's save lands.
 */
final class CertImageLibraryConcurrencyTest extends TestCase {
    private const KEY = 'cert_logo_library';
    private const LOCK = 'learning/cert-library/cert_logo_library';

    private CertLogoLibrary $library;

    protected function setUp(): void {
        $this->library = Server::get(CertLogoLibrary::class);
        $this->clean();
    }

    protected function tearDown(): void {
        $this->clean();
    }

    private function clean(): void {
        $appConfig = Server::get(IAppConfig::class);
        $appConfig->clearCache();
        foreach ($this->library->list() as $entry) {
            $this->library->delete($entry['id'], CertImageLibrary::revisionOf($this->library->list()));
        }
        $appConfig->deleteKey('learning', self::KEY);
        $appConfig->clearCache();
    }

    private static function png(int $red): string {
        $image = imagecreatetruecolor(40, 20);
        imagefilledrectangle($image, 0, 0, 39, 19, (int)imagecolorallocate($image, $red, 70, 140));
        ob_start();
        imagepng($image);
        return (string)ob_get_clean();
    }

    /** Another request saves the list: straight into the table, this request's cache does not see it. */
    private function savedElsewhere(string $json): void {
        $db = Server::get(IDBConnection::class);
        $delete = $db->getQueryBuilder();
        $delete->delete('appconfig')
            ->where($delete->expr()->eq('appid', $delete->createNamedParameter('learning')))
            ->andWhere($delete->expr()->eq('configkey', $delete->createNamedParameter(self::KEY)));
        $delete->executeStatement();
        $insert = $db->getQueryBuilder();
        $insert->insert('appconfig')->values([
            'appid' => $insert->createNamedParameter('learning'),
            'configkey' => $insert->createNamedParameter(self::KEY),
            'configvalue' => $insert->createNamedParameter($json),
            'type' => $insert->createNamedParameter(IAppConfig::VALUE_MIXED, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
            'lazy' => $insert->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
        ]);
        $insert->executeStatement();
    }

    public function testAnAdditionKeepsTheEntryAnotherRequestSavedMeanwhile(): void {
        $this->assertSame([], $this->library->list(), 'this request has read the empty list');
        $other = hash('sha256', 'saved by another administrator');
        $this->savedElsewhere((string)json_encode([[
            'id' => $other, 'name' => 'Theirs', 'mime' => 'image/png', 'size' => 1, 'created_at' => 1, 'reencoded' => true,
        ]]));
        $this->assertSame([], $this->library->list(), 'the cache still holds what this request read');

        $mine = $this->library->add('Mine', self::png(10));

        Server::get(IAppConfig::class)->clearCache();
        $this->assertSame([$other, $mine['id']], array_column($this->library->list(), 'id'));
    }

    public function testARenameOnAListChangedMeanwhileIsRefused(): void {
        $logo = $this->library->add('Primary', self::png(10));
        $seen = CertImageLibrary::revisionOf($this->library->list());
        $stored = $this->library->list();
        $stored[0]['name'] = 'Renamed elsewhere';
        $this->savedElsewhere((string)json_encode($stored));

        try {
            $this->library->rename($logo['id'], 'Mine', $seen);
            $this->fail('a rename on an outdated list was applied');
        } catch (CertLibraryConflictException $e) {
            $this->assertSame('library_changed', $e->getMessage());
        }
        Server::get(IAppConfig::class)->clearCache();
        $this->assertSame(['Renamed elsewhere'], array_column($this->library->list(), 'name'));
    }

    public function testATakenLibraryLockRefusesTheChangeAndWritesNothing(): void {
        $locking = Server::get(ILockingProvider::class);
        $locking->acquireLock(self::LOCK, ILockingProvider::LOCK_EXCLUSIVE);
        try {
            $this->library->add('Primary', self::png(10));
            $this->fail('the library was changed while its lock was held');
        } catch (CertLibraryConflictException $e) {
            $this->assertSame('library_busy', $e->getMessage());
        } finally {
            $locking->releaseLock(self::LOCK, ILockingProvider::LOCK_EXCLUSIVE);
        }
        Server::get(IAppConfig::class)->clearCache();
        $this->assertSame([], $this->library->list());

        // released, the next change goes through
        $this->library->add('Primary', self::png(10));
        $this->assertCount(1, $this->library->list());
    }
}
