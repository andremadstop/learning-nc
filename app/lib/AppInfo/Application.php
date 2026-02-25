<?php
declare(strict_types=1);
namespace OCA\Learning\AppInfo;

use OCA\Learning\Dashboard\LearningWidget;
use OCA\Learning\Notification\Notifier;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

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
    }
}
