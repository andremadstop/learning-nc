<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use OCA\Learning\Db\UserTelos;
use OCA\Learning\Db\UserTelosMapper;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use Sabre\VObject\Component\VCalendar;

class IcsService {
    private IDBConnection $db;
    private UserTelosMapper $userTelosMapper;
    private IURLGenerator $urlGenerator;

    public function __construct(
        IDBConnection $db,
        UserTelosMapper $userTelosMapper,
        IURLGenerator $urlGenerator
    ) {
        $this->db = $db;
        $this->userTelosMapper = $userTelosMapper;
        $this->urlGenerator = $urlGenerator;
    }

    /**
     * @return array{token: string, url: string}
     */
    public function ensureCalendarToken(string $userId): array {
        $entity = $this->getOrCreateUserTelos($userId);
        $token = $entity->getIcsToken();

        if ($token === null || $token === '') {
            $token = $this->persistToken($entity, bin2hex(random_bytes(32)));
        }

        return [
            'token' => $token,
            'url' => $this->buildFeedUrl($token),
        ];
    }

    /**
     * @return array{token: string, url: string}
     */
    public function regenerateCalendarToken(string $userId): array {
        $entity = $this->getOrCreateUserTelos($userId);
        $token = $this->persistToken($entity, bin2hex(random_bytes(32)));

        return [
            'token' => $token,
            'url' => $this->buildFeedUrl($token),
        ];
    }

    public function renderCalendarForToken(string $token): ?string {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $entity = $this->userTelosMapper->findByIcsTokenOrNull($token);
        if ($entity === null) {
            return null;
        }

        return $this->renderCalendarForUser($entity->getUserId());
    }

    public function renderCalendarForUser(string $userId): string {
        $calendar = new VCalendar();
        $calendar->PRODID = '-//Learning-NC//Leitner Repetition//DE';
        $calendar->VERSION = '2.0';
        $calendar->CALSCALE = 'GREGORIAN';
        $calendar->METHOD = 'PUBLISH';
        $calendar->{'X-WR-CALNAME'} = 'Leitner-Wiederholungen';
        $calendar->{'X-WR-CALDESC'} = 'Faellige Leitner-Wiederholungen nach Kurskontext';

        foreach ($this->loadDueEventGroups($userId) as $eventGroup) {
            $start = new DateTimeImmutable($eventGroup['due_date'] . ' 00:00:00', new DateTimeZone('UTC'));
            $end = $start->add(new DateInterval('P1D'));
            $deepLink = $this->urlGenerator->linkToRouteAbsolute('learning.page.index');

            $vevent = $calendar->add('VEVENT', [
                'SUMMARY' => sprintf('Leitner-Wiederholung: %s', $eventGroup['course_title']),
                'DESCRIPTION' => implode("\n", [
                    sprintf('%d Karten faellig aus Box %d.', $eventGroup['card_count'], $eventGroup['box']),
                    sprintf('Direkt zum Lernraum: %s', $deepLink),
                ]),
                'DTSTART' => $start,
                'DTEND' => $end,
                'URL' => $deepLink,
            ]);
            $vevent->DTSTART['VALUE'] = 'DATE';
            $vevent->DTEND['VALUE'] = 'DATE';
            $vevent->UID = sprintf(
                'lnc-%s-%d-%d-%s@learning',
                substr(sha1($userId), 0, 16),
                $eventGroup['course_id'],
                $eventGroup['box'],
                str_replace('-', '', $eventGroup['due_date'])
            );
            $vevent->add('CATEGORIES', 'LEITNER');
            $vevent->add('TRANSP', 'TRANSPARENT');
        }

        return $calendar->serialize();
    }

    private function buildFeedUrl(string $token): string {
        return $this->urlGenerator->linkToRouteAbsolute('learning.ics.feed', ['token' => $token]);
    }

    private function getOrCreateUserTelos(string $userId): UserTelos {
        $entity = $this->userTelosMapper->findByUserIdOrNull($userId);
        if ($entity !== null) {
            return $entity;
        }

        $now = time();
        $entity = new UserTelos();
        $entity->setUserId($userId);
        $entity->setCreatedAt($now);
        $entity->setUpdatedAt($now);

        return $this->userTelosMapper->insert($entity);
    }

    private function persistToken(UserTelos $entity, string $token): string {
        $entity->setIcsToken($token);
        $entity->setUpdatedAt(time());

        if ($entity->getId() === null) {
            $entity = $this->userTelosMapper->insert($entity);
        } else {
            $entity = $this->userTelosMapper->update($entity);
        }

        return (string)$entity->getIcsToken();
    }

    /**
     * @return list<array{course_id: int, course_title: string, box: int, due_date: string, card_count: int}>
     */
    private function loadDueEventGroups(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        // Every box, dated by next_review — the date the queue makes the card due (FSRS interval).
        // Fixed box intervals (7/14/30 days from last_reviewed, boxes 3-5 only) put cards on other
        // days than the queue and left boxes 1-2 out (audit #54).
        $qb->select('c.id AS course_id', 'c.title AS course_title', 'l.box', 'l.next_review')
            ->from('learning_leitner_items', 'l')
            ->innerJoin('l', 'learning_course_pools', 'cp', 'cp.pool_id = l.pool_id')
            ->innerJoin('cp', 'learning_courses', 'c', 'c.id = cp.course_id')
            ->innerJoin('c', 'learning_course_members', 'cm', $qb->expr()->andX(
                $qb->expr()->eq('cm.course_id', 'c.id'),
                $qb->expr()->eq('cm.user_id', $qb->createNamedParameter($userId))
            ))
            ->where($qb->expr()->eq('l.user_id', $qb->createNamedParameter($userId)))
            ->orderBy('c.title', 'ASC')
            ->addOrderBy('l.box', 'ASC');

        $result = $qb->executeQuery();
        $todayStart = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->getTimestamp();
        $groups = [];

        while ($row = $result->fetch()) {
            $courseId = (int)$row['course_id'];
            $box = (int)$row['box'];
            // Overdue cards are due today.
            $dueTimestamp = max($todayStart, (int)$row['next_review']);
            $dueDate = gmdate('Y-m-d', $dueTimestamp);
            $groupKey = implode(':', [$courseId, $box, $dueDate]);

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'course_id' => $courseId,
                    'course_title' => (string)$row['course_title'],
                    'box' => $box,
                    'due_date' => $dueDate,
                    'card_count' => 0,
                ];
            }

            $groups[$groupKey]['card_count']++;
        }

        $result->closeCursor();

        usort($groups, static function (array $left, array $right): int {
            if ($left['due_date'] !== $right['due_date']) {
                return strcmp($left['due_date'], $right['due_date']);
            }
            if ($left['course_title'] !== $right['course_title']) {
                return strcasecmp($left['course_title'], $right['course_title']);
            }
            return $left['box'] <=> $right['box'];
        });

        return $groups;
    }
}
