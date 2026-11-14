<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Repair;

use OCA\Learning\Repair\RemoveRetiredJobs;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Audit #206/#216: the retired NotificationJob was removed by Application::boot() on every request.
 * Its class is deleted; the repair step removes a leftover row once, by class name.
 */
class RemoveRetiredJobsTest extends TestCase {
    public function testALeftoverNotificationJobIsRemoved(): void {
        $jobs = $this->createMock(IJobList::class);
        $jobs->method('has')->willReturnCallback(
            static fn (string $job): bool => $job === 'OCA\Learning\BackgroundJob\NotificationJob'
        );
        $jobs->expects($this->once())->method('remove')->with('OCA\Learning\BackgroundJob\NotificationJob', null);

        (new RemoveRetiredJobs($jobs))->run($this->createMock(IOutput::class));
    }

    public function testNothingIsRemovedWhenTheJobIsGone(): void {
        $jobs = $this->createMock(IJobList::class);
        $jobs->method('has')->willReturn(false);
        $jobs->expects($this->never())->method('remove');

        (new RemoveRetiredJobs($jobs))->run($this->createMock(IOutput::class));
    }

    public function testTheRetiredClassIsReallyGone(): void {
        foreach (RemoveRetiredJobs::RETIRED_JOBS as $job) {
            $this->assertFalse(class_exists($job), "$job is retired; its class must not come back");
        }
    }
}
