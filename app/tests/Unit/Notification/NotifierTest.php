<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Notification;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Notification\Notifier;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

/**
 * RBAC-04: Notifier::prepare() compliance_reminder contract.
 *
 * testComplianceReminderCasePrepares — RED at Wave 0 (skeleton throws UnknownNotificationException).
 * testUnknownSubjectStillThrows   — GREEN at Wave 0 (regression guard; default case unaffected).
 */
#[AllowMockObjectsWithoutExpectations]
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

    /**
     * GitHub #35: the certificate notification opens the course's completion tab
     * (/courses/<id>/summary), not the app root. Old notifications without course_id keep the root.
     */
    public function testCertificateIssuedLinksToCompletionTab(): void {
        $notifier = $this->makeNotifier();
        $notification = $this->makeNotification('learning', 'certificate_issued', [
            'course_title' => 'Arbeitsschutz 2026',
            'course_id' => 12,
        ]);
        $notification->expects($this->once())->method('setLink')
            ->with('https://nc.example/apps/learning/courses/12/summary')->willReturnSelf();

        $notifier->prepare($notification, 'de');
    }

    public function testCertificateIssuedWithoutCourseIdLinksToAppRoot(): void {
        $notifier = $this->makeNotifier();
        $notification = $this->makeNotification('learning', 'certificate_issued', ['course_title' => 'Alt']);
        $notification->expects($this->once())->method('setLink')
            ->with('https://nc.example/apps/learning')->willReturnSelf();

        $notifier->prepare($notification, 'de');
    }

    /**
     * GitHub #35: an instructor enrolled the person. Subject names the course, the link opens it.
     */
    public function testCourseEnrolledNamesTheCourseAndLinksToIt(): void {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(fn (string $text, array $p = []) => vsprintf($text, $p));
        $l10nFactory = $this->createMock(IFactory::class);
        $l10nFactory->expects($this->atLeastOnce())->method('get')->with('learning', 'fr')->willReturn($l);
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('linkToRouteAbsolute')->willReturn('https://nc.example/apps/learning/');
        $urlGenerator->method('getAbsoluteURL')->willReturn('https://nc.example/img/app.svg');
        $notifier = new Notifier($l10nFactory, $urlGenerator);

        $notification = $this->makeNotification('learning', 'course_enrolled', [
            'course_title' => 'Arbeitsschutz 2026',
            'course_id' => 12,
        ]);
        $notification->expects($this->once())->method('setParsedSubject')
            ->with('You were enrolled in the course Arbeitsschutz 2026')->willReturnSelf();
        $notification->expects($this->once())->method('setLink')
            ->with('https://nc.example/apps/learning/courses/12')->willReturnSelf();

        $notifier->prepare($notification, 'fr');
    }
    /**
     * GitHub #84 / #87: the revoke, the new cycle and the used-up attempts are rendered (before
     * 5.16.0 every one of them fell into the "Unknown subject" branch), with links to the course.
     */
    public function testCycleAndAttemptSubjectsRender(): void {
        $cases = [
            ['certificate_revoked', ['course_title' => 'Safety', 'course_id' => 7], '/courses/7/summary'],
            ['certification_cycle_opened', ['course_title' => 'Safety', 'course_id' => 7], '/courses/7/summary'],
            ['exam_attempts_exhausted', ['course_title' => 'Safety', 'course_id' => 7, 'pool_title' => 'Fire', 'member_id' => 31], '/courses/7/members'],
        ];
        foreach ($cases as [$subject, $params, $link]) {
            $notification = $this->makeNotification('learning', $subject, $params);
            $notification->method('setParsedMessage')->willReturnSelf();
            $notification->expects($this->once())->method('setParsedSubject');
            $notification->expects($this->once())->method('setLink')->with($this->stringEndsWith($link))->willReturnSelf();
            $this->makeNotifier()->prepare($notification, 'de');
        }
    }

    /**
     * GitHub #87: the learner's current display name, resolved from the member row the
     * notification holds — never a uid (review C9: the uid stayed in oc_notifications after the
     * account was deleted, and a new account with that uid would have been named as the one who
     * failed). A row that is gone, or of another course, names nobody.
     */
    public function testAttemptsNotificationNamesTheLearnerByDisplayName(): void {
        $users = $this->createMock(\OCP\IUserManager::class);
        $anna = $this->createMock(\OCP\IUser::class);
        $anna->method('getDisplayName')->willReturn('Anna Berg');
        $users->method('get')->willReturnCallback(fn (string $uid) => $uid === 'anna' ? $anna : null);
        $members = $this->createMock(\OCA\Learning\Db\CourseMemberMapper::class);
        $members->method('findById')->willReturnCallback(function (int $id): \OCA\Learning\Db\CourseMember {
            if ($id !== 31 && $id !== 40) {
                throw new \OCP\AppFramework\Db\DoesNotExistException('gone');
            }
            $m = new \OCA\Learning\Db\CourseMember();
            $m->setId($id);
            $m->setUserId('anna');
            $m->setCourseId($id === 31 ? 7 : 99);
            return $m;
        });
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(fn (string $text, array $p = []) => $text . ($p === [] ? '' : '|' . implode('|', $p)));
        $factory = $this->createMock(IFactory::class);
        $factory->method('get')->willReturn($l);
        $url = $this->createMock(IURLGenerator::class);
        $url->method('linkToRouteAbsolute')->willReturn('https://nc.example/apps/learning');
        $notifier = new Notifier($factory, $url, $users, $members);

        foreach ([31 => 'Anna Berg', 32 => 'Eine lernende Person', 40 => 'Eine lernende Person', 0 => 'Eine lernende Person'] as $memberId => $name) {
            $n = $this->makeNotification('learning', 'exam_attempts_exhausted', ['course_title' => 'Safety', 'course_id' => 7, 'pool_title' => 'Fire', 'member_id' => $memberId]);
            $n->method('setParsedMessage')->willReturnSelf();
            $n->expects($this->once())->method('setParsedSubject')->with($this->callback(
                fn (string $text) => str_contains($text, '|' . $name . '|Fire|Safety')
            ))->willReturnSelf();
            $notifier->prepare($n, 'de');
        }
    }

    /**
     * GitHub #85 (pre-existing): the team lead's reminder carries no course title (only the
     * course id as object id), and the bell read "Compliance reminder: " with nothing after it.
     * The title is looked up from the course; a deleted course leaves the old text, not an error.
     */
    public function testComplianceReminderWithoutTitleNamesTheCourseFromItsId(): void {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(fn (string $text, array $p = []) => vsprintf($text, $p));
        $factory = $this->createMock(IFactory::class);
        $factory->method('get')->willReturn($l);
        $url = $this->createMock(IURLGenerator::class);
        $url->method('linkToRouteAbsolute')->willReturn('https://nc.example/apps/learning/');
        $url->method('getAbsoluteURL')->willReturn('https://nc.example/img/app.svg');

        $course = new Course();
        $course->setTitle('Arbeitsschutz 2026');
        $courses = $this->createMock(CourseMapper::class);
        $courses->method('findById')->willReturnCallback(
            fn (int $id) => $id === 42 ? $course : throw new DoesNotExistException('gone')
        );
        $notifier = new Notifier($factory, $url, null, null, $courses);

        $known = $this->makeNotification('learning', 'compliance_reminder', ['lead_user_id' => 'lead']);
        $known->method('getObjectId')->willReturn('42');
        $known->expects($this->once())->method('setParsedSubject')
            ->with('Compliance reminder: Arbeitsschutz 2026')->willReturnSelf();
        $notifier->prepare($known, 'de');

        $gone = $this->makeNotification('learning', 'compliance_reminder', ['lead_user_id' => 'lead']);
        $gone->method('getObjectId')->willReturn('7');
        $gone->expects($this->once())->method('setParsedSubject')
            ->with('Compliance reminder: ')->willReturnSelf();
        $notifier->prepare($gone, 'de');
    }
}
