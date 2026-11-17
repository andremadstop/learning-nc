<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseDocument;
use OCA\Learning\Db\CourseDocumentMapper;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Service\DocumentService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #75: "a pure rename doesn't count as a change". A renamed file used to become a new
 * document row on the next scan; the old row (the one the required-content entry and every
 * read confirmation point at) could no longer be opened. The scan now follows the Nextcloud
 * file id, and the file version ignores the name.
 */
#[AllowMockObjectsWithoutExpectations]
class DocumentScanRenameTest extends TestCase {
    /** @param list<CourseDocument> $rows */
    private function memoryMapper(array $rows): CourseDocumentMapper {
        return new class($rows) extends CourseDocumentMapper {
            /** @var list<CourseDocument> */
            public array $inserted = [];
            /** @var list<CourseDocument> */
            public array $updated = [];

            /** @param list<CourseDocument> $rows */
            public function __construct(public array $rows) {
            }

            public function findByCourseId(int $courseId): array {
                return array_values(array_filter($this->rows, static fn ($r) => $r->getCourseId() === $courseId));
            }

            public function insert($entity) {
                $this->inserted[] = $entity;
                $this->rows[] = $entity;
                return $entity;
            }

            public function update($entity) {
                $this->updated[] = $entity;
                return $entity;
            }
        };
    }

    private function scan(CourseDocumentMapper $mapper, string $name, int $fileId): void {
        $this->scanAll($mapper, [[$name, $fileId]]);
    }

    /** @param list<array{0: string, 1: int}> $listing name and file id, in Nextcloud's listing order */
    private function scanAll(CourseDocumentMapper $mapper, array $listing): void {
        $course = new Course();
        $course->setInstructorId('teacher');
        $course->setMaterialFolder('/Material');
        $courseMapper = $this->createMock(CourseMapper::class);
        $courseMapper->method('findById')->willReturn($course);

        $nodes = [];
        foreach ($listing as [$name, $fileId]) {
            $file = $this->createMock(Node::class);
            $file->method('getType')->willReturn(Node::TYPE_FILE);
            $file->method('getName')->willReturn($name);
            $file->method('getSize')->willReturn(1234);
            $file->method('getId')->willReturn($fileId);
            $nodes[] = $file;
        }
        $folder = $this->createMock(Folder::class);
        $folder->method('getType')->willReturn(Node::TYPE_FOLDER);
        $folder->method('getDirectoryListing')->willReturn($nodes);
        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('get')->willReturn($folder);
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($userFolder);

        (new DocumentService($mapper, $courseMapper, $root, $this->createMock(LoggerInterface::class)))->scanFolder(4, 'teacher');
    }

    private static function row(int $id, string $name, ?int $fileId): CourseDocument {
        $doc = new CourseDocument();
        $doc->setId($id);
        $doc->setCourseId(4);
        $doc->setFilePath('/Material/' . $name);
        $doc->setFileName($name);
        $doc->setFileType('pdf');
        $doc->setFileId($fileId);
        return $doc;
    }

    public function testRenamedFileKeepsItsRow(): void {
        $row = self::row(5, 'Chapter 1.pdf', 77);
        $mapper = $this->memoryMapper([$row]);

        $this->scan($mapper, 'Chapter 1 (2026).pdf', 77);

        self::assertSame([], $mapper->inserted, 'a rename must not register a new document');
        self::assertSame(5, $row->getId());
        self::assertSame('Chapter 1 (2026).pdf', $row->getFileName());
        self::assertSame('/Material/Chapter 1 (2026).pdf', $row->getFilePath());
    }

    public function testRowFromBeforeTheFileIdIsStampedOnTheNextScan(): void {
        $row = self::row(5, 'Chapter 1.pdf', null);
        $mapper = $this->memoryMapper([$row]);

        $this->scan($mapper, 'Chapter 1.pdf', 77);

        self::assertSame([], $mapper->inserted);
        self::assertSame(77, $row->getFileId());
    }

    public function testAnotherFileUnderANewNameIsANewRow(): void {
        $mapper = $this->memoryMapper([self::row(5, 'Chapter 1.pdf', 77)]);

        $this->scan($mapper, 'Chapter 2.pdf', 78);

        self::assertCount(1, $mapper->inserted);
        self::assertSame(78, $mapper->inserted[0]->getFileId());
    }

    /** @return array<string, array{0: list<array{0: string, 1: int}>}> */
    public static function renameAndReuploadListings(): array {
        return [
            'new file listed first' => [[['Policy.pdf', 200], ['Policy_v1.pdf', 100]]],
            'renamed file listed first' => [[['Policy_v1.pdf', 100], ['Policy.pdf', 200]]],
        ];
    }

