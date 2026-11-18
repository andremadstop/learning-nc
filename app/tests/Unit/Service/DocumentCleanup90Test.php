<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseDocument;
use OCA\Learning\Db\CourseDocumentMapper;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseVideo;
use OCA\Learning\Db\CourseVideoMapper;
use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\ComplianceEventTypes;
use OCA\Learning\Service\DocumentService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #90: "Scan folder" cleans up. An entry whose file was deleted, or that belongs to an
 * earlier material folder, used to stay in the list for good: neither the scan nor the
 * instructor could take it out. Now the scan marks it (removed_at) and leaves the row and its
 * confirmations in place; the same file back in the folder brings the same row back. Whether a
 * required document holds the exam back changes with it, so that is in the compliance chain.
 */
#[AllowMockObjectsWithoutExpectations]
class DocumentCleanup90Test extends TestCase {
    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    private array $events = [];

    /** @param list<CourseDocument> $rows */
    private function memoryMapper(array $rows): CourseDocumentMapper {
        return new class($rows) extends CourseDocumentMapper {
            /** @var list<CourseDocument> */
            public array $inserted = [];

            /** @param list<CourseDocument> $rows */
            public function __construct(public array $rows) {
            }

            public function findByCourseId(int $courseId): array {
                return array_values(array_filter($this->rows, static fn ($r) => $r->getCourseId() === $courseId));
            }

            public function insert($entity) {
                $entity->setId(100 + count($this->inserted));
                $this->inserted[] = $entity;
                $this->rows[] = $entity;
                return $entity;
            }

            public function update($entity) {
                return $entity;
            }
        };
    }

    /**
     * @param list<array{0: string, 1: int}> $listing name and file id of the files in $folder now
     * @param array<int, CourseVideo> $required document id => required-content row
     */
    private function scan(CourseDocumentMapper $mapper, array $listing, string $folderPath = '/Material', array $required = []): void {
        $course = new Course();
        $course->setId(4);
        $course->setInstructorId('teacher');
        $course->setMaterialFolder($folderPath);
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

        $videos = $this->createMock(CourseVideoMapper::class);
        $videos->method('findDocumentRowsByCourse')->with(4, true)->willReturn($required);
        $audit = $this->createMock(AuditService::class);
        $audit->method('logComplianceEvent')->willReturnCallback(function (string $key, string $uid, array $context): void {
            $this->events[] = [$key, $uid, $context];
        });
        $db = $this->createMock(IDBConnection::class);

        (new DocumentService($mapper, $courseMapper, $root, $this->createMock(LoggerInterface::class), $videos, $audit, $db))
            ->scanFolder(4, 'teacher');
    }

    private static function row(int $id, string $name, ?int $fileId, string $folder = '/Material'): CourseDocument {
        $doc = new CourseDocument();
        $doc->setId($id);
        $doc->setCourseId(4);
        $doc->setFilePath($folder . '/' . $name);
        $doc->setFileName($name);
        $doc->setFileType('pdf');
        $doc->setFileId($fileId);
        return $doc;
    }

    private static function requiredRow(int $id, int $documentId): CourseVideo {
        $video = new CourseVideo();
        $video->setId($id);
        $video->setCourseId(4);
        $video->setSourceType('document');
        $video->setVideoRef((string)$documentId);
        $video->setDocumentId($documentId);
        return $video;
    }

    public function testADeletedFileLeavesTheListOnTheNextScan(): void {
        $kept = self::row(5, 'Kept.pdf', 77);
        $deleted = self::row(6, 'Deleted.pdf', 78);
        $mapper = $this->memoryMapper([$kept, $deleted]);

        $this->scan($mapper, [['Kept.pdf', 77]]);

        self::assertNull($kept->getRemovedAt());
        self::assertNotNull($deleted->getRemovedAt(), 'the entry of the deleted file leaves the list');
        self::assertContains($deleted, $mapper->rows, 'the row (and so its confirmations) stays');
        self::assertSame([], $this->events, 'an optional file changes nothing about the exam');
    }

