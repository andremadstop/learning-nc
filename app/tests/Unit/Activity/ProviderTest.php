<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Activity;

use OCA\Learning\Activity\ActivityPublisher;
use OCA\Learning\Activity\Provider;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMember;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CoursePool;
use OCA\Learning\Db\CoursePoolMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

/**
 * Codeberg #4 (second item): Provider::parse() threw the bare \InvalidArgumentException for
 * events it does not know. Nextcloud deprecated that in NC 30 — since then the activity app
 * logs a warning on every stream load ("threw \InvalidArgumentException which is deprecated")
 * for each foreign event it hands us, which on a busy instance is one warning per provider
 * per request. The contract is now \OCP\Activity\Exceptions\UnknownActivityException.
 *
 * Notification\Notifier was migrated to UnknownNotificationException (the same NC-30 cohort)
 * but this provider was missed — hence a test per throw site so the pair cannot drift again.
 *
 * Both tests go RED against the pre-fix code: UnknownActivityException does not extend
 * InvalidArgumentException, so expectException() fails on the old throw.
 */
#[AllowMockObjectsWithoutExpectations]
class ProviderTest extends TestCase {

    /** Course 7 exists, led by "teacher"; "learner" is a member; pool 3 belongs to it. */
    private ?CourseMapper $courseMapper = null;
    private ?CourseMemberMapper $memberMapper = null;
    private ?CoursePoolMapper $coursePoolMapper = null;

    protected function setUp(): void {
        $course = new Course();
        $course->setId(7);
        $course->setInstructorId('teacher');
        $this->courseMapper = $this->createMock(CourseMapper::class);
        $this->courseMapper->method('findById')->willReturnCallback(static function (int $id) use ($course): Course {
            if ($id !== 7) {
                throw new DoesNotExistException('no course');
            }
            return $course;
        });
        $this->memberMapper = $this->createMock(CourseMemberMapper::class);
        $this->memberMapper->method('findByCourseAndUser')->willReturnCallback(static function (int $courseId, string $uid): CourseMember {
            if ($courseId !== 7 || $uid !== 'learner') {
                throw new DoesNotExistException('no member');
            }
            return new CourseMember();
        });
        $this->coursePoolMapper = $this->createMock(CoursePoolMapper::class);
        $this->coursePoolMapper->method('findByCourseAndPool')->willReturnCallback(static function (int $courseId, int $poolId): CoursePool {
            if ($courseId !== 7 || $poolId !== 3) {
                throw new DoesNotExistException('not in the course');
            }
            return new CoursePool();
        });
    }

    /** @param list<array<string, mixed>|false> $announcementRows what the announcement reads return, in order */
    private function makeProvider(array $announcementRows = [['title' => 'Termin']], ?FakeDbConnection &$db = null): Provider {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
        $l->method('n')->willReturnCallback(static fn (string $one, string $many, int $count, array $params = []): string =>
            vsprintf(str_replace('%n', (string)$count, $count === 1 ? $one : $many), $params));

        $l10nFactory = $this->createMock(IFactory::class);
        $l10nFactory->method('get')->willReturn($l);

        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('imagePath')->willReturn('/apps/learning/img/app.svg');
        $urlGenerator->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://nc.example' . $path);
        $urlGenerator->method('linkToRouteAbsolute')->willReturn('https://nc.example/apps/learning');

        $db = new FakeDbConnection(array_map(static fn ($row) => new FakeQueryBuilder(FakeResult::fromFetch($row)), $announcementRows));
        return new Provider($l10nFactory, $urlGenerator, $db, $this->courseMapper, $this->memberMapper, $this->coursePoolMapper);
    }

    private function makeEvent(string $app, string $subject, array $params = [], bool $stubSetters = true, string $reader = 'learner'): IEvent {
        $event = $this->createMock(IEvent::class);
        $event->method('getApp')->willReturn($app);
        $event->method('getSubject')->willReturn($subject);
        $event->method('getSubjectParameters')->willReturn($params);
        $event->method('getAffectedUser')->willReturn($reader);
        $event->method('getObjectName')->willReturn($subject === 'badge_earned' ? 'week_streak' : '');
        if ($stubSetters) {
            $event->method('setParsedSubject')->willReturnSelf();
            $event->method('setRichSubject')->willReturnSelf();
            $event->method('setIcon')->willReturnSelf();
            $event->method('setLink')->willReturnSelf();
        }
        return $event;
    }

