<?php
declare(strict_types=1);
namespace OCA\Learning\AppInfo;

use OCA\Learning\Controller\PageController;
use OCA\Learning\Controller\PoolController;
use OCA\Learning\Controller\QuestionController;
use OCA\Learning\Controller\TrainingController;
use OCA\Learning\Controller\LeitnerController;
use OCA\Learning\Service\PoolService;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\TrainingService;
use OCA\Learning\Service\LeitnerService;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Db\AnswerMapper;
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
        // Controllers auto-registered via routes.php
        // Services auto-injected via Nextcloud DI
    }

    public function boot(IBootContext $context): void {
        // Nothing needed here
    }
}
