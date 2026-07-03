<?php
declare(strict_types=1);

namespace OCA\Learning\BackgroundJob;

use OCA\Learning\Service\AssignmentService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * RecertPeriodCloseJob — daily job that closes expired recertification periods (RECERT-04).
 *
 * Fires daily (86400 s). For each assignment with an open period whose associated cert has
 * expires_at < (now - grace_days), calls AssignmentService::closePeriod(type, id, courseId, certId):
 *   - certId = the specific expired cert row from the job's own expiry query — closePeriod's
 *     CAS gate (UPDATE ... WHERE id = certId AND active_idem_key = idemKey) makes repeat and
 *     stale calls a clean no-op instead of corrupting the next period
 *   - Nulls active_period_key (frees the re-issue slot for the next period)
 *   - Inserts a new assignment row with a fresh active_period_key — all writes + audit in ONE
 *     transaction (a crash mid-close can never strand the subject)
 *
 * SC2 invariant: period-close MUST NOT set revoked=true — that makes the old verify URL
 * return "withdrawn" instead of "expired". Expiry is derived from expires_at < now.
 *
 * Implementation: 164-05 (run() body is a no-op until then).
 * Registered in Application::boot() alongside existing jobs.
 */
class RecertPeriodCloseJob extends TimedJob {
    public function __construct(
        ITimeFactory $time,
        private readonly AssignmentService $svc,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(86400); // daily
    }

    protected function run($argument): void {
        try {
            // impl in 164-05: query expired open periods, call $this->svc->closePeriod() for each
        } catch (\Throwable $e) {
            $this->logger->warning(
                'RecertPeriodCloseJob: ' . $e->getMessage(),
                ['app' => 'learning']
            );
        }
    }
}