    /**
     * GitHub #75 (review): the instructor renames "Policy.pdf" (file 100, required) to
     * "Policy_v1.pdf" and uploads a new "Policy.pdf" (file 200). The row follows its file, in
     * either listing order, and stays there on the next scan; the new upload is a new row. It
     * used to take the new file id when the new file came first, and then the old file's name,
     * swapping between both files on every scan.
     *
     * @param list<array{0: string, 1: int}> $listing
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('renameAndReuploadListings')]
    public function testRenamedAndReuploadedFileKeepsTheRowOnTheOldFile(array $listing): void {
        $row = self::row(5, 'Policy.pdf', 100);
        $mapper = $this->memoryMapper([$row]);

        for ($scan = 1; $scan <= 2; $scan++) {
            $this->scanAll($mapper, $listing);

            self::assertSame(100, $row->getFileId(), "scan $scan: the row stays on its file");
            self::assertSame('Policy_v1.pdf', $row->getFileName(), "scan $scan");
            self::assertSame('/Material/Policy_v1.pdf', $row->getFilePath(), "scan $scan");
            self::assertCount(1, $mapper->inserted, "scan $scan: one new row for the new upload, not one per scan");
            self::assertSame(200, $mapper->inserted[0]->getFileId());
            self::assertSame('/Material/Policy.pdf', $mapper->inserted[0]->getFilePath());
        }
    }

    /** @return array<string, array{0: bool}> */
    public static function rowOrders(): array {
        return ['a.pdf row first' => [false], 'b.pdf row first' => [true]];
    }

    /**
     * GitHub #75 (review L1): "b.pdf" (file 200) is deleted and "a.pdf" (file 100) renamed to
     * "b.pdf". The row of a.pdf follows its file; the row of the deleted b.pdf stays behind. The
     * b.pdf row used to take over the content of file 100 (the scan skipped the stamp and moved
     * on), while the a.pdf row kept pointing at a file that no longer exists.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rowOrders')]
    public function testRenameOntoTheNameOfADeletedFileMovesTheRowOfThatFile(bool $deletedRowFirst): void {
        $a = self::row(5, 'a.pdf', 100);
        $b = self::row(6, 'b.pdf', 200);
        $mapper = $this->memoryMapper($deletedRowFirst ? [$b, $a] : [$a, $b]);

        for ($scan = 1; $scan <= 2; $scan++) {
            $this->scanAll($mapper, [['b.pdf', 100]]);

            self::assertSame([], $mapper->inserted, "scan $scan: no new row");
            self::assertSame(100, $a->getFileId(), "scan $scan");
            self::assertSame('/Material/b.pdf', $a->getFilePath(), "scan $scan: the row follows its file");
            self::assertSame(200, $b->getFileId(), "scan $scan: the deleted file's row keeps its own file id");
        }
    }

    /** A real DocumentService resolving "b.pdf" of /Material, whose file has id $nodeId. */
    private function resolveService(int $nodeId, ?CourseDocument $owner): array {
        $course = new Course();
        $course->setId(4);
        $course->setInstructorId('teacher');
        $course->setMaterialFolder('/Material');

        $storage = $this->createMock(\OCP\Files\Storage\IStorage::class);
        $storage->method('instanceOfStorage')->willReturn(false);
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn($nodeId);
        $file->method('isReadable')->willReturn(true);
        $file->method('getStorage')->willReturn($storage);
        $file->method('getChecksum')->willReturn('');
        $file->method('getMTime')->willReturn(10);
        $file->method('getSize')->willReturn(1);
        $folder = $this->createMock(Folder::class);
        $folder->method('get')->with('b.pdf')->willReturn($file);
        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('get')->with('/Material')->willReturn($folder);
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($userFolder);

        $left = self::row(6, 'b.pdf', 200);
        $mapper = $this->createMock(CourseDocumentMapper::class);
        $mapper->method('findById')->willReturn($left);
        $mapper->method('findByCourseAndFileId')->with(4, $nodeId)->willReturn($owner);

        return [new DocumentService($mapper, $this->createMock(CourseMapper::class), $root, $this->createMock(LoggerInterface::class)), $course];
    }

    /** GitHub #75 (review L1): the row left behind no longer serves the other row's file. */
    public function testTheRowLeftBehindDoesNotServeTheFileOfAnotherRow(): void {
        $moved = self::row(5, 'b.pdf', 100);
        [$service, $course] = $this->resolveService(100, $moved);

        self::assertNull($service->currentFileInfo($course, 6));
        $this->expectException(\OCP\Files\NotFoundException::class);
        $service->resolveMaterialFile($course, 6);
    }

    /** A file replaced under the same name (new id, no scan yet) still opens for its row. */
    public function testAReplacedFileStillResolvesBeforeTheNextScan(): void {
        [$service, $course] = $this->resolveService(300, null);

        [$doc] = $service->resolveMaterialFile($course, 6);
        self::assertSame(6, $doc->getId());
        self::assertNotNull($service->currentFileInfo($course, 6));
    }

