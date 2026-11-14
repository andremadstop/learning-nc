<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Repair;

use OCA\Learning\Repair\RemoveRetiredBackgroundJobs;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/** Audit #133: the NotificationJob cleanup moved from Application::boot() into a repair step. */
class RemoveRetiredBackgroundJobsTest extends TestCase {

    public function testARegisteredNotificationJobIsRemoved(): void {
        $jobs = $this->createMock(IJobList::class);
        $jobs->method('has')->willReturnCallback(
            static fn (string $job): bool => $job === 'OCA\Learning\BackgroundJob\NotificationJob'
        );
        $jobs->expects($this->once())->method('remove')
            ->with('OCA\Learning\BackgroundJob\NotificationJob', null);

        (new RemoveRetiredBackgroundJobs($jobs))->run($this->createStub(IOutput::class));
    }

    public function testNothingIsRemovedWhenTheJobIsNotRegistered(): void {
        $jobs = $this->createMock(IJobList::class);
        $jobs->method('has')->willReturn(false);
        $jobs->expects($this->never())->method('remove');

        (new RemoveRetiredBackgroundJobs($jobs))->run($this->createStub(IOutput::class));
    }

    public function testItRunsAfterMigrationsOnly(): void {
        $info = simplexml_load_file(__DIR__ . '/../../../appinfo/info.xml');
        $this->assertNotFalse($info);
        $step = RemoveRetiredBackgroundJobs::class;
        $this->assertContains($step, array_map('strval', $info->xpath('/info/repair-steps/post-migration/step') ?: []));
        $this->assertNotContains($step, array_map('strval', $info->xpath('/info/repair-steps/install/step') ?: []));
    }
}
