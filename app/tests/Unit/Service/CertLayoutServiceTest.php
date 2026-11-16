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

    /** @param array{mime: string, bytes: string}|null $themingLogo what ThemingLogoSource::current() gives */
    private function service(?IRootFolder $root = null, ?FakeDbConnection $db = null, ?\OCA\Learning\Service\CertLogoLibrary $library = null, ?array $themingLogo = null): CertLayoutService {
        $theming = $this->createMock(\OCA\Learning\Service\ThemingLogoSource::class);
        $theming->method('current')->willReturn($themingLogo);
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
            $library ?? $this->createMock(\OCA\Learning\Service\CertLogoLibrary::class),
            $theming,
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
        // GitHub #78: an image stored within the grace period is spared too (the copy of the Theming
        // logo is stored by an issuance that does not change the course)
        $this->assertSame([['type' => 'lt', 'left' => 'a.created_at', 'right' => ['value' => 1760000000 - 3600, 'type' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT]]], $candidates->andWhereCalls);
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

    // ---- GitHub #78: the course's logo from the library ---------------------------------------

    private function library(?string $id = null, string $mime = 'image/jpeg', string $bytes = 'JPEGBYTES'): \OCA\Learning\Service\CertLogoLibrary {
        $library = $this->createMock(\OCA\Learning\Service\CertLogoLibrary::class);
        $library->method('content')->willReturnCallback(function (string $asked) use ($id, $mime, $bytes): array {
            if ($asked !== $id) {
                throw new \OCP\Files\NotFoundException('no such logo');
            }
            return ['mime' => $mime, 'bytes' => $bytes];
        });
        return $library;
    }

    public function testChoosingALibraryLogoCopiesItIntoTheCourseImages(): void {
        $id = str_repeat('d', 64);
        $db = new FakeDbConnection([
            new FakeQueryBuilder(new FakeResult()),   // not stored for the course yet
            new FakeQueryBuilder(null, 1),            // insert
        ]);

        $course = $this->service(null, $db, $this->library($id))->selectLogo($this->course(), $id);

        $this->assertSame($id, CertLayout::decode($course->getCertLayout())['logo_sha']);
        $insert = $db->issuedBuilders[1];
        $this->assertSame('insert', $insert->operation);
        $values = array_column($insert->namedParameters, 'value');
        $this->assertContains('image/jpeg', $values, 'the type of the logo, not image/png');
        $this->assertContains(base64_encode('JPEGBYTES'), $values);
        $this->assertContains(7, $values);
        $this->assertSame(1760000000, $course->getUpdatedAt(), 'the change starts the grace period of the sweep');
    }

    public function testALogoThatIsNotInTheLibraryIsRefused(): void {
        $db = new FakeDbConnection();
        foreach ([str_repeat('e', 64), '../../etc/passwd', 'x'] as $id) {
            try {
                $this->service(null, $db, $this->library(str_repeat('d', 64)))->selectLogo($this->course(), $id);
                $this->fail('accepted ' . $id);
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('logo_not_found', $e->getMessage());
            }
        }
        $this->assertCount(0, $db->issuedBuilders);
        $this->assertSame([], $this->updated);
    }

    public function testThemingAgainClearsTheLogo(): void {
        $layout = CertLayout::defaults();
        $layout['logo_sha'] = str_repeat('d', 64);

        $course = $this->service()->selectLogo($this->course(CertLayout::encode($layout)), null);

        $this->assertNull($course->getCertLayout(), 'back to the standard layout');
    }

    public function testTheSweepKeepsTheCoursesLogo(): void {
        $logo = str_repeat('d', 64);
        $layout = CertLayout::defaults();
        $layout['logo_sha'] = $logo;
        $db = new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetchAll([['id' => 1, 'course_id' => 7, 'sha256' => $logo, 'cert_layout' => CertLayout::encode($layout)]])),
        ]);

        $this->assertSame(0, $this->service(null, $db)->sweepUnreferencedAssets());
        $this->assertCount(1, $db->issuedBuilders, 'no certificate lookup, no delete');
    }

    public function testCertificatePresentationAndVerifyLogoUseTheCopy(): void {
        $logo = str_repeat('d', 64);
        $layout = CertLayout::defaults();
        $layout['logo_sha'] = $logo;
        $cert = new Certificate();
        $cert->setCourseId(7);
        $cert->setLayoutJson(CertLayout::encode($layout));
        $row = ['mime' => 'image/webp', 'data_b64' => 'UklGRg=='];
        $db = new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetch($row)),
            new FakeQueryBuilder(FakeResult::fromFetch($row)),
        ]);
        $service = $this->service(null, $db);

        $this->assertSame('data:image/webp;base64,UklGRg==', $service->presentationForCertificate($cert)['logo'] ?? null);
        $this->assertSame('data:image/webp;base64,UklGRg==', $service->logoForCertificate($cert));

        $old = new Certificate();
        $old->setCourseId(7);
        $this->assertNull($service->logoForCertificate($old), 'issued before 5.14.0: the Theming logo');
    }

    /**
     * GitHub #78 (review): a course on the Theming logo keeps a copy of the Theming logo of the
     * moment of issuance, so replacing the Theming logo later does not redraw the certificate.
     */
    public function testIssuanceCopiesTheThemingLogoOfThisMoment(): void {
        $bytes = self::png(120, 40);
        $sha = hash('sha256', $bytes);
        $db = new FakeDbConnection([
            new FakeQueryBuilder(new FakeResult()),   // not stored for the course yet
            new FakeQueryBuilder(null, 1),            // insert
        ]);

        $json = $this->service(null, $db, null, ['mime' => 'image/png', 'bytes' => $bytes])->snapshotForIssuance($this->course());

        $this->assertNotNull($json, 'the standard layout with a Theming copy is stored');
        $layout = CertLayout::decode($json);
        $this->assertSame($sha, $layout['theming_logo_sha']);
        $this->assertNull($layout['logo_sha']);
        $insert = $db->issuedBuilders[1];
        $this->assertSame('insert', $insert->operation);
        $this->assertSame('learning_cert_assets', $insert->table);
        $values = array_column($insert->namedParameters, 'value');
        $this->assertContains(base64_encode($bytes), $values);
        $this->assertContains('image/png', $values);
        $this->assertContains(7, $values);
    }

    public function testNoThemingCopyKeepsTheOldRowShape(): void {
        $db = new FakeDbConnection();

        $this->assertNull($this->service(null, $db)->snapshotForIssuance($this->course()));
        $this->assertCount(0, $db->issuedBuilders);
    }

    public function testALibraryLogoNeedsNoThemingCopy(): void {
        $layout = CertLayout::defaults();
        $layout['logo_sha'] = str_repeat('d', 64);
        $db = new FakeDbConnection();

        $json = $this->service(null, $db, null, ['mime' => 'image/png', 'bytes' => self::png()])
            ->snapshotForIssuance($this->course(CertLayout::encode($layout)));

        $this->assertSame(str_repeat('d', 64), CertLayout::decode($json)['logo_sha']);
        $this->assertNull(CertLayout::decode($json)['theming_logo_sha']);
        $this->assertCount(0, $db->issuedBuilders, 'nothing copied');
    }

    public function testCertificateShowsItsThemingCopyWithoutTheLibraryPlate(): void {
        $layout = CertLayout::defaults();
        $layout['theming_logo_sha'] = str_repeat('f', 64);
        $cert = new Certificate();
        $cert->setCourseId(7);
        $cert->setLayoutJson(CertLayout::encode($layout));
        $row = ['mime' => 'image/png', 'data_b64' => 'QUJD'];
        $db = new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetch($row)),
            new FakeQueryBuilder(FakeResult::fromFetch($row)),
        ]);
        $service = $this->service(null, $db);

        $presentation = $service->presentationForCertificate($cert);
        $this->assertSame('data:image/png;base64,QUJD', $presentation['logo'] ?? null);
        $this->assertFalse($presentation['logo_library']);
        $this->assertSame('data:image/png;base64,QUJD', $service->logoForCertificate($cert));
        $this->assertSame(str_repeat('f', 64), $db->issuedBuilders[0]->namedParameters[1]['value']);
    }
}
