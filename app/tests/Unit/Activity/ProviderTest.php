<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Activity;

use OCA\Learning\Activity\Provider;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

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
class ProviderTest extends TestCase {

    private function makeProvider(): Provider {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnArgument(0);

        $l10nFactory = $this->createMock(IFactory::class);
        $l10nFactory->method('get')->willReturn($l);

        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('imagePath')->willReturn('/apps/learning/img/app.svg');
        $urlGenerator->method('linkToRouteAbsolute')->willReturn('https://nc.example/apps/learning');

        return new Provider($l10nFactory, $urlGenerator);
    }

    private function makeEvent(string $app, string $subject, array $params = []): IEvent {
        $event = $this->createMock(IEvent::class);
        $event->method('getApp')->willReturn($app);
        $event->method('getSubject')->willReturn($subject);
        $event->method('getSubjectParameters')->willReturn($params);
        $event->method('setParsedSubject')->willReturnSelf();
        $event->method('setIcon')->willReturnSelf();
        $event->method('setLink')->willReturnSelf();
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
}
