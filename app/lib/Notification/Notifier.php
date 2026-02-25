<?php
declare(strict_types=1);
namespace OCA\Learning\Notification;

use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

class Notifier implements INotifier {
    private IFactory $l10nFactory;
    private IURLGenerator $urlGenerator;

    public function __construct(IFactory $l10nFactory, IURLGenerator $urlGenerator) {
        $this->l10nFactory = $l10nFactory;
        $this->urlGenerator = $urlGenerator;
    }

    public function getID(): string {
        return 'learning';
    }

    public function getName(): string {
        return $this->l10nFactory->get('learning')->t('Learning');
    }

    public function prepare(INotification $notification, string $languageCode): INotification {
        if ($notification->getApp() !== 'learning') {
            throw new \InvalidArgumentException('Wrong app');
        }

        $l = $this->l10nFactory->get('learning', $languageCode);
        $appUrl = $this->urlGenerator->linkToRouteAbsolute('learning.page.index');

        switch ($notification->getSubject()) {
            case 'badge_earned':
                $params = $notification->getSubjectParameters();
                $badgeName = $params['badge_name'] ?? '';
                $badgeEmoji = $params['badge_emoji'] ?? '';
                $notification->setParsedSubject(
                    $l->t('Achievement unlocked: %s %s', [$badgeEmoji, $badgeName])
                );
                $notification->setLink($appUrl);
                $notification->setIcon($this->urlGenerator->imagePath('learning', 'app.svg'));
                break;

            case 'streak_warning':
                $params = $notification->getSubjectParameters();
                $days = $params['streak_days'] ?? 0;
                $notification->setParsedSubject(
                    $l->t('Don\'t break your %s-day streak! Review some cards today.', [(string)$days])
                );
                $notification->setLink($appUrl);
                $notification->setIcon($this->urlGenerator->imagePath('learning', 'app.svg'));
                break;

            case 'due_reminder':
                $params = $notification->getSubjectParameters();
                $dueCount = $params['due_count'] ?? 0;
                $notification->setParsedSubject(
                    $l->t('You have %s cards waiting for review', [(string)$dueCount])
                );
                $notification->setLink($appUrl);
                $notification->setIcon($this->urlGenerator->imagePath('learning', 'app.svg'));
                break;

            default:
                throw new \InvalidArgumentException('Unknown subject');
        }

        return $notification;
    }
}
