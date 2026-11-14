<?php
declare(strict_types=1);

namespace OCA\Learning\Repair;

use OCA\Learning\BackgroundJob\NotificationJob;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Removes background jobs the app no longer runs from the job list (<post-migration>; a fresh
 * install never had them). Application::boot() used to do this on every request until the jobs
 * moved to appinfo/info.xml (audit #133). NotificationJob was replaced by SendRemindersJob; its
 * class stays so that an old row still resolves until this step has run.
 */
class RemoveRetiredBackgroundJobs implements IRepairStep {
    /** @var list<class-string> */
    public const RETIRED = [NotificationJob::class];

    public function __construct(
        private IJobList $jobList,
    ) {
    }

    public function getName(): string {
        return 'Remove retired Learning background jobs';
    }

    public function run(IOutput $output): void {
        foreach (self::RETIRED as $job) {
            if ($this->jobList->has($job, null)) {
                $this->jobList->remove($job, null);
                $output->info('Removed retired background job ' . $job);
            }
        }
    }
}
