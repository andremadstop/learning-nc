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

    private function file(string $bytes, ?int $size = null): File {
        $file = $this->createMock(File::class);
        $file->method('isReadable')->willReturn(true);
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

    public function testReplacingKeepsAnImageAnIssuedCertificateStillUses(): void {
        $old = str_repeat('b', 64);
        $layout = CertLayout::defaults();
        $layout['stamp_sha'] = $old;
        $bytes = self::png();
        $db = new FakeDbConnection([
            new FakeQueryBuilder(new FakeResult()),               // assetExists(new) → none
            new FakeQueryBuilder(),                                // insert new
            new FakeQueryBuilder(FakeResult::fromFetchOne(12)),    // a certificate still uses the old one
        ]);
        $service = $this->service($this->rootWith('teacher', '/neu.png', $this->file($bytes)), $db);

        $service->importImage($this->course(CertLayout::encode($layout)), 'stamp', 'teacher', '/neu.png');

        $this->assertCount(3, $db->issuedBuilders, 'no delete of the old image');
        $this->assertStringContainsString($old, (string)$db->issuedBuilders[2]->namedParameters[1]['value']);
    }

    public function testRemovingDeletesAnImageNobodyUses(): void {
        $old = str_repeat('c', 64);
        $layout = CertLayout::defaults();
        $layout['signature_sha'] = $old;
        $db = new FakeDbConnection([
            new FakeQueryBuilder(new FakeResult()),   // no certificate uses it
            new FakeQueryBuilder(),                   // delete
        ]);

        $course = $this->service(null, $db)->removeImage($this->course(CertLayout::encode($layout)), 'signature');

        $this->assertNull($course->getCertLayout(), 'back to the standard layout');
        $this->assertSame('delete', $db->issuedBuilders[1]->operation);
        $this->assertSame('learning_cert_assets', $db->issuedBuilders[1]->table);
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
}
