<?php
declare(strict_types=1);
namespace OCA\Learning\AppInfo;

use OCA\Learning\BackgroundJob\ChunkingJob;
use OCA\Learning\BackgroundJob\ConsistencyCheckJob;
use OCA\Learning\BackgroundJob\NotificationJob;
use OCA\Learning\BackgroundJob\SendRemindersJob;
use OCA\Learning\BackgroundJob\WeeklyLernplanJob;
use OCA\Learning\Command\ImportVaultCommand;
use OCA\Learning\Dashboard\LearningWidget;
use OCA\Learning\Db\RagChunkMapper;
use OCA\Learning\Listener\UserDeletedListener;
use OCA\Learning\Notification\Notifier;
use OCA\Learning\Settings\LegalNoticeHelpSettings;
use OCA\Learning\Settings\PrivacyHelpSettings;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;
use OCP\IConfig;
use OCP\IURLGenerator;
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
        $context->registerService(ImportVaultCommand::class, function (ContainerInterface $container): ImportVaultCommand {
            return new ImportVaultCommand(
                $container->get(RagChunkMapper::class),
                $container->get(LoggerInterface::class)
            );
        });
    }

    public function boot(IBootContext $context): void {
        $container = $context->getServerContainer();
        $this->registerHelpSettings($container);
        $this->syncThemingLegalLinks($container);
        $jobList = $container->get(IJobList::class);
        try {
            if ($jobList->has(NotificationJob::class, null)) {
                $jobList->remove(NotificationJob::class, null);
            }
            if (!$jobList->has(SendRemindersJob::class, null)) {
                $jobList->add(SendRemindersJob::class);
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

    private function syncThemingLegalLinks(ContainerInterface $container): void {
        try {
            $config = $container->get(IConfig::class);
            $urlGenerator = $container->get(IURLGenerator::class);

            $desiredPrivacyUrl = $urlGenerator->linkToRouteAbsolute('learning.page.privacy');
            $desiredImprintUrl = $urlGenerator->linkToRouteAbsolute('learning.page.impressum');

            $currentPrivacyUrl = $config->getAppValue('theming', 'privacyUrl', '');
            $currentImprintUrl = $config->getAppValue('theming', 'imprintUrl', '');

            if (($currentPrivacyUrl === '' || str_contains($currentPrivacyUrl, '/apps/learning/privacy'))
                && $currentPrivacyUrl !== $desiredPrivacyUrl) {
                $config->setAppValue('theming', 'privacyUrl', $desiredPrivacyUrl);
            }

            if (($currentImprintUrl === '' || str_contains($currentImprintUrl, '/apps/learning/impressum'))
                && $currentImprintUrl !== $desiredImprintUrl) {
                $config->setAppValue('theming', 'imprintUrl', $desiredImprintUrl);
            }
        } catch (\Throwable $e) {
            $container->get(LoggerInterface::class)->warning(
                'Learning: legal link sync failed: ' . $e->getMessage(),
                ['app' => self::APP_ID]
            );
        }
    }
}
