<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseDocument;
use OCA\Learning\Db\CourseDocumentMapper;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Service\DocumentService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 2026-11 audit (S14): the material scan treated a file as "already registered" when ANY course had
 * a row with the same relative path. Paths are relative to each instructor's home, so a second
 * course with the same folder name ("Learning/Material") registered nothing, silently.
 */
#[AllowMockObjectsWithoutExpectations]
class DocumentScanCourseScopeTest extends TestCase {

    /**
     * In-memory stand-in for the documents table: each lookup filters the way its SQL would.
     *
     * @param list<CourseDocument> $rows
     */
    private function memoryMapper(array $rows): CourseDocumentMapper {
        return new class($rows) extends CourseDocumentMapper {
            /** @var list<CourseDocument> */
            public array $rows;
            /** @var list<CourseDocument> */
            public array $inserted = [];

            /** @param list<CourseDocument> $rows */
            public function __construct(array $rows) {
                $this->rows = $rows;
            }

            public function findByCourseAndPath(int $courseId, string $filePath): ?CourseDocument {
                foreach ($this->rows as $row) {
                    if ($row->getCourseId() === $courseId && $row->getFilePath() === $filePath) {
                        return $row;
                    }
                }
                return null;
            }

            public function findByCourseId(int $courseId): array {
                return array_values(array_filter($this->rows, static fn ($r) => $r->getCourseId() === $courseId));
            }

            public function insert($entity) {
                $this->inserted[] = $entity;
                $this->rows[] = $entity;
                return $entity;
            }
        };
    }

    private static function doc(int $courseId, string $path): CourseDocument {
        $doc = new CourseDocument();
        $doc->setCourseId($courseId);
        $doc->setFilePath($path);
        return $doc;
    }

    private function service(CourseDocumentMapper $mapper, int $courseId): DocumentService {
        $course = new Course();
        $course->setInstructorId('teacher-b');
        $course->setMaterialFolder('Learning/Material');
        $courseMapper = $this->createMock(CourseMapper::class);
        $courseMapper->method('findById')->with($courseId)->willReturn($course);

        $file = $this->createMock(Node::class);
        $file->method('getType')->willReturn(Node::TYPE_FILE);
        $file->method('getName')->willReturn('skript.pdf');
        $file->method('getSize')->willReturn(1234);
        $folder = $this->createMock(Folder::class);
        $folder->method('getType')->willReturn(Node::TYPE_FOLDER);
        $folder->method('getDirectoryListing')->willReturn([$file]);
        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('get')->with('Learning/Material')->willReturn($folder);
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->with('teacher-b')->willReturn($userFolder);

        return new DocumentService($mapper, $courseMapper, $root, $this->createMock(LoggerInterface::class));
    }

    public function testSamePathInAnotherCourseIsRegisteredForThisCourse(): void {
        $mapper = $this->memoryMapper([self::doc(1, 'Learning/Material/skript.pdf')]);

        $docs = $this->service($mapper, 2)->scanFolder(2, 'teacher-b');

        self::assertCount(1, $mapper->inserted);
        self::assertSame(2, $mapper->inserted[0]->getCourseId());
        self::assertCount(1, $docs);
    }

    public function testSamePathInSameCourseIsNotRegisteredTwice(): void {
        $mapper = $this->memoryMapper([self::doc(2, 'Learning/Material/skript.pdf')]);

        $docs = $this->service($mapper, 2)->scanFolder(2, 'teacher-b');

        self::assertCount(0, $mapper->inserted);
        self::assertCount(1, $docs);
    }

    public function testMapperLookupFiltersOnCourseAndPath(): void {
        $qb = new FakeQueryBuilder();
        $mapper = new CourseDocumentMapper(new FakeDbConnection([$qb]));

        self::assertNull($mapper->findByCourseAndPath(2, 'Learning/Material/skript.pdf'));

        $conditions = json_encode(array_merge($qb->whereCalls, $qb->andWhereCalls));
        self::assertStringContainsString('course_id', (string)$conditions);
        self::assertStringContainsString('file_path', (string)$conditions);
        self::assertSame(1, $qb->maxResults);
    }
}
