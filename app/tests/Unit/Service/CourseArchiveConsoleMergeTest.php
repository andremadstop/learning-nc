<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Command\ArchiveCourseCommand;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMember;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CoursePoolMapper;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Service\CourseArchiveService;
use OCA\Learning\Service\CourseMergeService;
use OCA\Learning\Service\DataMobilityService;
use OCA\Learning\Service\RoleService;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Audit #135: occ learning:archive-course archived as the user 'admin' and failed the course
 * permission check on every instance without an admin of that name.
 *
 * Audit #153: the merge archived its source course before its transaction, so a failed merge left
 * the course archived; and 'stats_merged' counted users without merging anything.
 */
#[AllowMockObjectsWithoutExpectations]
final class CourseArchiveConsoleMergeTest extends TestCase {
    public function testArchiveCommandNeedsNoUserNamedAdmin(): void {
        $archive = $this->createMock(CourseArchiveService::class);
        $archive->expects($this->never())->method('archiveCourse');
        $archive->expects($this->once())->method('archiveCourseFromConsole')
            ->with(7, ArchiveCourseCommand::ACTOR)
            ->willReturn(['snapshot_id' => 3, 'course_id' => 7]);

        $code = (new CommandTester(new ArchiveCourseCommand($archive)))->execute(['courseId' => '7']);

        $this->assertSame(Command::SUCCESS, $code);
    }

    public function testConsoleArchiveSkipsTheCoursePermissionCheck(): void {
        $course = new Course();
        $course->setId(7);
        $course->setInstructorId('teacher');
        $courseMapper = $this->createMock(CourseMapper::class);
        $courseMapper->method('findById')->willReturn($course);
        $courseMapper->expects($this->once())->method('update')
            ->with($this->callback(fn (Course $c) => $c->getStatus() === 'archived'));

        $roles = $this->createMock(RoleService::class);
        $roles->expects($this->never())->method('isAdmin');

        $db = new class implements IDBConnection {
            public function getQueryBuilder() {
                return new class {
                    public function __call(string $name, array $args): mixed {
                        return $name === 'executeStatement' ? 1 : $this;
                    }
                };
            }

            public function lastInsertId(string $table): int {
                return 3;
            }

            public function executeQuery(string $sql, array $params = []) {
                return null;
            }

            public function escapeLikeParameter(string $input): string {
                return $input;
            }

            public function beginTransaction(): void {
            }

            public function commit(): void {
            }

            public function rollBack(): void {
            }

            public function inTransaction(): bool {
                return false;
            }
        };

        $mobility = $this->createMock(DataMobilityService::class);
        $mobility->method('exportCourse')->willReturn(['course' => ['id' => 7]]);

        $service = new CourseArchiveService(
            $courseMapper,
            $this->createMock(CourseMemberMapper::class),
            $mobility,
            $roles,
            $db,
            $this->createMock(LoggerInterface::class)
        );

        $this->assertSame(['snapshot_id' => 3, 'course_id' => 7], $service->archiveCourseFromConsole(7, 'occ'));
    }

    public function testAFailedMergeLeavesTheSourceCourseActive(): void {
        $source = new Course();
        $source->setId(1);
        $source->setStatus('active');
        $target = new Course();
        $target->setId(2);
        $courseMapper = $this->createMock(CourseMapper::class);
        $courseMapper->method('findById')->willReturnMap([[1, $source], [2, $target]]);

        $member = new CourseMember();
        $member->setUserId('bob');
        $member->setRole('student');
        $memberMapper = $this->createMock(CourseMemberMapper::class);
        $memberMapper->method('findByCourse')->willReturnMap([[1, [$member]], [2, []]]);
        $memberMapper->method('insert')->willThrowException(new \RuntimeException('unique violation'));

        $pools = $this->createMock(CoursePoolMapper::class);
        $pools->method('findByCourse')->willReturn([]);

        $archive = $this->createMock(CourseArchiveService::class);
        $archive->expects($this->never())->method('archiveCourse');
        $archive->expects($this->once())->method('snapshotCourse')->with(1, 'teacher')->willReturn(9);
        $archive->expects($this->never())->method('markArchived');

        $roles = $this->createMock(RoleService::class);
        $roles->method('isInstructor')->willReturn(true);

        $db = $this->createMock(IDBConnection::class);
        $db->expects($this->once())->method('beginTransaction');
        $db->expects($this->once())->method('rollBack');
        $db->expects($this->never())->method('commit');

        $service = new CourseMergeService(
            $courseMapper,
            $memberMapper,
            $pools,
            $this->createMock(PoolMapper::class),
            $archive,
            $roles,
            $db,
            $this->createMock(LoggerInterface::class)
        );

        try {
            $service->mergeCourse(1, 2, 'teacher');
            $this->fail('the merge must fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('unique violation', $e->getMessage());
        }
    }

    public function testASuccessfulMergeArchivesTheSourceInsideTheTransaction(): void {
        $source = new Course();
        $source->setId(1);
        $target = new Course();
        $target->setId(2);
        $courseMapper = $this->createMock(CourseMapper::class);
        $courseMapper->method('findById')->willReturnMap([[1, $source], [2, $target]]);

        $memberMapper = $this->createMock(CourseMemberMapper::class);
        $memberMapper->method('findByCourse')->willReturn([]);
        $pools = $this->createMock(CoursePoolMapper::class);
        $pools->method('findByCourse')->willReturn([]);

        $calls = [];
        $archive = $this->createMock(CourseArchiveService::class);
        $archive->method('snapshotCourse')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'snapshot';
            return 9;
        });
        $archive->method('markArchived')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'archived';
        });
        $roles = $this->createMock(RoleService::class);
        $roles->method('isInstructor')->willReturn(true);
        $db = $this->createMock(IDBConnection::class);
        $db->method('beginTransaction')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'begin';
        });
        $db->method('commit')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'commit';
        });

        $report = (new CourseMergeService(
            $courseMapper,
            $memberMapper,
            $pools,
            $this->createMock(PoolMapper::class),
            $archive,
            $roles,
            $db,
            $this->createMock(LoggerInterface::class)
        ))->mergeCourse(1, 2, 'teacher');

        $this->assertSame(['snapshot', 'begin', 'archived', 'commit'], $calls);
        $this->assertSame(9, $report['snapshot_id']);
        $this->assertArrayNotHasKey('stats_merged', $report);
    }
}
