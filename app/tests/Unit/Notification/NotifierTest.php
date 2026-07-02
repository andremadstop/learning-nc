<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Notification;

use OCA\Learning\Notification\Notifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\TestCase;

/**
 * RBAC-04: Notifier::prepare() compliance_reminder contract.
 *
 * testComplianceReminderCasePrepares — RED at Wave 0 (skeleton throws UnknownNotificationException).
 * testUnknownSubjectStillThrows   — GREEN at Wave 0 (regression guard; default case unaffected).
 */
class NotifierTest extends TestCase {

    private function makeNotifier(): Notifier {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnArgument(0);

        $l10nFactory = $this->createMock(IFactory::class);
        $l10nFactory->method('get')->willReturn($l);

        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('linkToRouteAbsolute')->willReturn('https://nc.example/apps/learning');
        $urlGenerator->method('getAbsoluteURL')->willReturn('https://nc.example/img/app.svg');
        $urlGenerator->method('imagePath')->willReturn('/apps/learning/img/app.svg');

        return new Notifier($l10nFactory, $urlGenerator);
    }

    private function makeNotification(string $app, string $subject, array $params = []): INotification {
        $notification = $this->createMock(INotification::class);
        $notification->method('getApp')->willReturn($app);
        $notification->method('getSubject')->willReturn($subject);
        $notification->method('getSubjectParameters')->willReturn($params);
        $notification->method('setParsedSubject')->willReturnSelf();
        $notification->method('setLink')->willReturnSelf();
        $notification->method('setIcon')->willReturnSelf();
        return $notification;
    }

    /**
     * RBAC-04: prepare() with subject 'compliance_reminder' must return the notification with
     * a parsed subject set — it must NOT throw once 163-06 implements the l10n render.
     *
     * RED at Wave 0: the Wave-0 skeleton throws UnknownNotificationException, so
     * - setParsedSubject() is never called → expects($this->once()) is violated
     * - the uncaught exception causes the test to ERROR
     *
     * GREEN after 163-06 adds the l10n render and removes the throw.
     */
    public function testComplianceReminderCasePrepares(): void {
        $notifier = $this->makeNotifier();
        $notification = $this->makeNotification('learning', 'compliance_reminder', [
            'course_title' => 'Arbeitsschutz 2026',
            'deadline'     => '2026-12-31',
        ]);

        // This expectation will NOT be met at Wave 0 (skeleton throws before setParsedSubject).
        $notification->expects($this->once())->method('setParsedSubject');

        $result = $notifier->prepare($notification, 'de');

        $this->assertInstanceOf(INotification::class, $result,
            'prepare() must return the notification after rendering compliance_reminder');
    }

    /**
     * Regression guard: an unrecognized subject must still throw UnknownNotificationException.
     * Adding compliance_reminder must not affect the default branch.
     *
     * GREEN at Wave 0 and must remain GREEN forever.
     */
    public function testUnknownSubjectStillThrows(): void {
        $this->expectException(UnknownNotificationException::class);

        $notifier = $this->makeNotifier();
        $notification = $this->makeNotification('learning', 'completely_unknown_subject_xyz_163');
        $notifier->prepare($notification, 'de');
    }
}
