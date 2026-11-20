<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Activity\ActivityPublisher;
use OCA\Learning\Db\Certificate;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\CertificationCycleService;
use OCA\Learning\Service\CourseEnrollmentService;
use OCA\Learning\Service\CourseService;
use OCP\Activity\IManager as IActivityManager;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\RichObjectStrings\IValidator;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #85 against a real Nextcloud with the real activity app (scripts/nc-integration.sh):
 * enrolment, a revoke, a course announcement and a badge become activities that
 *  - the recipient sees in their own stream, rendered by our provider in their language,
 *  - nobody else sees (the activity app's stream is per recipient),
 *  - are queued for email exactly where the default is on (enrolment, certificate) or the user
 *    switched it on (badge), and not for the course feed, whose default is off.
 *
 * The revoke runs with the LEARNER as the session user, and the badge is the learner's own: the
 * activity app sends no email for an activity whose author is its recipient, which is why the
 * badge email never arrived before 5.16.0 even when switched on.
 */
final class LearningActivitiesTest extends TestCase {
    private IDBConnection $db;
    private IConfig $config;
    /** @var array<string, IUser> */
    private array $users = [];
    private int $courseId = 0;
    private string $gamificationBefore = '';

    protected function setUp(): void {
        if ($this->name() === 'testWithoutTheActivityAppNothingFails') {
            // Before anything sets up a filesystem: the activity app is a filesystem app and
            // would be booted (its consumer registered for the whole process) on the first one.
            $apps = Server::get(IAppManager::class);
            $apps->disableApp('activity');
            $this->assertFalse($apps->isAppLoaded('activity'), 'this process never booted the activity app');
        }
        $this->db = Server::get(IDBConnection::class);
        $this->config = Server::get(IConfig::class);
        $suffix = bin2hex(random_bytes(3));
        foreach (['learner', 'teacher', 'stranger'] as $role) {
            $user = Server::get(IUserManager::class)->createUser('gh85-' . $role . '-' . $suffix, bin2hex(random_bytes(16)) . 'Aa1!');
            $this->assertInstanceOf(IUser::class, $user);
            $this->users[$role] = $user;
            $this->config->setUserValue($user->getUID(), 'core', 'lang', 'de');
        }
        $this->gamificationBefore = $this->config->getAppValue('learning', 'gamification_enabled', '');
        $this->config->setAppValue('learning', 'gamification_enabled', 'yes');

        $now = time();
        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_courses')->values([
            'title' => $qb->createNamedParameter('Brandschutz 2026'),
            'instructor_id' => $qb->createNamedParameter($this->uid('teacher')),
            'status' => $qb->createNamedParameter('active'),
            'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
        ])->executeStatement();
        $this->courseId = $qb->getLastInsertId();
    }

    protected function tearDown(): void {
        if ($this->name() === 'testWithoutTheActivityAppNothingFails') {
            Server::get(IAppManager::class)->enableApp('activity');
        }
        Server::get(IUserSession::class)->setUser(null);
        foreach (['learning_course_members' => 'course_id', 'learning_feed_items' => 'course_id', 'learning_course_announcements' => 'course_id', 'learning_courses' => 'id'] as $table => $column) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($table)->where($qb->expr()->eq($column, $qb->createNamedParameter($this->courseId, IQueryBuilder::PARAM_INT)))->executeStatement();
        }
        $uids = array_map(static fn (IUser $u): string => $u->getUID(), $this->users);
        if ($uids !== []) {
            foreach (['activity' => 'affecteduser', 'activity_mq' => 'amq_affecteduser', 'learning_user_badges' => 'user_id'] as $table => $column) {
                $qb = $this->db->getQueryBuilder();
                $qb->delete($table)->where($qb->expr()->in($column, $qb->createNamedParameter(array_values($uids), IQueryBuilder::PARAM_STR_ARRAY)))->executeStatement();
            }
        }
        if ($uids !== []) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete('notifications')->where($qb->expr()->in('user', $qb->createNamedParameter(array_values($uids), IQueryBuilder::PARAM_STR_ARRAY)))
                ->andWhere($qb->expr()->eq('app', $qb->createNamedParameter('learning')))->executeStatement();
        }
        foreach ($this->users as $user) {
            $user->delete();
        }
        if ($this->gamificationBefore === '') {
            $this->config->deleteAppValue('learning', 'gamification_enabled');
        } else {
            $this->config->setAppValue('learning', 'gamification_enabled', $this->gamificationBefore);
        }
    }

    private function uid(string $role): string {
        return $this->users[$role]->getUID();
    }

    public function testTheActivitiesReachTheirRecipientsOnlyAndMailWhereTheDefaultIsOn(): void {
        $apps = Server::get(IAppManager::class);
        // Fail, never skip: without the activity app this test proves nothing.
        $this->assertTrue($apps->isEnabledForAnyone('activity'), 'the activity app ships enabled with Nextcloud');
        $apps->loadApp('activity');
        $learner = $this->uid('learner');
        $teacher = $this->uid('teacher');

        // Every Learning type has its setting registered by the time the activity app asks.
        $manager = Server::get(IActivityManager::class);
        foreach (array_unique(ActivityPublisher::TYPES) as $type) {
            $this->assertSame($type, $manager->getSettingById($type)->getIdentifier());
        }

        // 1. The teacher enrols the learner.
        Server::get(IUserSession::class)->setUser($this->users['teacher']);
        Server::get(CourseEnrollmentService::class)->addMember($this->courseId, $learner, 'student', $teacher);

        // 2. A revoke, seen from the learner's own request.
        Server::get(IUserSession::class)->setUser($this->users['learner']);
        $cert = new Certificate();
        $cert->setUserId($learner);
        $cert->setCourseId($this->courseId);
        $cert->setVerificationId('85858585-8585-4585-8585-' . substr(bin2hex(random_bytes(6)), 0, 12));
        Server::get(CertificationCycleService::class)->notifyRevoked($cert);

        // 3. The learner's own badge, with its email switched on by the learner.
        $this->config->setUserValue($learner, 'activity', 'notify_email_learning_badge_earned', '1');
        Server::get(BadgeService::class)->dispatchNotifications($learner, [['badge_id' => 'first_session']]);

        // 4. The teacher posts an announcement: a feed entry for the members, not for its author.
        Server::get(IUserSession::class)->setUser($this->users['teacher']);
        Server::get(CourseService::class)->createAnnouncement($this->courseId, $teacher, 'Übung am Freitag', 'Treffpunkt Halle 2', null);
        Server::get(IUserSession::class)->setUser(null);

        $rows = $this->rows('activity', 'affecteduser', $learner);
        $this->assertSame(
            ['learning_badge_earned', 'learning_certificate', 'learning_course_feed', 'learning_enrollment'],
            $this->sorted(array_column($rows, 'type'))
        );
        foreach ($rows as $row) {
            $this->assertSame(ActivityPublisher::AUTHOR, $row['user'], 'never a person as author: ' . $row['type']);
            $this->assertStringNotContainsString($teacher, (string)$row['subjectparams'], 'no uid in the parameters');
            $this->assertStringNotContainsString('Treffpunkt', (string)$row['subjectparams'], 'no announcement body');
        }

        // Email: on by default for enrolment and certificate, switched on for the badge, off for the feed.
        $this->assertSame(
            ['learning_badge_earned', 'learning_certificate', 'learning_enrollment'],
            $this->sorted(array_column($this->rows('activity_mq', 'amq_affecteduser', $learner), 'amq_type'))
        );

        // Nobody else: the stranger has nothing, the teacher not even the announcement they wrote.
        $this->assertSame([], $this->rows('activity', 'affecteduser', $this->uid('stranger')));
        $this->assertSame([], $this->rows('activity', 'affecteduser', $teacher));
        $this->assertSame([], $this->rows('activity_mq', 'amq_affecteduser', $this->uid('stranger')));

        // The learner's own stream, as the activity app builds it, renders them in German.
        $subjects = $this->stream($learner);
        $this->assertContains('Du wurdest in den Kurs Brandschutz 2026 eingeschrieben', $subjects);
        $this->assertContains('Dein Zertifikat für den Kurs Brandschutz 2026 wurde widerrufen', $subjects);
        $this->assertContains('Neue Ankündigung im Kurs Brandschutz 2026: Übung am Freitag', $subjects);
        $this->assertSame([], $this->stream($this->uid('stranger')));

        // GitHub #85 (links): the names are links, in the stream (rich subject, as the web UI
        // renders it) and in the digest email (built from the mail queue as the activity app does).
        $app = rtrim(Server::get(IURLGenerator::class)->linkToRouteAbsolute('learning.page.index'), '/');
        $course = $app . '/courses/' . $this->courseId;
        $links = [];
        foreach ($this->streamEntries($learner) as $entry) {
            foreach ($entry['subject_rich'][1] as $key => $parameter) {
                $links[$entry['subject'] . ' | ' . $key] = $parameter['link'] ?? null;
            }
        }
        $this->assertSame($course, $links['Du wurdest in den Kurs Brandschutz 2026 eingeschrieben | course'] ?? 'missing');
        $this->assertSame($course . '/summary', $links['Dein Zertifikat für den Kurs Brandschutz 2026 wurde widerrufen | course'] ?? 'missing');
        $this->assertSame($course . '/feed', $links['Neue Ankündigung im Kurs Brandschutz 2026: Übung am Freitag | announcement'] ?? 'missing');

        $html = $this->digestItems($learner);
        $this->assertContains('Du wurdest in den Kurs <a href="' . $course . '">Brandschutz 2026</a> eingeschrieben', $html);
        $this->assertContains('Dein Zertifikat für den Kurs <a href="' . $course . '/summary">Brandschutz 2026</a> wurde widerrufen', $html);
        $badge = array_values(array_filter($html, static fn (string $item): bool => str_contains($item, $app . '/dashboard')));
        $this->assertCount(1, $badge, 'the achievement links to the dashboard: ' . implode(' / ', $html));

        // The learner leaves the course: the names stay, the links go (no dead course page).
        $qb = $this->db->getQueryBuilder();
        $qb->delete('learning_course_members')
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($this->courseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($learner)))
            ->executeStatement();
        $html = $this->digestItems($learner);
        $this->assertContains('Du wurdest in den Kurs <strong>Brandschutz 2026</strong> eingeschrieben', $html);
        foreach ($html as $item) {
            $this->assertStringNotContainsString('/courses/', $item);
        }
    }

    /**
     * The HTML of each item of the learner's next activity email: the events are built from the
     * mail queue the way MailQueueHandler::sendEmailToUser() builds them, parsed by a fresh
     * provider (it caches access per request), and turned into HTML by the activity app's own
     * MailQueueHandler::getHTMLSubject(). An event the rich validator rejects fails here.
     *
     * @return list<string>
     */
    private function digestItems(string $uid): array {
        $provider = new \OCA\Learning\Activity\Provider(
            Server::get(IFactory::class),
            Server::get(IURLGenerator::class),
            $this->db,
            Server::get(\OCA\Learning\Db\CourseMapper::class),
            Server::get(\OCA\Learning\Db\CourseMemberMapper::class),
            Server::get(\OCA\Learning\Db\CoursePoolMapper::class),
        );
        $handler = (new \ReflectionClass(\OCA\Activity\MailQueueHandler::class))->newInstanceWithoutConstructor();
        $toHtml = new \ReflectionMethod(\OCA\Activity\MailQueueHandler::class, 'getHTMLSubject');
        $items = [];
        foreach ($this->rows('activity_mq', 'amq_affecteduser', $uid) as $row) {
            $event = Server::get(IActivityManager::class)->generateEvent();
            $event->setApp((string)$row['amq_appid'])
                ->setType((string)$row['amq_type'])
                ->setAffectedUser((string)$row['amq_affecteduser'])
                ->setTimestamp((int)$row['amq_timestamp'])
                ->setSubject((string)$row['amq_subject'], (array)json_decode((string)$row['amq_subjectparams'], true))
                ->setObject((string)$row['object_type'], (int)$row['object_id']);
            $provider->parse('de', $event);
            $this->assertTrue($event->isValidParsed(), 'valid for the activity app: ' . $row['amq_subject']);
            $this->assertNotSame('', $event->getRichSubject(), 'a rich subject: ' . $row['amq_subject']);
            $items[] = (string)$toHtml->invoke($handler, $event);
        }
        return $items;
    }

    /**
     * Without the activity app nothing fails: the enrolment goes on as before. In its
     * own process with the app disabled from the start (see setUp), because once the activity app
     * has booted, its consumer stays registered for the rest of the process.
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testWithoutTheActivityAppNothingFails(): void {
        $learner = $this->uid('learner');
        $result = Server::get(CourseEnrollmentService::class)->addMember($this->courseId, $learner, 'student', $this->uid('teacher'));

        $this->assertTrue($result['created']);
        $this->assertFalse(Server::get(IAppManager::class)->isAppLoaded('activity'));
        $this->assertSame([], $this->rows('activity', 'affecteduser', $learner));
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from('learning_course_members')
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($this->courseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($learner)));
        $this->assertNotFalse($qb->executeQuery()->fetchOne(), 'the enrolment is stored');
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $table, string $column, string $uid): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($table)->where($qb->expr()->eq($column, $qb->createNamedParameter($uid)));
        if ($table === 'activity') {
            $qb->andWhere($qb->expr()->eq('app', $qb->createNamedParameter('learning')));
        } else {
            $qb->andWhere($qb->expr()->eq('amq_appid', $qb->createNamedParameter('learning')));
        }
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        return $rows;
    }

    /** @param list<mixed> $values @return list<string> */
    private function sorted(array $values): array {
        $values = array_map('strval', $values);
        sort($values);
        return $values;
    }

    /** @return list<string> the parsed subjects of the user's Learning stream */
    private function stream(string $uid): array {
        return array_map(static fn (array $activity): string => (string)$activity['subject'], $this->streamEntries($uid));
    }

    /** @return list<array<string, mixed>> the user's Learning stream entries, as the activity app builds them */
    private function streamEntries(string $uid): array {
        $l = Server::get(IFactory::class)->get('activity', 'de');
        $groupHelper = new \OCA\Activity\GroupHelper($l, Server::get(IActivityManager::class), Server::get(IValidator::class), Server::get(LoggerInterface::class));
        $data = Server::get(\OCA\Activity\Data::class)->get($groupHelper, Server::get(\OCA\Activity\UserSettings::class), $uid, 0, 50, 'desc', 'all');
        $entries = [];
        foreach ($data['data'] as $activity) {
            if ($activity['app'] === 'learning') {
                $entries[] = $activity;
            }
        }
        return $entries;
    }
}
