<?php
declare(strict_types=1);
namespace OCA\Learning\Activity;

use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CoursePoolMapper;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;

/**
 * Renders every Learning activity (GitHub #85) in the reader's language, for the stream and for
 * the activity email. Texts are built from the stored ids and titles only (see ActivityPublisher);
 * an announcement's title is read when it is rendered (announcementTitle()).
 *
 * Every subject is also a rich subject (setSubjects()): the names in it (course, pool,
 * announcement, achievement) are links to what they name, in the stream and in the activity email.
 *
 * GitHub #87: "all exam attempts used up" goes to the course's instructors. The learner's name is
 * read when it is rendered, from the member row the event carries (never a uid), and only for a
 * reader who still leads the course; name and course then open the course's member list.
 */
class Provider implements IProvider {
    private IFactory $l10nFactory;
    private IURLGenerator $urlGenerator;
    private IDBConnection $db;
    private CourseMapper $courseMapper;
    private CourseMemberMapper $memberMapper;
    private CoursePoolMapper $coursePoolMapper;
    /** @var array<string, string|null> per request: "<course>:<announcement>" => current title */
    private array $announcementTitles = [];
    /** @var array<string, bool> per request: "<course>:<reader>" => the reader reaches the course */
    private array $reachable = [];
    /** @var array<string, bool> per request: "<course>:<pool>" => the pool is in the course */
    private array $poolsInCourse = [];
    /** @var array<string, bool> per request: "<course>:<reader>" => the reader leads the course */
    private array $leads = [];
    private IUserManager $userManager;

    public function __construct(
        IFactory $l10nFactory,
        IURLGenerator $urlGenerator,
        IDBConnection $db,
        CourseMapper $courseMapper,
        CourseMemberMapper $memberMapper,
        CoursePoolMapper $coursePoolMapper,
        IUserManager $userManager,
    ) {
        $this->l10nFactory = $l10nFactory;
        $this->urlGenerator = $urlGenerator;
        $this->db = $db;
        $this->courseMapper = $courseMapper;
        $this->memberMapper = $memberMapper;
        $this->coursePoolMapper = $coursePoolMapper;
        $this->userManager = $userManager;
    }

