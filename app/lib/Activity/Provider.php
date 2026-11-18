<?php
declare(strict_types=1);
namespace OCA\Learning\Activity;

use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

/**
 * Renders every Learning activity (GitHub #85) in the reader's language, for the stream and for
 * the activity email. Texts are built from the stored ids and titles only (see ActivityPublisher);
 * an announcement's title is read when it is rendered (announcementTitle()).
 */
class Provider implements IProvider {
    private IFactory $l10nFactory;
    private IURLGenerator $urlGenerator;
    private IDBConnection $db;
    /** @var array<string, string|null> per request: "<course>:<announcement>" => current title */
    private array $announcementTitles = [];

    public function __construct(IFactory $l10nFactory, IURLGenerator $urlGenerator, IDBConnection $db) {
        $this->l10nFactory = $l10nFactory;
        $this->urlGenerator = $urlGenerator;
        $this->db = $db;
    }

    public function parse(mixed $language, IEvent $event, ?IEvent $previousEvent = null): IEvent {
        if ($event->getApp() !== 'learning') {
            throw new UnknownActivityException('Wrong app');
        }

        $l = $this->l10nFactory->get('learning', (string) $language);
        $params = $event->getSubjectParameters();
        $courseTitle = (string)($params['course_title'] ?? '');
        $courseId = (int)($params['course_id'] ?? 0);

        switch ($event->getSubject()) {
            case ActivityPublisher::SUBJECT_BADGE_EARNED:
                $badgeName = $params['badge_name'] ?? '';
                $badgeEmoji = $params['badge_emoji'] ?? '';
                $event->setParsedSubject(
                    $l->t('Achievement unlocked: %s %s', [$badgeEmoji, $badgeName])
                );
                $event->setLink($this->appUrl());
                break;

            case ActivityPublisher::SUBJECT_ENROLLED:
                $event->setParsedSubject($l->t('Du wurdest in den Kurs %s eingeschrieben', [$courseTitle]));
                $event->setLink($this->courseUrl($courseId, ''));
                break;

            case ActivityPublisher::SUBJECT_CERT_ISSUED:
                $event->setParsedSubject($l->t('Dein Zertifikat für den Kurs %s wurde ausgestellt', [$courseTitle]));
                $event->setLink($this->courseUrl($courseId, 'summary'));
                break;

            case ActivityPublisher::SUBJECT_CERT_EXPIRING:
                $days = max(1, (int)($params['days_left'] ?? 0));
                $event->setParsedSubject($l->n(
                    'Dein Zertifikat für den Kurs %s läuft in %n Tag ab',
                    'Dein Zertifikat für den Kurs %s läuft in %n Tagen ab',
                    $days,
                    [$courseTitle]
                ));
                $event->setLink($this->courseUrl($courseId, 'summary'));
                break;

            case ActivityPublisher::SUBJECT_CERT_EXPIRED:
                $event->setParsedSubject($l->t('Dein Zertifikat für den Kurs %s ist abgelaufen', [$courseTitle]));
                $event->setLink($this->courseUrl($courseId, 'summary'));
                break;

            case ActivityPublisher::SUBJECT_CERT_REVOKED:
                $event->setParsedSubject($l->t('Dein Zertifikat für den Kurs %s wurde widerrufen', [$courseTitle]));
                $event->setLink($this->courseUrl($courseId, 'summary'));
                break;

            case ActivityPublisher::SUBJECT_CYCLE_OPENED:
                $event->setParsedSubject($l->t('Im Kurs %s ist ein neuer Zertifizierungszyklus offen', [$courseTitle]));
                $event->setLink($this->courseUrl($courseId, 'summary'));
                break;

            case ActivityPublisher::SUBJECT_COMPLIANCE_REMINDER:
                $event->setParsedSubject($l->t('Erinnerung an die Pflichtschulung im Kurs %s', [$courseTitle]));
                $event->setLink($this->courseUrl($courseId, ''));
                break;

            case ActivityPublisher::SUBJECT_FEED_POOL_ADDED:
                $event->setParsedSubject($l->t('Neuer Fragenpool im Kurs %1$s: %2$s', [$courseTitle, (string)($params['pool_name'] ?? '')]));
                $event->setLink($this->courseUrl($courseId, 'feed'));
                break;

            case ActivityPublisher::SUBJECT_FEED_ANNOUNCEMENT:
                // GitHub #85 (review): the current title, or none once the announcement is gone.
                $title = $this->announcementTitle($courseId, (int)($params['announcement_id'] ?? 0));
                $event->setParsedSubject($title !== null && $title !== ''
                    ? $l->t('Neue Ankündigung im Kurs %1$s: %2$s', [$courseTitle, $title])
                    : $l->t('Neue Ankündigung im Kurs %s', [$courseTitle]));
                // The feed, not the announcements tab: learners (the main recipients) only have
                // the feed, which shows the announcement; the announcements tab is instructor-only.
                $event->setLink($this->courseUrl($courseId, 'feed'));
                break;

            default:
                throw new UnknownActivityException('Unknown subject: ' . $event->getSubject());
        }

        // IEvent::setIcon() is documented as taking an ABSOLUTE url — desktop and mobile
        // clients do not share the web root, so a relative path renders no icon there.
        $event->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('learning', 'app-dark.svg')));

        return $event;
    }

    /**
     * The announcement's title as it is now; null when it was deleted (or the event names none).
     * Read on every render, so an edit shows the new title and a deletion leaves none behind.
     */
    private function announcementTitle(int $courseId, int $announcementId): ?string {
        if ($courseId <= 0 || $announcementId <= 0) {
            return null;
        }
        $key = $courseId . ':' . $announcementId;
        if (array_key_exists($key, $this->announcementTitles)) {
            return $this->announcementTitles[$key];
        }
        $title = null;
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('title')
                ->from('learning_course_announcements')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($announcementId, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)));
            $result = $qb->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();
            if (is_array($row) && isset($row['title'])) {
                $title = (string)$row['title'];
            }
        } catch (\Throwable $e) {
            // Rendering the stream must not fail on one entry: without the title it still names the course.
            $title = null;
        }
        return $this->announcementTitles[$key] = $title;
    }

    private function appUrl(): string {
        return $this->urlGenerator->linkToRouteAbsolute('learning.page.index');
    }

    /** The course (optionally one of its tabs); an event without a course id opens the app. */
    private function courseUrl(int $courseId, string $tab): string {
        if ($courseId <= 0) {
            return $this->appUrl();
        }
        return rtrim($this->appUrl(), '/') . '/courses/' . $courseId . ($tab !== '' ? '/' . $tab : '');
    }
}
