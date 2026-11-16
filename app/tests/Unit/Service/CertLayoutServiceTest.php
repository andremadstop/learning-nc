<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Certificate;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Service\CertLayout;
use OCA\Learning\Service\CertLayoutService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorage;
use OCP\Files\Storage\ISharedStorage;
use OCP\Share\IAttributes;
use OCP\Share\IShare;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #43: stamp and signature come from the instructor's own files, only as real PNGs within
 * the limits, and reach the client only inside the certificate (or settings preview) response.
 */
#[AllowMockObjectsWithoutExpectations]
class CertLayoutServiceTest extends TestCase {
    /** @var Course[] */
    private array $updated = [];

    public static function png(int $width = 40, int $height = 20): string {
        $ihdr = pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0);
        $chunk = static fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', $ihdr) . $chunk('IDAT', (string)gzcompress('')) . $chunk('IEND', '');
    }

    private function course(?string $layout = null): Course {
        $course = new Course();
        $course->setId(7);
        $course->setInstructorId('teacher');
        $course->setCertLayout($layout);
        return $course;
    }

    private function service(?IRootFolder $root = null, ?FakeDbConnection $db = null): CertLayoutService {
        $mapper = $this->createMock(CourseMapper::class);
        $mapper->method('update')->willReturnCallback(function (Course $c): Course {
            $this->updated[] = $c;
            return $c;
        });
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1760000000);
        return new CertLayoutService(
            $db ?? new FakeDbConnection(),
            $root ?? $this->createMock(IRootFolder::class),
            $mapper,
            $time,
            $this->issuerName(),
        );
    }

    /** GitHub #77: no course name, no operator name, Theming name "DevCloud". */
    private function issuerName(): \OCA\Learning\Service\CertIssuerName {
        $theming = $this->createMock(\OCP\Defaults::class);
        $theming->method('getName')->willReturn('DevCloud');
        return new \OCA\Learning\Service\CertIssuerName(
            new \OCA\Learning\Service\OperatorInfo($this->createMock(\OCP\IConfig::class)),
            $theming,
        );
    }

    /** A user folder in which $path resolves to $node, or throws NotFound for anything else. */
    private function rootWith(string $user, string $path, mixed $node): IRootFolder {
        $folder = $this->createMock(Folder::class);
        $folder->method('get')->willReturnCallback(function (string $p) use ($path, $node) {
            if ($p !== $path) {
                throw new NotFoundException($p);
            }
            return $node;
        });
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturnCallback(function (string $u) use ($user, $folder) {
            if ($u !== $user) {
                throw new \RuntimeException('read through the wrong user folder: ' . $u);
            }
            return $folder;
        });
        return $root;
    }

    private function file(string $bytes, ?int $size = null, ?IStorage $storage = null): File {
        if ($storage === null) {
            $storage = $this->createMock(IStorage::class);
            $storage->method('instanceOfStorage')->willReturn(false);
        }
        $file = $this->createMock(File::class);
        $file->method('isReadable')->willReturn(true);
        $file->method('getStorage')->willReturn($storage);
        $file->method('getSize')->willReturn($size ?? strlen($bytes));
        $file->method('getContent')->willReturn($bytes);
        return $file;
    }

    public function testImportStoresThePngAndPointsTheLayoutAtIt(): void {
        $bytes = self::png();
        // assetExists → no row; insert
        $db = new FakeDbConnection([new FakeQueryBuilder(new FakeResult()), new FakeQueryBuilder()]);
        $service = $this->service($this->rootWith('teacher', '/Stempel.png', $this->file($bytes)), $db);

        $course = $service->importImage($this->course(), 'stamp', 'teacher', '/Stempel.png');

        $layout = CertLayout::decode($course->getCertLayout());
        $this->assertSame(hash('sha256', $bytes), $layout['stamp_sha']);
        $this->assertNull($layout['signature_sha']);
        $insert = $db->issuedBuilders[1];
        $this->assertSame('insert', $insert->operation);
        $this->assertSame('learning_cert_assets', $insert->table);
        $this->assertSame(base64_encode($bytes), $insert->insertValues['data_b64']['value']);
        $this->assertSame('image/png', $insert->insertValues['mime']['value']);
        $this->assertCount(1, $this->updated);
    }

    /** The storage of a share given to the instructor; $download null = no attributes (allowed). */
    private function sharedStorage(?bool $download): ISharedStorage {
        $attributes = null;
        if ($download !== null) {
            $attributes = $this->createMock(IAttributes::class);
            $attributes->method('getAttribute')->willReturnCallback(
                static fn (string $scope, string $key) => ($scope === 'permissions' && $key === 'download') ? $download : null);
        }
        $share = $this->createMock(IShare::class);
        $share->method('getAttributes')->willReturn($attributes);
        $storage = $this->createMock(ISharedStorage::class);
        $storage->method('instanceOfStorage')->willReturnCallback(static fn (string $class) => $class === ISharedStorage::class);
        $storage->method('getShare')->willReturn($share);
        return $storage;
    }

    /**
     * A file shared with the instructor as "view only" must not become a stamp or signature:
     * its bytes would go to every learner of the course inside the certificate.
     */
    public function testViewOnlyShareIsRefusedBeforeReading(): void {
        $file = $this->createMock(File::class);
        $file->method('isReadable')->willReturn(true);
        $file->method('getSize')->willReturn(100);
        $file->method('getStorage')->willReturn($this->sharedStorage(false));
        $file->expects($this->never())->method('getContent');
        $db = new FakeDbConnection();
        $service = $this->service($this->rootWith('teacher', '/Geteilt/Unterschrift.png', $file), $db);

        try {
            $service->importImage($this->course(), 'signature', 'teacher', '/Geteilt/Unterschrift.png');
            $this->fail('a view-only share must not be imported');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('not_downloadable', $e->getMessage());
        }
        $this->assertSame([], $this->updated, 'the course layout is unchanged');
        $this->assertCount(0, $db->issuedBuilders, 'nothing stored');
    }

    public function testShareWithDownloadAllowedIsImported(): void {
        foreach ([true, null] as $download) {
            $this->updated = [];
            $bytes = self::png();
            $db = new FakeDbConnection([new FakeQueryBuilder(new FakeResult()), new FakeQueryBuilder()]);
            $file = $this->file($bytes, null, $this->sharedStorage($download));
            $service = $this->service($this->rootWith('teacher', '/Geteilt/Stempel.png', $file), $db);

            $course = $service->importImage($this->course(), 'stamp', 'teacher', '/Geteilt/Stempel.png');

            $this->assertSame(hash('sha256', $bytes), CertLayout::decode($course->getCertLayout())['stamp_sha']);
            $this->assertCount(1, $this->updated);
        }
    }

    public function testFileOutsideTheUsersFolderIsNotFound(): void {
        $service = $this->service($this->rootWith('teacher', '/Stempel.png', $this->file(self::png())));

        $this->expectException(NotFoundException::class);
        $service->importImage($this->course(), 'stamp', 'teacher', '/../other/Stempel.png');
    }

    public function testFolderIsNotAnImage(): void {
        $service = $this->service($this->rootWith('teacher', '/Bilder', $this->createMock(Folder::class)));

        $this->expectException(NotFoundException::class);
        $service->importImage($this->course(), 'signature', 'teacher', '/Bilder');
    }

    public function testJpegRenamedToPngIsRefused(): void {
        $jpeg = "\xFF\xD8\xFF\xE0" . str_repeat("\0", 64);
        $service = $this->service($this->rootWith('teacher', '/fake.png', $this->file($jpeg)));

        try {
            $service->importImage($this->course(), 'stamp', 'teacher', '/fake.png');
            $this->fail('a JPEG must not pass as PNG');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('not_png', $e->getMessage());
        }
        $this->assertSame([], $this->updated, 'nothing changed');
    }

    public function testPngSignatureWithGarbageIsRefused(): void {
        $service = $this->service($this->rootWith('teacher', '/x.png', $this->file("\x89PNG\r\n\x1a\n" . 'garbage')));

        $this->expectExceptionMessage('not_png');
        $service->importImage($this->course(), 'stamp', 'teacher', '/x.png');
    }

    public function testOversizedFileIsRefusedBeforeReading(): void {
        $file = $this->createMock(File::class);
        $file->method('isReadable')->willReturn(true);
        $file->method('getSize')->willReturn(CertLayoutService::MAX_BYTES + 1);
        $file->method('getStorage')->willReturn($this->createMock(IStorage::class));
        $file->expects($this->never())->method('getContent');
        $service = $this->service($this->rootWith('teacher', '/big.png', $file));

        $this->expectExceptionMessage('too_large');
        $service->importImage($this->course(), 'stamp', 'teacher', '/big.png');
    }

    public function testOversizedDimensionsAreRefused(): void {
        $service = $this->service($this->rootWith('teacher', '/wide.png', $this->file(self::png(CertLayoutService::MAX_DIMENSION + 1, 10))));

        $this->expectExceptionMessage('too_large');
        $service->importImage($this->course(), 'stamp', 'teacher', '/wide.png');
    }

    public function testUnknownKindIsRefused(): void {
        $this->expectExceptionMessage('invalid_kind');
        $this->service()->importImage($this->course(), 'logo', 'teacher', '/x.png');
    }

    /**
     * An issuance reads the course layout at its start and inserts the certificate later: an
     * image deleted on the change could be the one that certificate is inserted with.
     */
    public function testReplacingNeverDeletesTheOldImageInline(): void {
        $old = str_repeat('b', 64);
        $layout = CertLayout::defaults();
        $layout['stamp_sha'] = $old;
        $db = new FakeDbConnection([
            new FakeQueryBuilder(new FakeResult()),   // assetExists(new) → none
            new FakeQueryBuilder(),                    // insert new
        ]);
        $service = $this->service($this->rootWith('teacher', '/neu.png', $this->file(self::png())), $db);

        $service->importImage($this->course(CertLayout::encode($layout)), 'stamp', 'teacher', '/neu.png');

        $this->assertCount(2, $db->issuedBuilders, 'nothing after the insert of the new image');
        $this->assertSame('insert', $db->issuedBuilders[1]->operation);
    }

    public function testRemovingNeverDeletesTheImageInline(): void {
        $old = str_repeat('c', 64);
        $layout = CertLayout::defaults();
        $layout['signature_sha'] = $old;
        $db = new FakeDbConnection();

        $course = $this->service(null, $db)->removeImage($this->course(CertLayout::encode($layout)), 'signature');

        $this->assertNull($course->getCertLayout(), 'back to the standard layout');
        $this->assertCount(0, $db->issuedBuilders, 'no query on the image table');
        $this->assertSame(1760000000, $course->getUpdatedAt(), 'the change starts the grace period');
    }

    public function testSweepLooksOnlyAtCoursesUnchangedForTheGracePeriod(): void {
        $db = new FakeDbConnection([new FakeQueryBuilder(FakeResult::fromFetchAll([]))]);

        $this->assertSame(0, $this->service(null, $db)->sweepUnreferencedAssets());

        $candidates = $db->issuedBuilders[0];
        $this->assertSame([['type' => 'lt', 'left' => 'c.updated_at', 'right' => ['value' => 1760000000 - 3600, 'type' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT]]], $candidates->whereCalls);
        $this->assertSame('learning_courses', $candidates->joins[0]['join']);
    }

    public function testSweepDeletesAnImageNobodyUses(): void {
        $sha = str_repeat('c', 64);
        $db = new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetchAll([['id' => 5, 'course_id' => 7, 'sha256' => $sha, 'cert_layout' => null]])),
            new FakeQueryBuilder(new FakeResult()),   // no certificate uses it
            new FakeQueryBuilder(null, 1),            // delete
        ]);

        $this->assertSame(1, $this->service(null, $db)->sweepUnreferencedAssets());

        $this->assertStringContainsString($sha, (string)$db->issuedBuilders[1]->namedParameters[1]['value']);
        $this->assertSame('delete', $db->issuedBuilders[2]->operation);
        $this->assertSame('learning_cert_assets', $db->issuedBuilders[2]->table);
        $this->assertSame(5, $db->issuedBuilders[2]->namedParameters[0]['value']);
    }

    public function testSweepKeepsTheCoursesOwnImageAndOneACertificateUses(): void {
        $own = str_repeat('a', 64);
        $issued = str_repeat('b', 64);
        $layout = CertLayout::defaults();
        $layout['stamp_sha'] = $own;
        $db = new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetchAll([
                ['id' => 1, 'course_id' => 7, 'sha256' => $own, 'cert_layout' => CertLayout::encode($layout)],
                ['id' => 2, 'course_id' => 7, 'sha256' => $issued, 'cert_layout' => CertLayout::encode($layout)],
            ])),
            new FakeQueryBuilder(FakeResult::fromFetchOne(12)),   // a certificate still uses $issued
        ]);

        $this->assertSame(0, $this->service(null, $db)->sweepUnreferencedAssets());
        $this->assertCount(2, $db->issuedBuilders, 'no delete');
    }

    public function testCertificateWithoutSnapshotHasNoPresentation(): void {
        $cert = new Certificate();
        $cert->setCourseId(7);

        $this->assertNull($this->service()->presentationForCertificate($cert));
    }

    public function testCertificatePresentationCarriesItsImageAsDataUrl(): void {
        $sha = str_repeat('d', 64);
        $layout = CertLayout::defaults();
        $layout['intro'] = 'Hiermit wird bestätigt, dass {student.name}';
        $layout['stamp_sha'] = $sha;
        $cert = new Certificate();
        $cert->setCourseId(7);
        $cert->setLayoutJson(CertLayout::encode($layout));
        $db = new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetch(['mime' => 'image/png', 'data_b64' => 'QUJD'])),
        ]);

        $presentation = $this->service(null, $db)->presentationForCertificate($cert);

        $this->assertSame('Hiermit wird bestätigt, dass {student.name}', $presentation['intro']);
        $this->assertSame('data:image/png;base64,QUJD', $presentation['stamp']);
        $this->assertNull($presentation['signature']);
        // The image is looked up in the certificate's own course.
        $this->assertSame(7, $db->issuedBuilders[0]->namedParameters[0]['value']);
        $this->assertSame($sha, $db->issuedBuilders[0]->namedParameters[1]['value']);
    }

    /** GitHub #77: the settings preview names the organisation the next certificate is issued in. */
    public function testCoursePresentationNamesTheOrganisation(): void {
        $layout = CertLayout::defaults();
        $layout['issuer_name'] = 'Training Solutions LTD';
        $layout['show']['issuer'] = false;

        $own = $this->service()->presentationForCourse($this->course(CertLayout::encode($layout)));
        $this->assertSame('Training Solutions LTD', $own['issuer_name']);
        $this->assertSame('DevCloud', $own['issuer_name_fallback']);
        $this->assertFalse($own['show']['issuer']);

        $standard = $this->service()->presentationForCourse($this->course(null));
        $this->assertSame('DevCloud', $standard['issuer_name']);
        $this->assertTrue($standard['show']['issuer']);
    }
}
