<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Command;

use OCA\Learning\Command\ArchiveCourseCommand;
use OCA\Learning\Command\MergeCourseCommand;
use OCA\Learning\Service\CourseArchiveService;
use OCA\Learning\Service\CourseMergeService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Audit #8: occ learning:archive-course / merge-course passed the hard-coded user ID 'admin'
 * into the user permission checks, so they failed on every instance without a user called
 * 'admin' (and a regular user called 'admin' decided the outcome). occ is the system path.
 */
#[AllowMockObjectsWithoutExpectations]
class CourseOccSystemActorTest extends TestCase {

    public function testArchiveCommandUsesTheSystemPath(): void {
        $svc = $this->createMock(CourseArchiveService::class);
        $svc->expects($this->never())->method('archiveCourse');
        $svc->expects($this->once())->method('archiveCourseFromConsole')->with(5, ArchiveCourseCommand::ACTOR)
            ->willReturn(['snapshot_id' => 9, 'course_id' => 5]);

        $tester = new CommandTester(new ArchiveCourseCommand($svc));
        $this->assertSame(0, $tester->execute(['courseId' => '5']));
        $this->assertStringContainsString('Snapshot ID: 9', $tester->getDisplay());
    }

    public function testArchiveExportReadsTheSnapshotOnTheSystemPath(): void {
        $svc = $this->createMock(CourseArchiveService::class);
        $svc->method('archiveCourseFromConsole')->willReturn(['snapshot_id' => 9, 'course_id' => 5]);
        $svc->expects($this->never())->method('getSnapshot');
        $svc->expects($this->once())->method('getSnapshotFromConsole')->with(9)
            ->willReturn(['snapshot' => ['course' => ['id' => 5]]]);

        $file = tempnam(sys_get_temp_dir(), 'snap_');
        $tester = new CommandTester(new ArchiveCourseCommand($svc));
        $status = $tester->execute(['courseId' => '5', '--output' => $file]);
        $json = (string)file_get_contents($file);
        unlink($file);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('"id": 5', $json);
    }

    public function testMergeCommandUsesTheSystemPath(): void {
        $report = [
            'members_copied' => 1, 'members_skipped' => 0, 'items_copied' => 2,
            'items_skipped' => 0, 'snapshot_id' => null,
        ];
        $svc = $this->createMock(CourseMergeService::class);
        $svc->expects($this->never())->method('mergeCourse');
        $svc->expects($this->once())->method('mergeCourseAsSystem')->with(3, 4, true)->willReturn($report);

        $tester = new CommandTester(new MergeCourseCommand($svc));
        $this->assertSame(0, $tester->execute(['sourceCourseId' => '3', 'targetCourseId' => '4', '--dry-run' => true]));
    }
}
