<?php
declare(strict_types=1);

namespace OCA\Learning\BackgroundJob;

use OCA\Learning\Service\RetentionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * RetentionJob — daily DSGVO-03 crypto-erasure job (Phase 164).
 *
 * Fires daily (86400 s). Delegates to RetentionService::anonymizeExpired() which:
 *   - Finds certs with anonymized_at IS NULL AND issued_at older than retention_years
 *   - Nulls user_id, scrubs credential_json (crypto-erasure), sets anonymized_at = now
 *   - Nulls user_id on linked audit_events rows (chain_hash unchanged — DSGVO-01 pattern)
 *
 * ⚠ RETENTION_YEARS_DEFAULT = '3' is FLAGGED for AWO/DSGVO confirmation.
 * ⚠ BetrVG §87 Abs.1 Nr.6 Betriebsvereinbarung required before production rollout.
 *
 * Implementation: 164-07 (run() body is a no-op until then).
 * Registered in Application::boot() alongside existing jobs.
 */
class RetentionJob extends TimedJob {
    public function __construct(
        ITimeFactory $time,
        private readonly RetentionService $svc,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(86400); // daily
    }

    protected function run($argument): void {
        try {
            // impl in 164-07: $this->svc->anonymizeExpired()
        } catch (\Throwable $e) {
            $this->logger->warning(
                'RetentionJob: ' . $e->getMessage(),
                ['app' => 'learning']
            );
        }
    }
}
