<?php
declare(strict_types=1);
namespace OCA\Learning\AppInfo;

use OCA\Learning\BackgroundJob\ChunkingJob;
use OCA\Learning\BackgroundJob\ConsistencyCheckJob;
use OCA\Learning\BackgroundJob\NotificationJob;
use OCA\Learning\BackgroundJob\WeeklyLernplanJob;
use OCA\Learning\Dashboard\LearningWidget;
use OCA\Learning\Notification\Notifier;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

class Application extends App implements IBootstrap {
    public const APP_ID = 'learning';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerDashboardWidget(LearningWidget::class);
        $context->registerNotifierService(Notifier::class);
    }

    public function boot(IBootContext $context): void {
        $container = $context->getServerContainer();
        $jobList = $container->get(IJobList::class);
        try {
            if (!$jobList->has(NotificationJob::class, null)) {
                $jobList->add(NotificationJob::class);
            }
            if (!$jobList->has(ConsistencyCheckJob::class, null)) {
                $jobList->add(ConsistencyCheckJob::class);
            }
            if (!$jobList->has(WeeklyLernplanJob::class, null)) {
                $jobList->add(WeeklyLernplanJob::class);
            }
            if (!$jobList->has(ChunkingJob::class, null)) {
                $jobList->add(ChunkingJob::class);
            }
        } catch (\Throwable $e) {
            // Duplicate entry is harmless (NC deduplicates), but log unexpected errors (R2 #5)
            $container->get(LoggerInterface::class)->warning(
                'Learning: job registration failed: ' . $e->getMessage(),
                ['app' => 'learning']
            );
        }
    }
}
