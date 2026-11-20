<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Activity\ActivityPublisher;
use OCA\Learning\Activity\ExamAttemptsSetting;
use OCA\Learning\Activity\Provider;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CoursePoolMapper;
use OCA\Learning\Service\ExamAttemptLimitService;
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
 * GitHub #87 against a real Nextcloud with the real activity app (scripts/nc-integration.sh), wired
 * by Nextcloud's container as in production: a learner fails the last allowed counting exam of a
 * pool. The course's owner and co-instructor get the bell notification as before (its delivery is
 * covered by ExamAttemptLimitServiceTest; a CLI run stores no notification rows) and now also an
 * activity, in their stream and queued for email (on by default); the learner gets none. The
 * learner's name and the course link to the course's member list, in the stream and in the
 * digest email. Once per event: a second check (the refused next start) adds nothing.
 */
final class ExamAttemptsActivity87Test extends TestCase {
    private IDBConnection $db;
    /** @var array<string, IUser> */
    private array $users = [];
    private int $courseId = 0;
    private int $poolId = 0;
    /** @var array<string, string> */
    private array $configBefore = [];

    protected function setUp(): void {
        $this->db = Server::get(IDBConnection::class);
        $config = Server::get(IConfig::class);
        foreach (['exam_attempt_limit_per_day' => '50', 'exam_attempt_cooldown_minutes' => '0'] as $key => $value) {
            $this->configBefore[$key] = $config->getAppValue('learning', $key, '');
            $config->setAppValue('learning', $key, $value);
        }
        $suffix = bin2hex(random_bytes(3));
        foreach (['owner', 'co', 'learner'] as $role) {
            $user = Server::get(IUserManager::class)->createUser('gh87-' . $role . '-' . $suffix, bin2hex(random_bytes(16)) . 'Aa1!');
            $this->assertInstanceOf(IUser::class, $user);
            $this->users[$role] = $user;
            $config->setUserValue($user->getUID(), 'core', 'lang', 'de');
        }
        $this->users['learner']->setDisplayName('Lea Lernend');

        $now = time();
        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_courses')->values([
            'title' => $qb->createNamedParameter('Brandschutz 2026'),
            'instructor_id' => $qb->createNamedParameter($this->uid('owner')),
            'status' => $qb->createNamedParameter('active'),
            'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            'cert_enabled' => $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
            'cert_pass_percent' => $qb->createNamedParameter(80, IQueryBuilder::PARAM_INT),
            'cert_validity_months' => $qb->createNamedParameter(12, IQueryBuilder::PARAM_INT),
            'exam_attempt_limit' => $qb->createNamedParameter(2, IQueryBuilder::PARAM_INT),
        ])->executeStatement();
        $this->courseId = $qb->getLastInsertId();

        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_pools')->values([
            'user_id' => $qb->createNamedParameter($this->uid('owner')),
            'name' => $qb->createNamedParameter('Löschmittel'),
            'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
        ])->executeStatement();
        $this->poolId = $qb->getLastInsertId();

        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_course_pools')->values([
            'course_id' => $qb->createNamedParameter($this->courseId, IQueryBuilder::PARAM_INT),
            'pool_id' => $qb->createNamedParameter($this->poolId, IQueryBuilder::PARAM_INT),
        ])->executeStatement();

        foreach (['co' => 'instructor', 'learner' => 'student'] as $who => $role) {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('learning_course_members')->values([
                'course_id' => $qb->createNamedParameter($this->courseId, IQueryBuilder::PARAM_INT),
                'user_id' => $qb->createNamedParameter($this->uid($who)),
                'role' => $qb->createNamedParameter($role),
                'enrolled_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            ])->executeStatement();
        }
    }

    protected function tearDown(): void {
        Server::get(IUserSession::class)->setUser(null);
        $uids = array_values(array_map(static fn (IUser $u): string => $u->getUID(), $this->users));
        foreach (['learning_sessions' => 'course_id', 'learning_course_members' => 'course_id', 'learning_course_pools' => 'course_id', 'learning_courses' => 'id'] as $table => $column) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($table)->where($qb->expr()->eq($column, $qb->createNamedParameter($this->courseId, IQueryBuilder::PARAM_INT)))->executeStatement();
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete('learning_pools')->where($qb->expr()->eq('id', $qb->createNamedParameter($this->poolId, IQueryBuilder::PARAM_INT)))->executeStatement();
        if ($uids !== []) {
            foreach (['activity' => 'affecteduser', 'activity_mq' => 'amq_affecteduser', 'notifications' => 'user'] as $table => $column) {
                $qb = $this->db->getQueryBuilder();
                $qb->delete($table)->where($qb->expr()->in($column, $qb->createNamedParameter($uids, IQueryBuilder::PARAM_STR_ARRAY)))->executeStatement();
            }
        }
        foreach ($this->users as $user) {
            $user->delete();
        }
        $config = Server::get(IConfig::class);
        foreach ($this->configBefore as $key => $value) {
            if ($value === '') {
                $config->deleteAppValue('learning', $key);
            } else {
                $config->setAppValue('learning', $key, $value);
            }
        }
    }

    private function uid(string $role): string {
        return $this->users[$role]->getUID();
    }

    public function testTheLastFailedAttemptBecomesAnActivityForTheInstructorsOnly(): void {
        $apps = Server::get(IAppManager::class);
        // Fail, never skip: without the activity app this test proves nothing.
        $this->assertTrue($apps->isEnabledForAnyone('activity'), 'the activity app ships enabled with Nextcloud');
        $apps->loadApp('activity');
        $this->assertSame(ExamAttemptsSetting::ID, Server::get(IActivityManager::class)->getSettingById(ExamAttemptsSetting::ID)->getIdentifier(), 'the settings row exists');

        $owner = $this->uid('owner');
        $co = $this->uid('co');
        $learner = $this->uid('learner');
        $limits = Server::get(ExamAttemptLimitService::class);

        // The learner's own request: the first failed attempt uses up nothing yet.
        Server::get(IUserSession::class)->setUser($this->users['learner']);
        $this->insertFailedExam(time() - 7200);
        $limits->afterExamCompleted($learner, $this->courseId, $this->poolId);
        $this->assertSame([], $this->rows('activity', 'affecteduser', [$owner, $co, $learner]), 'one attempt left: nothing yet');

        // The second (last) failed attempt.
        $this->insertFailedExam(time() - 60);
        $limits->afterExamCompleted($learner, $this->courseId, $this->poolId);
        // The refused next start checks again: once per event, nothing more.
        $limits->afterExamCompleted($learner, $this->courseId, $this->poolId);
        Server::get(IUserSession::class)->setUser(null);

        $rows = $this->rows('activity', 'affecteduser', [$owner, $co, $learner]);
        $this->assertSame([$co, $owner], $this->sorted(array_column($rows, 'affecteduser')), 'one activity per instructor, none for the learner');
        foreach ($rows as $row) {
            $this->assertSame(ExamAttemptsSetting::ID, $row['type']);
            $this->assertSame(ActivityPublisher::SUBJECT_EXAM_ATTEMPTS_EXHAUSTED, $row['subject']);
            $this->assertSame(ActivityPublisher::AUTHOR, $row['user']);
            $this->assertStringNotContainsString($learner, (string)$row['subjectparams'], 'no uid in the parameters');
            $this->assertStringNotContainsString('Lea', (string)$row['subjectparams'], 'no name stored: it is read when shown');
        }
        // Email on by default, for both.
        $this->assertSame([$co, $owner], $this->sorted(array_column($this->rows('activity_mq', 'amq_affecteduser', [$owner, $co, $learner]), 'amq_affecteduser')));
        // The bell path ran exactly once for this pool and cycle: its "already told" marker (a CLI
        // test run stores no notification rows; the recipients of the bell are the unit test's).
        $this->assertCount(1, $this->notifiedMarkers($learner));

        // The stream of each instructor, as the activity app builds it, in German, with the links.
        $members = rtrim(Server::get(IURLGenerator::class)->linkToRouteAbsolute('learning.page.index'), '/') . '/courses/' . $this->courseId . '/members';
        foreach ([$owner, $co] as $uid) {
            $entries = $this->streamEntries($uid);
            $this->assertCount(1, $entries, $uid);
            $this->assertSame('Lea Lernend hat alle Prüfungsversuche für „Löschmittel“ im Kurs Brandschutz 2026 aufgebraucht', $entries[0]['subject']);
            $rich = $entries[0]['subject_rich'][1];
            $this->assertSame($members, $rich['learner']['link'] ?? null, $uid);
            $this->assertSame($members, $rich['course']['link'] ?? null, $uid);
            $this->assertArrayNotHasKey('link', $rich['pool']);
            $this->assertSame([], array_diff_key($rich, ['learner' => 1, 'pool' => 1, 'course' => 1]));
        }
        $this->assertSame([], $this->streamEntries($learner));

        // The digest email, built as the activity app builds it.
        $this->assertSame(['<a href="' . $members . '">Lea Lernend</a> hat alle Prüfungsversuche für „<strong>Löschmittel</strong>“ im Kurs <a href="' . $members . '">Brandschutz 2026</a> aufgebraucht'], $this->digestItems($owner));

        // The co-instructor becomes a plain member: the name and the links go, the course stays.
        $qb = $this->db->getQueryBuilder();
        $qb->update('learning_course_members')->set('role', $qb->createNamedParameter('student'))
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($this->courseId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($co)))
            ->executeStatement();
        $this->assertSame(['<strong>Eine lernende Person</strong> hat alle Prüfungsversuche für „<strong>Löschmittel</strong>“ im Kurs <strong>Brandschutz 2026</strong> aufgebraucht'], $this->digestItems($co));
    }

    private function insertFailedExam(int $at): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_sessions')->values([
            'pool_id' => $qb->createNamedParameter($this->poolId, IQueryBuilder::PARAM_INT),
            'course_id' => $qb->createNamedParameter($this->courseId, IQueryBuilder::PARAM_INT),
            'user_id' => $qb->createNamedParameter($this->uid('learner')),
            'started_at' => $qb->createNamedParameter($at, IQueryBuilder::PARAM_INT),
            'completed_at' => $qb->createNamedParameter($at, IQueryBuilder::PARAM_INT),
            'total_questions' => $qb->createNamedParameter(4, IQueryBuilder::PARAM_INT),
            'correct_answers' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
            'mode' => $qb->createNamedParameter('exam'),
        ])->executeStatement();
    }

    /**
     * @param list<string> $uids
     * @return list<array<string, mixed>>
     */
    private function rows(string $table, string $column, array $uids): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($table)->where($qb->expr()->in($column, $qb->createNamedParameter($uids, IQueryBuilder::PARAM_STR_ARRAY)));
        $qb->andWhere($qb->expr()->eq($table === 'activity' ? 'app' : 'amq_appid', $qb->createNamedParameter('learning')));
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        return $rows;
    }

    /** @return list<string> the learner's "instructors were told" markers (ExamAttemptLimitService) */
    private function notifiedMarkers(string $uid): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('configkey')->from('preferences')
            ->where($qb->expr()->eq('userid', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->eq('appid', $qb->createNamedParameter('learning')))
            ->andWhere($qb->expr()->like('configkey', $qb->createNamedParameter('exam_attempts_notified_%')));
        $result = $qb->executeQuery();
        $keys = array_map('strval', $result->fetchAll(\PDO::FETCH_COLUMN));
        $result->closeCursor();
        return $keys;
    }

    /** @param list<mixed> $values @return list<string> */
    private function sorted(array $values): array {
        $values = array_map('strval', $values);
        sort($values);
        return $values;
    }

    /** @return list<array<string, mixed>> the user's Learning stream entries, as the activity app builds them */
    private function streamEntries(string $uid): array {
        $l = Server::get(IFactory::class)->get('activity', 'de');
        $groupHelper = new \OCA\Activity\GroupHelper($l, Server::get(IActivityManager::class), Server::get(IValidator::class), Server::get(LoggerInterface::class));
        $data = Server::get(\OCA\Activity\Data::class)->get($groupHelper, Server::get(\OCA\Activity\UserSettings::class), $uid, 0, 50, 'desc', 'all');
        return array_values(array_filter($data['data'], static fn (array $activity): bool => $activity['app'] === 'learning'));
    }

    /**
     * The HTML of each item of the user's next activity email, built from the mail queue the way
     * MailQueueHandler::sendEmailToUser() does (see LearningActivitiesTest::digestItems()).
     *
     * @return list<string>
     */
    private function digestItems(string $uid): array {
        $provider = new Provider(
            Server::get(IFactory::class),
            Server::get(IURLGenerator::class),
            $this->db,
            Server::get(CourseMapper::class),
            Server::get(CourseMemberMapper::class),
            Server::get(CoursePoolMapper::class),
            Server::get(IUserManager::class),
        );
        $handler = (new \ReflectionClass(\OCA\Activity\MailQueueHandler::class))->newInstanceWithoutConstructor();
        $toHtml = new \ReflectionMethod(\OCA\Activity\MailQueueHandler::class, 'getHTMLSubject');
        $items = [];
        foreach ($this->rows('activity_mq', 'amq_affecteduser', [$uid]) as $row) {
            $event = Server::get(IActivityManager::class)->generateEvent();
            $event->setApp((string)$row['amq_appid'])
                ->setType((string)$row['amq_type'])
                ->setAffectedUser((string)$row['amq_affecteduser'])
                ->setTimestamp((int)$row['amq_timestamp'])
                ->setSubject((string)$row['amq_subject'], (array)json_decode((string)$row['amq_subjectparams'], true))
                ->setObject((string)$row['object_type'], (int)$row['object_id']);
            $provider->parse('de', $event);
            $this->assertTrue($event->isValidParsed(), 'valid for the activity app: ' . $row['amq_subject']);
            $items[] = (string)$toHtml->invoke($handler, $event);
        }
        return $items;
    }
}
