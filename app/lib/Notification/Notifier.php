<?php
declare(strict_types=1);
namespace OCA\Learning\Notification;

use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\UnknownNotificationException;
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
            throw new UnknownNotificationException('Wrong app');
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
                $notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('learning', 'app.svg')));
                break;

            case 'streak_warning':
                $params = $notification->getSubjectParameters();
                $days = $params['streak_days'] ?? 0;
                $notification->setParsedSubject(
                    $l->t('Your %s-day streak expires today!', [(string)$days])
                );
                $notification->setLink($appUrl);
                $notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('learning', 'app.svg')));
                break;

            case 'due_cards':
            case 'due_reminder':
                $params = $notification->getSubjectParameters();
                $dueCount = $params['due_count'] ?? 0;
                $notification->setParsedSubject(
                    $l->t('You have %s due cards. Time to study!', [(string)$dueCount])
                );
                $notification->setLink($appUrl);
                $notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('learning', 'app.svg')));
                break;

            case 'exam_reminder':
                $params = $notification->getSubjectParameters();
                $days = max(1, (int)($params['days'] ?? 0));
                $courseTitle = (string)($params['course_title'] ?? '');
                $notification->setParsedSubject(
                    $l->n('Exam in %n day: %s', 'Exam in %n days: %s', $days, [$courseTitle])
                );
                $notification->setLink($appUrl);
                $notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('learning', 'app.svg')));
                break;

            case 'certificate_issued':
                $params = $notification->getSubjectParameters();
                $courseTitle = (string)($params['course_title'] ?? '');
                $notification->setParsedSubject(
                    $l->t('Certificate issued: %s', [$courseTitle])
                );
                $notification->setLink($appUrl);
                $notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('learning', 'app.svg')));
                break;

            case 'compliance_reminder':
                throw new UnknownNotificationException('compliance_reminder not yet rendered'); // real l10n render in 163-06

            default:
                throw new UnknownNotificationException('Unknown subject');
        }

        return $notification;
    }
}
