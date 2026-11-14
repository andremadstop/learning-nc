<?php
declare(strict_types=1);

namespace OCA\Learning\Repair;

use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Removes background jobs of this app that no longer exist (post-migration only: a fresh install
 * never had them). Until 5.10 Application::boot() did this on every request (audit #206).
 *
 * NotificationJob was replaced by SendRemindersJob/ReminderService and its class is gone (audit
 * #216), so it is named as a string. UninstallCommand::APP_JOBS keeps the same string for an
 * installation that is removed before it ever ran this step.
 */
class RemoveRetiredJobs implements IRepairStep {
    public const RETIRED_JOBS = [
        'OCA\Learning\BackgroundJob\NotificationJob',
    ];

    public function __construct(
        private IJobList $jobList,
    ) {
    }

    public function getName(): string {
        return 'Remove retired Learning background jobs';
    }

    public function run(IOutput $output): void {
        foreach (self::RETIRED_JOBS as $job) {
            // The class is gone, so it is no class-string; IJobList matches the stored name as text.
            if ($this->jobList->has($job, null)) { // @phpstan-ignore argument.type
                $this->jobList->remove($job, null); // @phpstan-ignore argument.type
                $output->info('Removed retired background job ' . $job);
            }
        }
    }
}