    /**
     * Parse one event and return what the provider set on it.
     *
     * @param array<string, int|string> $params
     * @return array{subject?: string, link?: string, icon?: string, rich?: string, richParams?: array<string, array<string, string>>}
     */
    private function render(string $subject, array $params, string $reader = 'learner', ?Provider $provider = null): array {
        $parsed = [];
        $event = $this->makeEvent('learning', $subject, $params, false, $reader);
        $event->method('setParsedSubject')->willReturnCallback(function (string $value) use (&$parsed, $event) {
            $parsed['subject'] = $value;
            return $event;
        });
        $event->method('setRichSubject')->willReturnCallback(function (string $value, array $richParams = []) use (&$parsed, $event) {
            $parsed['rich'] = $value;
            $parsed['richParams'] = $richParams;
            return $event;
        });
        $event->method('setLink')->willReturnCallback(function (string $value) use (&$parsed, $event) {
            $parsed['link'] = $value;
            return $event;
        });
        $event->method('setIcon')->willReturnCallback(function (string $value) use (&$parsed, $event) {
            $parsed['icon'] = $value;
            return $event;
        });
        ($provider ?? $this->makeProvider())->parse('de', $event);
        return $parsed;
    }

    /**
     * The hot path: the activity app calls every registered provider for every event, so this
     * throw fires for each foreign event. It is exactly the one the reporter saw in the log.
     */
    public function testForeignAppThrowsUnknownActivityException(): void {
        $this->expectException(UnknownActivityException::class);

        $this->makeProvider()->parse('en', $this->makeEvent('files', 'file_created'));
    }

    /**
     * Same contract for our own app's unknown subjects — an event written by an older or newer
     * version of this app that this Provider has no case for.
     */
    public function testUnknownSubjectThrowsUnknownActivityException(): void {
        $this->expectException(UnknownActivityException::class);

        $this->makeProvider()->parse('en', $this->makeEvent('learning', 'not_a_real_subject'));
    }

    /**
     * Guard: the migration must not have swallowed the one subject the provider does handle.
     */
    public function testKnownSubjectIsParsed(): void {
        $event = $this->makeEvent('learning', 'badge_earned', [
            'badge_name' => 'First Steps',
            'badge_emoji' => '🎉',
        ]);
        $event->expects($this->once())->method('setParsedSubject');

        $this->assertSame($event, $this->makeProvider()->parse('en', $event));
    }

    /**
     * GitHub #85: every subject the publisher writes is rendered — an unparsed one would be
     * dropped from the stream and from the email — with a text built from its own parameters,
     * an absolute link to the course (tab), and an absolute icon.
     *
     * @return array<string, array{string, array<string, int|string>, string, string}>
     */
    public static function subjects(): array {
        $course = ['course_id' => 7, 'course_title' => 'Brandschutz'];
        return [
            'enrolled' => ['course_enrolled', $course, 'Du wurdest in den Kurs Brandschutz eingeschrieben', '/courses/7'],
            'issued' => ['certificate_issued', $course, 'Dein Zertifikat für den Kurs Brandschutz wurde ausgestellt', '/courses/7/summary'],
            'expiring' => ['certificate_expiring', $course + ['days_left' => 30], 'Dein Zertifikat für den Kurs Brandschutz läuft in 30 Tagen ab', '/courses/7/summary'],
            'expiring 1' => ['certificate_expiring', $course + ['days_left' => 1], 'Dein Zertifikat für den Kurs Brandschutz läuft in 1 Tag ab', '/courses/7/summary'],
            'expired' => ['certificate_expired', $course, 'Dein Zertifikat für den Kurs Brandschutz ist abgelaufen', '/courses/7/summary'],
            'revoked' => ['certificate_revoked', $course, 'Dein Zertifikat für den Kurs Brandschutz wurde widerrufen', '/courses/7/summary'],
            'cycle' => ['certification_cycle_opened', $course, 'Im Kurs Brandschutz ist ein neuer Zertifizierungszyklus offen', '/courses/7/summary'],
            'compliance' => ['compliance_reminder', $course, 'Erinnerung an die Pflichtschulung im Kurs Brandschutz', '/courses/7'],
            'pool' => ['feed_pool_added', $course + ['pool_id' => 3, 'pool_name' => 'Pool A'], 'Neuer Fragenpool im Kurs Brandschutz: Pool A', '/courses/7/feed'],
            'announcement' => ['feed_announcement', $course + ['announcement_id' => 4], 'Neue Ankündigung im Kurs Brandschutz: Termin', '/courses/7/feed'],
            'badge' => ['badge_earned', ['badge_name' => 'Wochenserie', 'badge_emoji' => '*'], 'Achievement unlocked: * Wochenserie', ''],
        ];
    }

