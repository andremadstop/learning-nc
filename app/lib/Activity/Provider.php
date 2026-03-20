<?php
declare(strict_types=1);
namespace OCA\Learning\Activity;

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
            throw new \InvalidArgumentException('Wrong app');
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
                $event->setIcon($this->urlGenerator->imagePath('learning', 'app.svg'));
                $event->setLink($this->urlGenerator->linkToRouteAbsolute('learning.page.index'));
                break;

            default:
                throw new \InvalidArgumentException('Unknown subject: ' . $event->getSubject());
        }

        return $event;
    }
}
