<?php
declare(strict_types=1);

namespace OCA\Learning\BackgroundJob;

use OCA\Learning\Service\AuditCheckpointService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * AuditCheckpointJob — Phase 161 (AUDIT-04).
 *
 * Fires weekly and mints a signed Ed25519 checkpoint over the compliance hash chain. All errors
 * are swallowed (warning log) so a signing/DB failure never propagates to the NC scheduler and
 * never blocks other background jobs — a missed checkpoint is surfaced via getLivenessStatus()
 * (is_overdue) instead.
 */
class AuditCheckpointJob extends TimedJob {
    public function __construct(
        ITimeFactory $time,
        private AuditCheckpointService $service,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(604800); // 7 days
    }

    protected function run($argument): void {
        try {
            $this->service->createCheckpoint();
        } catch (\Throwable $e) {
            $this->logger->warning(
                'AuditCheckpointJob failed: ' . $e->getMessage(),
                ['app' => 'learning']
            );
        }
    }
}