    /** @param array<string, int|string> $params */
    #[\PHPUnit\Framework\Attributes\DataProvider('subjects')]
    public function testEveryPublishedSubjectIsRendered(string $subject, array $params, string $text, string $path): void {
        $this->assertArrayHasKey($subject, ActivityPublisher::TYPES, 'a subject the publisher knows');
        $parsed = $this->render($subject, $params);

        $this->assertSame($text, $parsed['subject'] ?? null);
        $this->assertSame('https://nc.example/apps/learning' . ($subject === 'badge_earned' ? '/dashboard' : $path), $parsed['link'] ?? null);
        $this->assertSame('https://nc.example/apps/learning/img/app.svg', $parsed['icon'] ?? null);
    }

    public function testEverySubjectOfThePublisherHasACase(): void {
        $covered = array_unique(array_map(static fn (array $row): string => $row[0], self::subjects()));
        sort($covered);
        $subjects = array_keys(ActivityPublisher::TYPES);
        sort($subjects);
        $this->assertSame($subjects, $covered);
    }

    private function announcementSubject(array $announcementRows, array $params, ?FakeDbConnection &$db = null): ?string {
        $parsed = null;
        $event = $this->makeEvent('learning', 'feed_announcement', ['course_id' => 7, 'course_title' => 'Brandschutz'] + $params, false);
        $event->method('setParsedSubject')->willReturnCallback(function (string $value) use (&$parsed, $event) {
            $parsed = $value;
            return $event;
        });
        $event->method('setLink')->willReturnSelf();
        $event->method('setIcon')->willReturnSelf();
        $this->makeProvider($announcementRows, $db)->parse('de', $event);
        return $parsed;
    }

    /**
     * GitHub #85 (review): an announcement deleted a minute after posting left its title in every
     * member's stream (and in a not-yet-sent activity email). The title is read when the activity
     * is rendered: an edited announcement shows its new title — never one stored in the event —
     * and a deleted one names only the course.
     */
    public function testAnAnnouncementShowsItsCurrentTitleReadForThatCourse(): void {
        $db = null;
        $this->assertSame('Neue Ankündigung im Kurs Brandschutz: Termin neu', $this->announcementSubject([['title' => 'Termin neu']], ['announcement_id' => 4, 'title' => 'Alter Titel'], $db));
        $reads = array_values(array_filter($db->issuedBuilders, fn ($qb) => ($qb->from['table'] ?? null) === 'learning_course_announcements'));
        $this->assertCount(1, $reads);
        $this->assertSame([4, 7], array_column($reads[0]->namedParameters, 'value'), 'that announcement, in that course');
    }

    public function testADeletedAnnouncementLeavesNoTitle(): void {
        $this->assertSame('Neue Ankündigung im Kurs Brandschutz', $this->announcementSubject([false], ['announcement_id' => 4, 'title' => 'Prüfungsfragen Teil 2']));
    }

    /** An event from before the course id was stored opens the app instead of /courses/0. */
    public function testWithoutACourseIdTheLinkIsTheApp(): void {
        $link = null;
        $event = $this->makeEvent('learning', 'course_enrolled', ['course_title' => 'X'], false);
        $event->method('setParsedSubject')->willReturnSelf();
        $event->method('setIcon')->willReturnSelf();
        $event->method('setLink')->willReturnCallback(function (string $value) use (&$link, $event) {
            $link = $value;
            return $event;
        });
        $this->makeProvider()->parse('de', $event);
        $this->assertSame('https://nc.example/apps/learning', $link);
    }

