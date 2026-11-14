<?php
declare(strict_types=1);
namespace OCA\Learning\AppInfo;

use OCA\Learning\Command\ArchiveCourseCommand;
use OCA\Learning\Command\AuditVerifyCommand;
use OCA\Learning\Command\ExportCourseCommand;
use OCA\Learning\Command\ExportPoolCommand;
use OCA\Learning\Command\ImportPoolJsonCommand;
use OCA\Learning\Command\ImportUsersCommand;
use OCA\Learning\Command\ImportVaultCommand;
use OCA\Learning\Command\InitIssuerCommand;
use OCA\Learning\Command\MergeCourseCommand;
use OCA\Learning\Service\CourseArchiveService;
use OCA\Learning\Service\CourseMergeService;
use OCA\Learning\Dashboard\LearningWidget;
use OCA\Learning\Db\RagChunkMapper;
use OCA\Learning\Service\DataMobilityService;
use OCA\Learning\Service\OperatorInfo;
use OCA\Learning\Service\ThemingLegalLinkSync;
use OCA\Learning\Listener\UserDeletedListener;
use OCA\Learning\Notification\Notifier;
use OCA\Learning\Search\PoolSearchProvider;
use OCA\Learning\Search\QuestionSearchProvider;
use OCA\Learning\Settings\LegalNoticeHelpSettings;
use OCA\Learning\Settings\PrivacyHelpSettings;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Settings\IManager as SettingsManager;
use OCP\User\Events\UserDeletedEvent;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class Application extends App implements IBootstrap {
    public const APP_ID = 'learning';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerDashboardWidget(LearningWidget::class);
        $context->registerNotifierService(Notifier::class);
        $context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
        $context->registerSearchProvider(PoolSearchProvider::class);
        $context->registerSearchProvider(QuestionSearchProvider::class);
        $context->registerService(ImportVaultCommand::class, function (ContainerInterface $container): ImportVaultCommand {
            return new ImportVaultCommand(
                $container->get(RagChunkMapper::class),
                $container->get(LoggerInterface::class)
            );
        });
        $context->registerService(ExportPoolCommand::class, function (ContainerInterface $container): ExportPoolCommand {
            return new ExportPoolCommand(
                $container->get(DataMobilityService::class)
            );
        });
        $context->registerService(ImportPoolJsonCommand::class, function (ContainerInterface $container): ImportPoolJsonCommand {
            // English output, whatever the instance's default language is.
            return new ImportPoolJsonCommand(
                $container->get(\OCA\Learning\Service\PoolBulkImportService::class),
                $container->get(\OCP\L10N\IFactory::class)->get('learning', 'en')
            );
        });
        $context->registerService(ExportCourseCommand::class, function (ContainerInterface $container): ExportCourseCommand {
            return new ExportCourseCommand(
                $container->get(DataMobilityService::class)
            );
        });
        $context->registerService(ArchiveCourseCommand::class, function (ContainerInterface $container): ArchiveCourseCommand {
            return new ArchiveCourseCommand(
                $container->get(CourseArchiveService::class)
            );
        });
        $context->registerService(MergeCourseCommand::class, function (ContainerInterface $container): MergeCourseCommand {
            return new MergeCourseCommand(
                $container->get(CourseMergeService::class)
            );
        });
        $context->registerService(InitIssuerCommand::class, function (ContainerInterface $container): InitIssuerCommand {
            return new InitIssuerCommand(
                $container->get(\OCA\Learning\Service\KeyService::class)
            );
        });
        $context->registerService(ImportUsersCommand::class, function (ContainerInterface $container): ImportUsersCommand {
            return new ImportUsersCommand(
                $container->get(IUserManager::class),
                $container->get(IGroupManager::class),
                $container->get(\OCA\Learning\Service\AuditService::class),
                $container->get(IJobList::class),
            );
        });
        $context->registerService(AuditVerifyCommand::class, function (ContainerInterface $container): AuditVerifyCommand {
            return new AuditVerifyCommand(
                $container->get(\OCP\IDBConnection::class),
                $container->get(\OCA\Learning\Db\CertKeyMapper::class),
                $container->get(\OCP\Http\Client\IClientService::class),
            );
        });
    }

    public function boot(IBootContext $context): void {
        $container = $context->getServerContainer();
        $this->registerHelpSettings($container);
        $this->syncThemingLegalLinks($container);
        // Background jobs are declared in appinfo/info.xml; Nextcloud registers them on install and
        // on every app upgrade. They used to be added here on every boot, i.e. 8 job-list queries
        // per request (audit #133). The retired NotificationJob is removed by the repair step
        // RemoveRetiredBackgroundJobs.
    }

    private function registerHelpSettings(ContainerInterface $container): void {
        try {
            $manager = $container->get(SettingsManager::class);
            $manager->registerSetting(SettingsManager::SETTINGS_PERSONAL, PrivacyHelpSettings::class);
            $manager->registerSetting(SettingsManager::SETTINGS_PERSONAL, LegalNoticeHelpSettings::class);
        } catch (\Throwable $e) {
            $container->get(LoggerInterface::class)->warning(
                'Learning: help settings registration failed: ' . $e->getMessage(),
                ['app' => self::APP_ID]
            );
        }
    }

    /**
     * Takes over the Nextcloud imprint/privacy links only once an operator is configured, never
     * over a link set elsewhere (audit 2026-11; logic in ThemingLegalLinkSync).
     */
    private function syncThemingLegalLinks(ContainerInterface $container): void {
        try {
            $config = $container->get(IConfig::class);
            (new ThemingLegalLinkSync(
                $config,
                $container->get(IURLGenerator::class),
                new OperatorInfo($config),
            ))->sync();
        } catch (\Throwable $e) {
            $container->get(LoggerInterface::class)->warning(
                'Learning: legal link sync failed: ' . $e->getMessage(),
                ['app' => self::APP_ID]
            );
        }
    }
}