    public function parse(mixed $language, IEvent $event, ?IEvent $previousEvent = null): IEvent {
        if ($event->getApp() !== 'learning') {
            throw new UnknownActivityException('Wrong app');
        }

        $l = $this->l10nFactory->get('learning', (string) $language);
        $params = $event->getSubjectParameters();
        $courseTitle = (string)($params['course_title'] ?? '');
        $courseId = (int)($params['course_id'] ?? 0);
        // GitHub #85 (links): only a course the reader still reaches gets links; a deleted one, or
        // one they left, keeps its name as before and opens the app instead of a dead course page.
        $reachable = $this->courseReachable($courseId, (string)$event->getAffectedUser());

        switch ($event->getSubject()) {
            case ActivityPublisher::SUBJECT_BADGE_EARNED:
                $badgeName = (string)($params['badge_name'] ?? '');
                $badgeEmoji = (string)($params['badge_emoji'] ?? '');
                // Opens Today (/dashboard: streak, level). No page lists the achievements app-wide
                // (only a pool's statistics and a course summary), and the event names no pool or
                // course; where it should lead is an open decision.
                $dashboard = rtrim($this->appUrl(), '/') . '/dashboard';
                $badge = ['badge' => $this->highlight((string)$event->getObjectName(), $badgeName, $dashboard)];
                if ($badgeEmoji !== '') {
                    $badge = ['emoji' => $this->highlight('emoji', $badgeEmoji, null)] + $badge;
                }
                $this->setSubjects($event, $l->t('Achievement unlocked: %s %s', [$badgeEmoji !== '' ? '{emoji}' : '', '{badge}']), $badge);
                $event->setLink($dashboard);
                break;

            case ActivityPublisher::SUBJECT_ENROLLED:
                $this->courseSubject($event, $l->t('Du wurdest in den Kurs %s eingeschrieben', ['{course}']), $courseId, $courseTitle, $reachable, '');
                break;

            case ActivityPublisher::SUBJECT_CERT_ISSUED:
                $this->courseSubject($event, $l->t('Dein Zertifikat für den Kurs %s wurde ausgestellt', ['{course}']), $courseId, $courseTitle, $reachable, 'summary');
                break;

            case ActivityPublisher::SUBJECT_CERT_EXPIRING:
                $days = max(1, (int)($params['days_left'] ?? 0));
                $this->courseSubject($event, $l->n(
                    'Dein Zertifikat für den Kurs %s läuft in %n Tag ab',
                    'Dein Zertifikat für den Kurs %s läuft in %n Tagen ab',
                    $days,
                    ['{course}']
                ), $courseId, $courseTitle, $reachable, 'summary');
                break;

            case ActivityPublisher::SUBJECT_CERT_EXPIRED:
                $this->courseSubject($event, $l->t('Dein Zertifikat für den Kurs %s ist abgelaufen', ['{course}']), $courseId, $courseTitle, $reachable, 'summary');
                break;

            case ActivityPublisher::SUBJECT_CERT_REVOKED:
                $this->courseSubject($event, $l->t('Dein Zertifikat für den Kurs %s wurde widerrufen', ['{course}']), $courseId, $courseTitle, $reachable, 'summary');
                break;

            case ActivityPublisher::SUBJECT_CYCLE_OPENED:
                $this->courseSubject($event, $l->t('Im Kurs %s ist ein neuer Zertifizierungszyklus offen', ['{course}']), $courseId, $courseTitle, $reachable, 'summary');
                break;

            case ActivityPublisher::SUBJECT_COMPLIANCE_REMINDER:
                // The course itself: a learner lands on its first learning area, which is the
                // learning material wherever the course has any (lernraumTabIds()).
                $this->courseSubject($event, $l->t('Erinnerung an die Pflichtschulung im Kurs %s', ['{course}']), $courseId, $courseTitle, $reachable, '');
                break;

            case ActivityPublisher::SUBJECT_FEED_POOL_ADDED:
                $poolId = (int)($params['pool_id'] ?? 0);
                // The pool itself (the pool link of the search, ?poolId=): learners have no pools
                // tab in a course. Only while the pool still belongs to the course.
                $poolLink = $reachable && $this->poolInCourse($courseId, $poolId)
                    ? rtrim($this->appUrl(), '/') . '/pools?poolId=' . $poolId
                    : null;
                $this->courseSubject($event, $l->t('Neuer Fragenpool im Kurs %1$s: %2$s', ['{course}', '{pool}']), $courseId, $courseTitle, $reachable, '', [
                    'pool' => $this->highlight((string)$poolId, (string)($params['pool_name'] ?? ''), $poolLink),
                ]);
                $event->setLink($this->courseLink($courseId, 'feed', $reachable));
                break;

            case ActivityPublisher::SUBJECT_FEED_ANNOUNCEMENT:
                // GitHub #85 (review): the current title, or none once the announcement is gone.
                $announcementId = (int)($params['announcement_id'] ?? 0);
                // Read now, so only for a reader still in the course: a former member must not
                // see a title given after they left (security review 5.17.0).
                $title = $reachable ? $this->announcementTitle($courseId, $announcementId) : null;
                // The feed, not the announcements tab: learners (the main recipients) only have
                // the feed, which shows the announcement; the announcements tab is instructor-only.
                $feed = $this->courseLink($courseId, 'feed', $reachable);
                if ($title !== null && $title !== '') {
                    $this->courseSubject($event, $l->t('Neue Ankündigung im Kurs %1$s: %2$s', ['{course}', '{announcement}']), $courseId, $courseTitle, $reachable, '', [
                        'announcement' => $this->highlight((string)$announcementId, $title, $reachable ? $feed : null),
                    ]);
                } else {
                    $this->courseSubject($event, $l->t('Neue Ankündigung im Kurs %s', ['{course}']), $courseId, $courseTitle, $reachable, '');
                }
                $event->setLink($feed);
                break;

            case ActivityPublisher::SUBJECT_EXAM_ATTEMPTS_EXHAUSTED:
                // GitHub #87: the member list is the instructors' tab. A reader who no longer leads
                // the course gets neither a link nor the learner's name (read now, like the bell's).
                $leads = $this->leadsCourse($courseId, (string)$event->getAffectedUser());
                $memberId = (int)($params['member_id'] ?? 0);
                $name = $leads ? $this->learnerName($memberId, $courseId) : null;
                $members = $leads ? $this->courseUrl($courseId, 'members') : null;
                $this->setSubjects($event, $l->t('%1$s hat alle Prüfungsversuche für „%2$s“ im Kurs %3$s aufgebraucht', ['{learner}', '{pool}', '{course}']), [
                    'learner' => $this->highlight((string)$memberId, $name ?? $l->t('Eine lernende Person'), $name !== null ? $members : null),
                    'pool' => $this->highlight((string)(int)($params['pool_id'] ?? 0), (string)($params['pool_title'] ?? ''), null),
                    'course' => $this->highlight((string)$courseId, $courseTitle, $members),
                ]);
                $event->setLink($members ?? $this->appUrl());
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
     * A subject naming the course: the course name links to $tab of the course (stream and the
     * activity email), and so does the activity itself.
     *
     * @param array<string, array<string, string>> $more further rich parameters of the subject
     */
    private function courseSubject(IEvent $event, string $template, int $courseId, string $courseTitle, bool $reachable, string $tab, array $more = []): void {
        $link = $this->courseLink($courseId, $tab, $reachable);
        $this->setSubjects($event, $template, [
            'course' => $this->highlight((string)$courseId, $courseTitle, $reachable ? $link : null),
        ] + $more);
        $event->setLink($link);
    }

    /**
     * GitHub #85 (links): the plain subject and the rich one. The template is the translated
     * sentence with {placeholders} in place of every name: the activity email puts the rich
     * subject into its HTML as it is and escapes only the parameters' names, so no title, no pool
     * name and no announcement title may ever be part of the template itself.
     *
     * @param array<string, array<string, string>> $parameters
     */
    private function setSubjects(IEvent $event, string $template, array $parameters): void {
        $names = [];
        foreach ($parameters as $key => $parameter) {
            $names['{' . $key . '}'] = $parameter['name'];
        }
        // strtr, not str_replace: a name that itself reads "{pool}" is not replaced a second time.
        $event->setParsedSubject(strtr($template, $names));
        foreach ($parameters as $parameter) {
            // An event without a name (written before the title was stored) keeps the plain text.
            if ($parameter['name'] === '') {
                return;
            }
        }
        try {
            $event->setRichSubject($template, $parameters);
        } catch (\Throwable $e) {
            // The plain subject is set; one entry must not break the stream or the email.
        }
    }

    /**
     * A rich object of the core type "highlight" (id, name, optional link). Every value is a
     * string: the rich object validator drops an event whose parameters hold anything else, and
     * with it the entry in the stream and in the email.
     *
     * @return array<string, string>
     */
    private function highlight(string $id, string $name, ?string $link): array {
        $parameter = ['type' => 'highlight', 'id' => $id !== '' ? $id : '0', 'name' => $name];
        if ($link !== null) {
            $parameter['link'] = $link;
        }
        return $parameter;
    }

    /** The course tab for a course the reader reaches; the app for any other. */
    private function courseLink(int $courseId, string $tab, bool $reachable): string {
        return $reachable ? $this->courseUrl($courseId, $tab) : $this->appUrl();
    }

    /**
     * Whether $userId still reaches the course: it exists and they lead it or are a member (the
     * same rule as CourseService::hasAccess(), which answers 404 for both other cases). Per
     * request, once per course and reader; any failure counts as "no".
     */
    private function courseReachable(int $courseId, string $userId): bool {
        if ($courseId <= 0 || $userId === '') {
            return false;
        }
        $key = $courseId . ':' . $userId;
        if (array_key_exists($key, $this->reachable)) {
            return $this->reachable[$key];
        }
        $reachable = false;
        try {
            $course = $this->courseMapper->findById($courseId);
            if ((string)$course->getInstructorId() === $userId) {
                $reachable = true;
            } else {
                $this->memberMapper->findByCourseAndUser($courseId, $userId);
                $reachable = true;
            }
        } catch (\Throwable $e) {
            $reachable = false;
        }
        return $this->reachable[$key] = $reachable;
    }

    /**
     * GitHub #87: whether $userId leads the course now: its owner or a member with the instructor
     * role (who see the member list). Per request; any failure counts as "no".
     */
    private function leadsCourse(int $courseId, string $userId): bool {
        if ($courseId <= 0 || $userId === '') {
            return false;
        }
        $key = $courseId . ':' . $userId;
        if (!array_key_exists($key, $this->leads)) {
            try {
                $this->leads[$key] = (string)$this->courseMapper->findById($courseId)->getInstructorId() === $userId
                    || $this->memberMapper->findByCourseAndUser($courseId, $userId)->getRole() === 'instructor';
            } catch (\Throwable $e) {
                $this->leads[$key] = false;
            }
        }
        return $this->leads[$key];
    }

    /**
     * The current display name behind a member row of this course (as Notifier::learnerName()):
     * null for a row of another course, a row that is gone (the learner left the course or the
     * account was deleted) or an unknown account.
     */
    private function learnerName(int $memberId, int $courseId): ?string {
        if ($memberId <= 0) {
            return null;
        }
        try {
            $member = $this->memberMapper->findById($memberId);
            $uid = (string)$member->getUserId();
            if ((int)$member->getCourseId() !== $courseId || $uid === '') {
                return null;
            }
            $user = $this->userManager->get($uid);
            $name = $user !== null ? (string)$user->getDisplayName() : '';
            return $name !== '' ? $name : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function poolInCourse(int $courseId, int $poolId): bool {
        if ($courseId <= 0 || $poolId <= 0) {
            return false;
        }
        $key = $courseId . ':' . $poolId;
        if (!array_key_exists($key, $this->poolsInCourse)) {
            try {
                $this->coursePoolMapper->findByCourseAndPool($courseId, $poolId);
                $this->poolsInCourse[$key] = true;
            } catch (\Throwable $e) {
                $this->poolsInCourse[$key] = false;
            }
        }
        return $this->poolsInCourse[$key];
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