    public function testAfterLinkingAnotherFolderTheOldFolderLeavesTheList(): void {
        $old = self::row(5, 'Old.pdf', 77, '/Old');
        $mapper = $this->memoryMapper([$old]);

        $this->scan($mapper, [['New.pdf', 80]], '/New');

        self::assertNotNull($old->getRemovedAt());
        self::assertCount(1, $mapper->inserted);
        self::assertNull($mapper->inserted[0]->getRemovedAt());
    }

    public function testTheSameFileBackBringsTheSameRowBack(): void {
        $doc = self::row(5, 'Policy.pdf', 77);
        $mapper = $this->memoryMapper([$doc]);

        $this->scan($mapper, []);
        self::assertNotNull($doc->getRemovedAt());

        // restored from the trash (same file id), even under another name
        $this->scan($mapper, [['Policy (restored).pdf', 77]]);
        self::assertNull($doc->getRemovedAt(), 'the row is listed again');
        self::assertSame([], $mapper->inserted, 'no new row: the confirmations belong to this one');
        self::assertSame('/Material/Policy (restored).pdf', $doc->getFilePath());
    }

    public function testLinkingTheOldFolderAgainBringsItsEntriesBack(): void {
        $doc = self::row(5, 'Policy.pdf', 77, '/Old');
        $mapper = $this->memoryMapper([$doc]);

        $this->scan($mapper, [['Other.pdf', 90]], '/New');
        self::assertNotNull($doc->getRemovedAt());

        $this->scan($mapper, [['Policy.pdf', 77]], '/Old');
        self::assertNull($doc->getRemovedAt());
    }

    public function testARequiredDocumentLeavingAndComingBackIsInTheComplianceChain(): void {
        $doc = self::row(5, 'Policy.pdf', 77);
        $mapper = $this->memoryMapper([$doc]);
        $required = [5 => self::requiredRow(31, 5)];

        $this->scan($mapper, [], '/Material', $required);
        $this->scan($mapper, [['Policy.pdf', 77]], '/Material', $required);

        self::assertSame([
            [ComplianceEventTypes::CONTENT_REQUIRED_CHANGED, 'teacher', ['course_id' => 4, 'required' => false, 'reason' => 'document_removed', 'document_ids' => [5], 'content_ids' => [31]]],
            [ComplianceEventTypes::CONTENT_REQUIRED_CHANGED, 'teacher', ['course_id' => 4, 'required' => true, 'reason' => 'document_back', 'document_ids' => [5], 'content_ids' => [31]]],
        ], $this->events);
    }

    public function testAScanThatChangesNothingWritesNothing(): void {
        $doc = self::row(5, 'Policy.pdf', 77);
        $mapper = $this->memoryMapper([$doc]);

        $this->scan($mapper, [['Policy.pdf', 77]], '/Material', [5 => self::requiredRow(31, 5)]);
        $this->scan($mapper, [['Policy.pdf', 77]], '/Material', [5 => self::requiredRow(31, 5)]);

        self::assertNull($doc->getRemovedAt());
        self::assertSame([], $this->events);
    }

    /**
     * GitHub #75 (review L1) meets #90: "b.pdf" was deleted and "a.pdf" renamed to "b.pdf". The
     * row of a.pdf follows its file; the row of the deleted b.pdf leaves the list instead of
     * staying behind as a dead entry.
     */
    public function testTheRowLeftBehindByARenameOntoItsNameLeavesTheList(): void {
        $a = self::row(5, 'a.pdf', 100);
        $b = self::row(6, 'b.pdf', 200);
        $mapper = $this->memoryMapper([$a, $b]);

        $this->scan($mapper, [['b.pdf', 100]]);

        self::assertNull($a->getRemovedAt());
        self::assertSame('/Material/b.pdf', $a->getFilePath());
        self::assertNotNull($b->getRemovedAt());
    }
}