    /**
     * GitHub #85 (links): in the stream and in the activity email the names in a Learning
     * activity are links, as in other apps' activities. Per subject: the rich template, and each
     * placeholder's object with its link.
     *
     * @return array<string, array{string, array<string, int|string>, string, array<string, array<string, string>>}>
     */
    public static function richSubjects(): array {
        $course = ['course_id' => 7, 'course_title' => 'Brandschutz'];
        $base = 'https://nc.example/apps/learning';
        $c = static fn (string $path): array => ['course' => ['type' => 'highlight', 'id' => '7', 'name' => 'Brandschutz', 'link' => $base . $path]];
        return [
            'enrolled' => ['course_enrolled', $course, 'Du wurdest in den Kurs {course} eingeschrieben', $c('/courses/7')],
            'issued' => ['certificate_issued', $course, 'Dein Zertifikat für den Kurs {course} wurde ausgestellt', $c('/courses/7/summary')],
            'expiring' => ['certificate_expiring', $course + ['days_left' => 30], 'Dein Zertifikat für den Kurs {course} läuft in 30 Tagen ab', $c('/courses/7/summary')],
            'expired' => ['certificate_expired', $course, 'Dein Zertifikat für den Kurs {course} ist abgelaufen', $c('/courses/7/summary')],
            'revoked' => ['certificate_revoked', $course, 'Dein Zertifikat für den Kurs {course} wurde widerrufen', $c('/courses/7/summary')],
            'cycle' => ['certification_cycle_opened', $course, 'Im Kurs {course} ist ein neuer Zertifizierungszyklus offen', $c('/courses/7/summary')],
            'compliance' => ['compliance_reminder', $course, 'Erinnerung an die Pflichtschulung im Kurs {course}', $c('/courses/7')],
            'pool' => ['feed_pool_added', $course + ['pool_id' => 3, 'pool_name' => 'Pool A'], 'Neuer Fragenpool im Kurs {course}: {pool}',
                $c('/courses/7') + ['pool' => ['type' => 'highlight', 'id' => '3', 'name' => 'Pool A', 'link' => $base . '/pools?poolId=3']]],
            'announcement' => ['feed_announcement', $course + ['announcement_id' => 4], 'Neue Ankündigung im Kurs {course}: {announcement}',
                $c('/courses/7') + ['announcement' => ['type' => 'highlight', 'id' => '4', 'name' => 'Termin', 'link' => $base . '/courses/7/feed']]],
            'badge' => ['badge_earned', ['badge_name' => 'Wochenserie', 'badge_emoji' => '*'], 'Achievement unlocked: {emoji} {badge}', [
                'emoji' => ['type' => 'highlight', 'id' => 'emoji', 'name' => '*'],
                'badge' => ['type' => 'highlight', 'id' => 'week_streak', 'name' => 'Wochenserie', 'link' => $base . '/dashboard'],
            ]],
        ];
    }

    /**
     * @param array<string, int|string> $params
     * @param array<string, array<string, string>> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('richSubjects')]
    public function testEverySubjectNamesItsObjectsAsLinks(string $subject, array $params, string $template, array $expected): void {
        $parsed = $this->render($subject, $params);
        $this->assertSame($template, $parsed['rich'] ?? null);
        $this->assertSame($expected, $parsed['richParams'] ?? null);
        // Nextcloud's rich object validator drops an event with a non-string value, and with it
        // the entry in the stream and in the email.
        foreach ($parsed['richParams'] as $parameter) {
            foreach ($parameter as $value) {
                $this->assertIsString($value);
            }
        }
    }

    public function testEverySubjectOfThePublisherHasARichCase(): void {
        $covered = array_keys(array_flip(array_map(static fn (array $row): string => $row[0], self::richSubjects())));
        sort($covered);
        $subjects = array_keys(ActivityPublisher::TYPES);
        sort($subjects);
        $this->assertSame($subjects, $covered);
    }

    /**
     * The activity email puts the rich template into its HTML unescaped and escapes only the
     * names (MailQueueHandler::getHTMLSubject()): no stored title may become part of the template.
     */
    public function testNoStoredTitleEverBecomesPartOfTheTemplate(): void {
        $evil = '<img src=x onerror=alert(1)>&"{pool}';
        foreach ([
            ['course_enrolled', ['course_id' => 7, 'course_title' => $evil]],
            ['feed_pool_added', ['course_id' => 7, 'course_title' => $evil, 'pool_id' => 3, 'pool_name' => $evil]],
            ['badge_earned', ['badge_name' => $evil, 'badge_emoji' => $evil]],
        ] as [$subject, $params]) {
            $parsed = $this->render($subject, $params);
            $this->assertStringNotContainsString('<img', $parsed['rich'], $subject);
            $this->assertStringNotContainsString('onerror', $parsed['rich'], $subject);
        }
        $parsed = $this->render('feed_announcement', ['course_id' => 7, 'course_title' => 'A', 'announcement_id' => 4], 'learner', $this->makeProvider([['title' => $evil]]));
        $this->assertStringNotContainsString('<img', $parsed['rich']);
        $this->assertSame($evil, $parsed['richParams']['announcement']['name']);
        // A name that reads like a placeholder is not replaced again in the plain text.
        $parsed = $this->render('feed_pool_added', ['course_id' => 7, 'course_title' => 'K {pool}', 'pool_id' => 3, 'pool_name' => 'P']);
        $this->assertSame('Neuer Fragenpool im Kurs K {pool}: P', $parsed['subject']);
    }

