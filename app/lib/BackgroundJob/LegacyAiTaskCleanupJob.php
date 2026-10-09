<?php
declare(strict_types=1);

namespace OCA\Learning\BackgroundJob;

use OCA\Learning\Repair\LegacyAiTaskCleanup;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Deletes the legacy AI tasks that were still running when the app was upgraded to 5.7, once they
 * have finished (spec 4.3). Registered by LegacyAiTaskCleanup::scan() only when there are such
 * tasks; it unregisters itself when none is left. A failed sweep is logged and retried.
 */
class LegacyAiTaskCleanupJob extends TimedJob {
    public function __construct(
        ITimeFactory $time,
        private LegacyAiTaskCleanup $cleanup,
        private IJobList $jobList,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(900); // 15 minutes
    }

    protected function run($argument): void {
        try {
            $left = $this->cleanup->sweepPending();
        } catch (\Throwable $e) {
            $this->logger->warning('Legacy AI task cleanup failed; retried on the next run', ['app' => 'learning', 'exception' => $e]);
            return;
        }
        if ($left === 0) {
            $this->jobList->remove(self::class);
        }
    }
}
