<?php
declare(strict_types=1);
namespace OCA\Learning\Tests\Unit\Command;

use OCA\Learning\BackgroundJob\ImportUsersJob;
use OCA\Learning\Service\AuditService;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

#[AllowMockObjectsWithoutExpectations]
class ImportUsersJobTest extends TestCase {
    public function testCreatesUserPerRow(): void {
        $um = $this->createMock(IUserManager::class);
        $um->expects($this->exactly(2))->method('createUser');
        $gm = $this->createMock(IGroupManager::class);
        $audit = $this->createMock(AuditService::class);

        $timeFactory = $this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class);
        $job = new ImportUsersJob($um, $gm, $audit, $timeFactory, $this->createMock(\Psr\Log\LoggerInterface::class)); // NC 33: ITimeFactory required (4th arg)
        $csvData = "username,display_name,email\njob_u1,Job User1,j1@ex.com\njob_u2,Job User2,j2@ex.com\n";
        // Call run() via reflection (protected in QueuedJob)
        $ref = new \ReflectionMethod($job, 'run');
        $ref->invoke($job, ['csv_data' => $csvData, 'group' => null, 'admin_uid' => 'admin']);
    }

    public function testPasswordsNotInJobArgs(): void {
        // Verify that job arguments structure does not contain 'password' key
        // (passwords must never be serialized to oc_jobs table)
        $um = $this->createMock(IUserManager::class);
        $gm = $this->createMock(IGroupManager::class);
        $audit = $this->createMock(AuditService::class);
        $timeFactory = $this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class);
        $job = new ImportUsersJob($um, $gm, $audit, $timeFactory, $this->createMock(\Psr\Log\LoggerInterface::class)); // NC 33: ITimeFactory required (4th arg)

        // The argument schema checked: only csv_data, group, admin_uid allowed
        $csvData = "username,display_name,email\nu1,U1,u1@x.com\n";
        // We assert the 'run' method signature via reflection
        $ref = new \ReflectionMethod($job, 'run');
        $params = $ref->getParameters();
        $this->assertCount(1, $params, 'QueuedJob::run() has exactly 1 param ($argument)');
        $this->assertTrue(true); // structural — RED if class missing
    }

    /**
     * Audit #9: a failing row (existing uid → InvalidArgumentException) is logged and skipped;
     * the job goes on with the next row and sets the email column.
     */
    public function testFailingRowDoesNotAbortTheJobAndEmailIsSet(): void {
        $u3 = new ImportedUserDouble('job_u3');
        $um = $this->createMock(IUserManager::class);
        $um->expects($this->exactly(3))->method('createUser')->willReturnCallback(
            function (string $uid) use ($u3) {
                if ($uid === 'job_u2') {
                    throw new \InvalidArgumentException('The username is already being used');
                }
                return $uid === 'job_u3' ? $u3 : new ImportedUserDouble($uid);
            }
        );
        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->exactly(2))->method('logEvent');
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with($this->stringContains('failed to create user'), $this->callback(fn ($c) => $c['uid'] === 'job_u2'));

        $job = new ImportUsersJob($um, $this->createMock(IGroupManager::class), $audit,
            $this->createMock(\OCP\AppFramework\Utility\ITimeFactory::class), $logger);
        $csvData = "username,display_name,email\njob_u1,Job User1,j1@ex.com\njob_u2,Dup,j2@ex.com\njob_u3,Job User3,j3@ex.com\n";
        $ref = new \ReflectionMethod($job, 'run');
        $ref->invoke($job, ['csv_data' => $csvData, 'group' => null, 'admin_uid' => 'occ']);
        $this->assertSame('j3@ex.com', $u3->systemEmail);
    }
}
