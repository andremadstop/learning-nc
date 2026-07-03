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
 * RECERT-04 reconciliation note (locked decision #2): the requirement's literal
 * "revoked_at setzen" is deliberately NOT implemented — SC2 is authoritative. Verify keys on
 * getRevoked(), so leaving revoked=false + expires_at<now yields 'expired'. The close is
 * audited via logComplianceEvent(PERIOD_CLOSED), not via the cert's revoked_at column.
 *
 * Registered in Application::boot() (164-03).
 */
class RecertPeriodCloseJob extends TimedJob {
    // Own reference — the NC Job base class does not expose its time factory to subclasses.
    private readonly ITimeFactory $timeFactory;

    public function __construct(
        ITimeFactory $time,
        private readonly AssignmentService $svc,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->timeFactory = $time;
        $this->setInterval(86400); // daily
    }

    protected function run($argument): void {
        try {
            // closeExpiredPeriods: certs owning their idem slot with expires_at < now-grace →
            // closePeriod per row (CAS-gated, per-row isolated; failed rows retry next run).
            $closed = $this->svc->closeExpiredPeriods($this->timeFactory->getTime());
            if ($closed > 0) {
                $this->logger->info(
                    'RecertPeriodCloseJob: closed ' . $closed . ' expired recertification period(s)',
                    ['app' => 'learning']
                );
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'RecertPeriodCloseJob: ' . $e->getMessage(),
                ['app' => 'learning']
            );
        }
    }
}