    /**
     * A deleted course, or one the reader is no longer in: the name stays as before, but nothing
     * links to a course page that would only say "not available" — the activity opens the app.
     *
     * @return array<string, array{string, string}>
     */
    public static function unreachable(): array {
        return [
            'deleted course' => ['learner', 'course_id 8'],
            'left the course' => ['someone-else', 'course_id 7'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unreachable')]
    public function testACourseTheReaderNoLongerReachesHasNoLinks(string $reader, string $which): void {
        $courseId = (int)substr($which, strlen('course_id '));
        foreach (['course_enrolled', 'certificate_issued', 'feed_pool_added', 'feed_announcement'] as $subject) {
            $parsed = $this->render($subject, ['course_id' => $courseId, 'course_title' => 'Brandschutz', 'pool_id' => 3, 'pool_name' => 'Pool A', 'announcement_id' => 4], $reader);
            $this->assertStringContainsString('Brandschutz', $parsed['subject'], $subject);
            $this->assertSame('https://nc.example/apps/learning', $parsed['link'], $subject);
            $this->assertSame('Brandschutz', $parsed['richParams']['course']['name'], $subject);
            foreach ($parsed['richParams'] as $key => $parameter) {
                $this->assertArrayNotHasKey('link', $parameter, $subject . ' ' . $key);
            }
        }
    }

    public function testTheCourseLeaderReachesTheCourseWithoutAMembership(): void {
        $parsed = $this->render('certificate_issued', ['course_id' => 7, 'course_title' => 'Brandschutz'], 'teacher');
        $this->assertSame('https://nc.example/apps/learning/courses/7/summary', $parsed['richParams']['course']['link']);
    }

    public function testAPoolNoLongerInTheCourseIsNotLinked(): void {
        $parsed = $this->render('feed_pool_added', ['course_id' => 7, 'course_title' => 'Brandschutz', 'pool_id' => 9, 'pool_name' => 'Weg']);
        $this->assertArrayNotHasKey('link', $parsed['richParams']['pool']);
        $this->assertSame('https://nc.example/apps/learning/courses/7', $parsed['richParams']['course']['link']);
    }

    /** A database error while checking never breaks the stream: the entry renders without links. */
    public function testALookupFailureRendersWithoutLinks(): void {
        $this->courseMapper = $this->createMock(CourseMapper::class);
        $this->courseMapper->method('findById')->willThrowException(new \RuntimeException('db down'));
        $parsed = $this->render('course_enrolled', ['course_id' => 7, 'course_title' => 'Brandschutz']);
        $this->assertSame('Du wurdest in den Kurs Brandschutz eingeschrieben', $parsed['subject']);
        $this->assertArrayNotHasKey('link', $parsed['richParams']['course']);
        $this->assertSame('https://nc.example/apps/learning', $parsed['link']);
    }

    /** An event stored without the course title keeps its plain text and gets no empty link. */
    public function testWithoutATitleThereIsNoRichSubject(): void {
        $parsed = $this->render('course_enrolled', ['course_id' => 7]);
        $this->assertSame('Du wurdest in den Kurs  eingeschrieben', $parsed['subject']);
        $this->assertArrayNotHasKey('rich', $parsed);
        $this->assertSame('https://nc.example/apps/learning/courses/7', $parsed['link']);
    }

    /** One lookup per course and reader in a request, not one per stream entry. */
    public function testTheAccessCheckRunsOncePerCourseAndReader(): void {
        $this->courseMapper = $this->createMock(CourseMapper::class);
        $course = new Course();
        $course->setId(7);
        $course->setInstructorId('teacher');
        $this->courseMapper->expects($this->exactly(2))->method('findById')->willReturn($course);
        $provider = $this->makeProvider();
        $this->render('course_enrolled', ['course_id' => 7, 'course_title' => 'A'], 'learner', $provider);
        $this->render('certificate_issued', ['course_id' => 7, 'course_title' => 'A'], 'learner', $provider);
        $this->render('certificate_issued', ['course_id' => 7, 'course_title' => 'A'], 'teacher', $provider);
    }
}
