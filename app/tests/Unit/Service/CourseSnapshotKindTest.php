<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\RagChunkMapper;
use OCA\Learning\Migration\Version010600Date20261114093000;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\CourseArchiveService;
use OCA\Learning\Service\CourseSummaryService;
use OCA\Learning\Service\DataMobilityService;
use OCA\Learning\Service\GeminiService;
use OCA\Learning\Service\RoleService;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Audit #51: learning_course_snapshots holds course archives and learners' personal summaries.
 * The archive panel listed the summaries as archives, and an instructor who archived the course
 * got the export back as their summary. Every writer sets snapshot_kind, every reader filters it.
 */
#[AllowMockObjectsWithoutExpectations]
class CourseSnapshotKindTest extends TestCase {
    private const KIND_FILTER_FIELD = 'snapshot_kind';

    private function archiveService(FakeDbConnection $db): CourseArchiveService {
        $course = new Course();
        $course->setId(7);
        $course->setInstructorId('teacher');
        $courseMapper = $this->createMock(CourseMapper::class);
        $courseMapper->method('findById')->willReturn($course);
        $mobility = $this->createMock(DataMobilityService::class);
        $mobility->method('exportCourse')->willReturn(['course' => ['id' => 7], 'pools' => [], 'members' => []]);
        $roles = $this->createMock(RoleService::class);
        $roles->method('isAdmin')->willReturn(true);
        return new CourseArchiveService(
            $courseMapper,
            $this->createMock(CourseMemberMapper::class),
            $mobility,
            $roles,
            $db,
            $this->createMock(LoggerInterface::class)
        );
    }

    /** @return mixed the value the query compared snapshot_kind with, or null when unfiltered */
    private function kindFilter(FakeQueryBuilder $qb): mixed {
        foreach (array_merge($qb->whereCalls, $qb->andWhereCalls) as $condition) {
            if (is_array($condition) && ($condition['type'] ?? null) === 'eq' && ($condition['left'] ?? null) === self::KIND_FILTER_FIELD) {
                return $condition['right']['value'] ?? null;
            }
        }
        return null;
    }

    public function testSummaryWriterAndReaderUseTheSummaryKind(): void {
        $insert = new FakeQueryBuilder(new FakeResult(), 1, 5);
        $read = new FakeQueryBuilder(FakeResult::fromFetch(false));
        // createSnapshot() assembles the summary first; only the INSERT and the read matter here.
        $partial = $this->getMockBuilder(CourseSummaryService::class)
            ->setConstructorArgs([
                new FakeDbConnection([$insert, $read]),
                $this->createMock(CourseMemberMapper::class),
                $this->createMock(BadgeService::class),
                $this->createMock(StreakService::class),
                $this->createMock(RagChunkMapper::class),
                $this->createMock(GeminiService::class),
            ])
            ->onlyMethods(['getStudentSummary'])
            ->getMock();
        $partial->method('getStudentSummary')->willReturn(['mastery' => []]);

        $partial->createSnapshot(7, 'alice');
        $this->assertSame('summary', $insert->insertValues['snapshot_kind']['value'] ?? null);

        $this->assertNull($partial->getSnapshot(7, 'alice'));
        $this->assertSame('summary', $this->kindFilter($read), 'an archive row is never read as a summary');
    }

    public function testArchiveWriterAndReadersUseTheArchiveKind(): void {
        $insert = new FakeQueryBuilder(new FakeResult(), 1, 9);
        $list = new FakeQueryBuilder(FakeResult::fromFetchAll([]));
        $get = new FakeQueryBuilder(FakeResult::fromFetch(false));
        $service = $this->archiveService(new FakeDbConnection([$insert, $list, $get]));

        $service->archiveCourse(7, 'admin');
        $this->assertSame('archive', $insert->insertValues['snapshot_kind']['value'] ?? null);

        $this->assertSame([], $service->listSnapshots(7, 'admin'));
        $this->assertSame('archive', $this->kindFilter($list), 'learners\' summaries are no archives');

        $this->assertNull($service->getSnapshot(5, 'admin'));
        $this->assertSame('archive', $this->kindFilter($get));
    }

    public function testMigrationClassifiesExistingArchivesByTheirExportShape(): void {
        $update = new FakeQueryBuilder(new FakeResult(), 3);
        $db = new FakeDbConnection([$update]);

        $count = (new Version010600Date20261114093000($db))->classifyExistingArchives();

        $this->assertSame(3, $count);
        $this->assertSame('update', $update->operation);
        $this->assertSame('learning_course_snapshots', $update->table);
        $this->assertSame('archive', $update->setCalls['snapshot_kind']['value'] ?? null);
        $like = $update->whereCalls[0];
        $this->assertSame('like', $like['type']);
        $this->assertSame('snapshot_data', $like['left']);
        $this->assertSame('{"course":%', $like['right']['value']);
        // A summary is JSON starting with "mastery" and never matches.
        $this->assertStringStartsNotWith('{"course":', (string)json_encode(['mastery' => [], 'sessions' => []]));
        $this->assertStringStartsWith('{"course":', (string)json_encode(['course' => [], 'pools' => [], 'members' => []]));
    }
}
