<?php
declare(strict_types=1);
namespace OCA\Learning\Tests\Unit\Command;

use OCA\Learning\BackgroundJob\ImportUsersJob;
use OCA\Learning\Command\ImportUsersCommand;
use OCA\Learning\Service\AuditService;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

#[AllowMockObjectsWithoutExpectations]
class ImportUsersCommandTest extends TestCase {
    public function testLargeCsvDispatchesJob(): void {
        $um = $this->createMock(IUserManager::class);
        $gm = $this->createMock(IGroupManager::class);
        $audit = $this->createMock(AuditService::class);
        $jobList = $this->createMock(IJobList::class);
        // Passwords are generated inside the job: nothing but these three keys may be
        // serialized into oc_jobs.argument (a plaintext password there would leak).
        $jobList->expects($this->once())->method('add')->with(
            ImportUsersJob::class,
            $this->callback(fn ($args): bool => is_array($args)
                && array_keys($args) === ['csv_data', 'group', 'admin_uid']),
        );

        $cmd = new ImportUsersCommand($um, $gm, $audit, $jobList);
        // Build a CSV with 51 rows (header + 51 data rows)
        $csvLines = ["username,display_name,email"];
        for ($i = 1; $i <= 51; $i++) {
            $csvLines[] = "user$i,User $i,user$i@example.com";
        }
        $csvContent = implode("\n", $csvLines);
        $base = tempnam(sys_get_temp_dir(), 'test_import_');
        $tmpFile = $base . '.csv';
        file_put_contents($tmpFile, $csvContent);

        $tester = new CommandTester($cmd);
        try {
            $tester->execute(['csv_file' => $tmpFile]);
        } finally {
            unlink($tmpFile);
            unlink($base); // tempnam() itself created this file
        }
    }

    public function testSmallCsvSync(): void {
        $um = $this->createMock(IUserManager::class);
        $um->expects($this->exactly(3))->method('createUser');
        $gm = $this->createMock(IGroupManager::class);
        $audit = $this->createMock(AuditService::class);
        $jobList = $this->createMock(IJobList::class);
        $jobList->expects($this->never())->method('add');

        $cmd = new ImportUsersCommand($um, $gm, $audit, $jobList);
        $csvContent = "username,display_name,email\nuser1,User 1,u1@ex.com\nuser2,User 2,u2@ex.com\nuser3,User 3,u3@ex.com\n";
        $base = tempnam(sys_get_temp_dir(), 'test_import_');
        $tmpFile = $base . '.csv';
        file_put_contents($tmpFile, $csvContent);

        $tester = new CommandTester($cmd);
        try {
            $tester->execute(['csv_file' => $tmpFile]);
        } finally {
            unlink($tmpFile);
            unlink($base); // tempnam() itself created this file
        }
    }
}
