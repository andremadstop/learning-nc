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

    /**
     * Audit #9: an existing uid in row 2 of 3 is reported with its reason; row 3 is still
     * created, its email column is stored, and the command exits non-zero.
     */
    public function testFailingRowIsReportedAndLaterRowsAreImported(): void {
        $u1 = new ImportedUserDouble('user1');
        $u3 = new ImportedUserDouble('user3');
        $um = $this->createMock(IUserManager::class);
        $um->expects($this->exactly(3))->method('createUser')->willReturnCallback(
            function (string $uid) use ($u1, $u3) {
                return match ($uid) {
                    'user1' => $u1,
                    'user2' => throw new \InvalidArgumentException('The username is already being used'),
                    default => $u3,
                };
            }
        );
        $cmd = new ImportUsersCommand($um, $this->createMock(IGroupManager::class),
            $this->createMock(AuditService::class), $this->createMock(IJobList::class));
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_import_') . '.csv';
        file_put_contents($tmpFile, "username,display_name,email\nuser1,User 1,not-an-email\nuser2,User 2,u2@ex.com\nuser3,User 3,u3@ex.com\n");

        $tester = new CommandTester($cmd);
        $status = $tester->execute(['csv_file' => $tmpFile]);
        unlink($tmpFile);
        $out = $tester->getDisplay();

        $this->assertStringContainsString('Failed to create user: user2 (The username is already being used)', $out);
        $this->assertStringContainsString('Created user: user3', $out);
        $this->assertStringContainsString('invalid email address ignored', $out);
        $this->assertSame(1, $status);
        $this->assertSame('u3@ex.com', $u3->systemEmail);
        $this->assertNull($u1->systemEmail, 'an invalid address is not stored');
    }
}
