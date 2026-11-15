<?php
declare(strict_types=1);

namespace OCA\Learning\BackgroundJob;

use OCA\Learning\Service\CertLayoutService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * GitHub #43: deletes stamp and signature images that neither a course layout nor any issued
 * certificate uses any more, once the course has been unchanged for an hour. Deleting them on the
 * change itself could race an issuance that already read the old layout
 * (CertLayoutService::sweepUnreferencedAssets).
 */
class CertAssetCleanupJob extends TimedJob {
    public function __construct(
        ITimeFactory $time,
        private readonly CertLayoutService $service,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(86400); // daily
    }

    protected function run($argument): void {
        try {
            $deleted = $this->service->sweepUnreferencedAssets();
            if ($deleted > 0) {
                $this->logger->info('CertAssetCleanupJob: deleted ' . $deleted . ' unused certificate image(s)', ['app' => 'learning']);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('CertAssetCleanupJob: ' . $e->getMessage(), ['app' => 'learning']);
        }
    }
}
