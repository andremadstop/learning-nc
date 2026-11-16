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
        $course = new Course();
        $course->setInstructorId('teacher');
        $course->setMaterialFolder('/Material');
        $courseMapper = $this->createMock(CourseMapper::class);
        $courseMapper->method('findById')->willReturn($course);

        $file = $this->createMock(Node::class);
        $file->method('getType')->willReturn(Node::TYPE_FILE);
        $file->method('getName')->willReturn($name);
        $file->method('getSize')->willReturn(1234);
        $file->method('getId')->willReturn($fileId);
        $folder = $this->createMock(Folder::class);
        $folder->method('getType')->willReturn(Node::TYPE_FOLDER);
        $folder->method('getDirectoryListing')->willReturn([$file]);
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
