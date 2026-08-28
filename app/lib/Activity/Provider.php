<?php
declare(strict_types=1);
namespace OCA\Learning\Activity;

use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

class Provider implements IProvider {
    private IFactory $l10nFactory;
    private IURLGenerator $urlGenerator;

    public function __construct(IFactory $l10nFactory, IURLGenerator $urlGenerator) {
        $this->l10nFactory = $l10nFactory;
        $this->urlGenerator = $urlGenerator;
    }

    public function parse(mixed $language, IEvent $event, ?IEvent $previousEvent = null): IEvent {
        if ($event->getApp() !== 'learning') {
            throw new UnknownActivityException('Wrong app');
        }

        $l = $this->l10nFactory->get('learning', (string) $language);
        $params = $event->getSubjectParameters();

        switch ($event->getSubject()) {
            case 'badge_earned':
                $badgeName = $params['badge_name'] ?? '';
                $badgeEmoji = $params['badge_emoji'] ?? '';
                $event->setParsedSubject(
                    $l->t('Achievement unlocked: %s %s', [$badgeEmoji, $badgeName])
                );
                // IEvent::setIcon() is documented as taking an ABSOLUTE url — desktop and mobile
                // clients do not share the web root, so a relative path renders no icon there.
                // Notification\Notifier already wraps imagePath() this way; this one was missed.
                $event->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('learning', 'app.svg')));
                $event->setLink($this->urlGenerator->linkToRouteAbsolute('learning.page.index'));
                break;

            default:
                throw new UnknownActivityException('Unknown subject: ' . $event->getSubject());
        }

        return $event;
    }
}