    /**
     * GitHub #75 (review): before 5.10.0 a folder typed as 'Material' and picked again as
     * '/Material' registered one file twice ('Material/x.pdf' and '/Material/x.pdf', no file
     * id). The scan stamps the file id on one of the two rows; the other one, which may be the
     * required one, kept serving the file before 5.14.0 and must still do so.
     */
    public function testADuplicateRowFromBefore510StillResolvesAfterTheScan(): void {
        $unnormalized = self::row(5, 'x.pdf', null);
        $unnormalized->setFilePath('Material/x.pdf');
        $normalized = self::row(6, 'x.pdf', null);
        $mapper = new class([$unnormalized, $normalized]) extends CourseDocumentMapper {
            /** @param list<CourseDocument> $rows */
            public function __construct(public array $rows) {
            }

            public function findByCourseId(int $courseId): array {
                return $this->rows;
            }

            public function findById(int $id): CourseDocument {
                foreach ($this->rows as $row) {
                    if ($row->getId() === $id) {
                        return $row;
                    }
                }
                throw new \OCP\AppFramework\Db\DoesNotExistException('none');
            }

            public function findByCourseAndFileId(int $courseId, int $fileId): ?CourseDocument {
                foreach ($this->rows as $row) {
                    if ($row->getFileId() === $fileId) {
                        return $row;
                    }
                }
                return null;
            }

            public function insert($entity) {
                $this->rows[] = $entity;
                return $entity;
            }

            public function update($entity) {
                return $entity;
            }
        };

        $course = new Course();
        $course->setId(4);
        $course->setInstructorId('teacher');
        $course->setMaterialFolder('/Material');
        $courseMapper = $this->createMock(CourseMapper::class);
        $courseMapper->method('findById')->willReturn($course);

        $storage = $this->createMock(\OCP\Files\Storage\IStorage::class);
        $storage->method('instanceOfStorage')->willReturn(false);
        $file = $this->createMock(File::class);
        $file->method('getType')->willReturn(Node::TYPE_FILE);
        $file->method('getName')->willReturn('x.pdf');
        $file->method('getId')->willReturn(100);
        $file->method('getSize')->willReturn(1);
        $file->method('isReadable')->willReturn(true);
        $file->method('getStorage')->willReturn($storage);
        $file->method('getChecksum')->willReturn('');
        $file->method('getMTime')->willReturn(10);
        $folder = $this->createMock(Folder::class);
        $folder->method('getType')->willReturn(Node::TYPE_FOLDER);
        $folder->method('getDirectoryListing')->willReturn([$file]);
        $folder->method('get')->with('x.pdf')->willReturn($file);
        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('get')->willReturn($folder);
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($userFolder);

        $service = new DocumentService($mapper, $courseMapper, $root, $this->createMock(LoggerInterface::class));
        $service->scanFolder(4, 'teacher');

        self::assertCount(2, $mapper->rows, 'the scan registers no third row');
        $stamped = array_values(array_filter([$unnormalized, $normalized], static fn ($r) => $r->getFileId() === 100));
        self::assertCount(1, $stamped, 'the scan stamps the file id on one of the two rows');
        foreach ([5, 6] as $id) {
            [$doc] = $service->resolveMaterialFile($course, $id);
            self::assertSame($id, $doc->getId(), "row $id still serves the file");
            self::assertNotNull($service->currentFileInfo($course, $id), "row $id is confirmed with a version");
        }
    }

    public function testVersionIgnoresTheNameAndFollowsTheContent(): void {
        $make = function (string $checksum, int $mtime, int $size): File {
            $file = $this->createMock(File::class);
            $file->method('getChecksum')->willReturn($checksum);
            $file->method('getMTime')->willReturn($mtime);
            $file->method('getSize')->willReturn($size);
            return $file;
        };
        // No checksum on the storage: mtime and size decide (a rename changes neither).
        self::assertSame(DocumentService::fileVersion($make('', 100, 10)), DocumentService::fileVersion($make('', 100, 10)));
        self::assertNotSame(DocumentService::fileVersion($make('', 100, 10)), DocumentService::fileVersion($make('', 200, 10)));
        // With a checksum, the content decides; a touched mtime is no new version.
        self::assertSame(DocumentService::fileVersion($make('SHA1:abc', 100, 10)), DocumentService::fileVersion($make('SHA1:abc', 300, 10)));
        self::assertNotSame(DocumentService::fileVersion($make('SHA1:abc', 100, 10)), DocumentService::fileVersion($make('SHA1:def', 100, 10)));
        self::assertSame(64, strlen(DocumentService::fileVersion($make('SHA1:abc MD5:def ADLER32:1', 1, 1))));
    }
}
