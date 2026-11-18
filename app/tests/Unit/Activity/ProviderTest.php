<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Activity;

use OCA\Learning\Activity\ActivityPublisher;
use OCA\Learning\Activity\Provider;
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
        return new Provider($l10nFactory, $urlGenerator, $db);
    }

    private function makeEvent(string $app, string $subject, array $params = [], bool $stubSetters = true): IEvent {
        $event = $this->createMock(IEvent::class);
        $event->method('getApp')->willReturn($app);
        $event->method('getSubject')->willReturn($subject);
        $event->method('getSubjectParameters')->willReturn($params);
        if ($stubSetters) {
            $event->method('setParsedSubject')->willReturnSelf();
            $event->method('setIcon')->willReturnSelf();
            $event->method('setLink')->willReturnSelf();
        }
        return $event;
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
        $parsed = [];
        $event = $this->makeEvent('learning', $subject, $params, false);
        $event->method('setParsedSubject')->willReturnCallback(function (string $value) use (&$parsed, $event) {
            $parsed['subject'] = $value;
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

        $this->makeProvider()->parse('de', $event);

        $this->assertSame($text, $parsed['subject'] ?? null);
        $this->assertSame('https://nc.example/apps/learning' . $path, $parsed['link'] ?? null);
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
}
