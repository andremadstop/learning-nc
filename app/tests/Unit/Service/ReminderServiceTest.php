<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\LeitnerService;
use OCA\Learning\Service\ReminderService;
use OCA\Learning\Service\StreakService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

/**
 * RBAC-04 + Locked Decision 2: sendComplianceReminder contract.
 *
 * Wave-0 RED suite — testSendComplianceReminderDispatches and
 * testComplianceReminderIgnoresNotificationOptOut are expected to FAIL against the
 * skeleton (which returns false and never touches INotificationManager). They flip
 * GREEN in 163-06 when the real dispatch logic is implemented.
 *
 * Key locked decision: sendComplianceReminder MUST NOT gate on notificationsEnabled().
 * Mandatory notices bypass the user's voluntary notification opt-out.
 */
#[AllowMockObjectsWithoutExpectations]
class ReminderServiceTest extends TestCase {

    private function makeFluentNotification(): INotification {
        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturnSelf();
        $notification->method('setUser')->willReturnSelf();
        $notification->method('setObject')->willReturnSelf();
        $notification->method('setSubject')->willReturnSelf();
        $notification->method('setDateTime')->willReturnSelf();
        return $notification;
    }

    private function makeService(INotificationManager $manager, ?IConfig $config = null, ?\OCA\Learning\Activity\ActivityPublisher $activity = null): ReminderService {
        return new ReminderService(
            $this->createMock(IDBConnection::class),
            $manager,
            $config ?? $this->createMock(IConfig::class),
            $this->createMock(StreakService::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(LeitnerService::class),
            $activity ?? $this->createMock(\OCA\Learning\Activity\ActivityPublisher::class),
        );
    }

    /**
     * GitHub #85: a delivered compliance reminder is also an activity (email on by default),
     * with the course only — the notification's parameters name the lead, the activity must not.
     * A reminder the bell already holds publishes nothing more.
     */
    public function testAComplianceReminderIsAlsoAnActivityWithoutTheLead(): void {
        $manager = $this->createMock(INotificationManager::class);
        $manager->method('createNotification')->willReturn($this->makeFluentNotification());
        $manager->method('getCount')->willReturnOnConsecutiveCalls(0, 1);
        $activities = [];
        $activity = $this->createMock(\OCA\Learning\Activity\ActivityPublisher::class);
        $activity->method('toUser')->willReturnCallback(function (...$args) use (&$activities): void {
            $activities[] = $args;
        });

        $svc = $this->makeService($manager, null, $activity);
        $this->assertTrue($svc->sendComplianceReminder('alice', 42, ['lead_user_id' => 'lead-bob']));
        $this->assertFalse($svc->sendComplianceReminder('alice', 42, ['lead_user_id' => 'lead-bob']));

        $this->assertSame([['compliance_reminder', 'alice', 42, null, []]], $activities);
    }

    /**
     * RBAC-04: sendComplianceReminder MUST dispatch a notification via INotificationManager.
     *
     * RED at Wave 0: skeleton returns false and never calls notify().
     * The unmet expects($this->once())->method('notify') causes a test FAILURE at teardown.
     * GREEN after 163-06 implements the dispatch.
     */
    public function testSendComplianceReminderDispatches(): void {
        $manager = $this->createMock(INotificationManager::class);
        $manager->method('createNotification')->willReturn($this->makeFluentNotification());
        $manager->method('getCount')->willReturn(0); // no duplicate — should dispatch
        $manager->expects($this->once())->method('notify');

        $svc = $this->makeService($manager);
        $result = $svc->sendComplianceReminder('alice', 42, ['course_title' => 'Arbeitsschutz 2026']);

        $this->assertTrue($result, 'sendComplianceReminder must return true on successful dispatch');
    }

    /**
     * Locked decision 2: compliance reminder MUST be dispatched EVEN WHEN the user has
     * voluntarily disabled notifications (notificationsEnabled() would return false).
     *
     * A future implementer copying the sibling voluntary-opt-out gate into
     * sendComplianceReminder would immediately break this locking test.
     *
     * RED at Wave 0: skeleton returns false regardless of user config.
     * GREEN after 163-06.
     */
    public function testComplianceReminderIgnoresNotificationOptOut(): void {
        // Simulate opt-out: any getUserValue for notifications_enabled returns 'no'
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturn('no');

        $manager = $this->createMock(INotificationManager::class);
        $manager->method('createNotification')->willReturn($this->makeFluentNotification());
        $manager->method('getCount')->willReturn(0);
        $manager->expects($this->once())->method('notify');

        $svc = $this->makeService($manager, $config);
        $result = $svc->sendComplianceReminder('alice', 42, ['course_title' => 'Pflichtschulung']);

        $this->assertTrue($result, 'compliance reminder must fire even when opt-out is set');
    }

    /**
     * USER-01 structural lock: ReminderService has NO IUserManager dependency.
     * getEMailAddress() is therefore structurally impossible to call from this service —
     * the email-null safety guarantee is enforced by the constructor signature, not by a
     * runtime check.
     *
     * Verified via reflection on constructor parameter types.
     * GREEN at Wave 0 (structural guard — no implementation change needed).
     */
    public function testComplianceReminderEmailNullSafe(): void {
        $rc = new \ReflectionClass(ReminderService::class);
        $params = $rc->getConstructor()?->getParameters() ?? [];
        $paramTypes = array_map(static fn(\ReflectionParameter $p): string => (string)($p->getType() ?? ''), $params);

        $this->assertNotContains(
            'OCP\IUserManager',
            $paramTypes,
            'ReminderService MUST NOT inject IUserManager — email access must be structurally impossible'
        );
    }

    /**
     * 2026-11 audit S03: the course tab saves the exam date from <input type=datetime-local>,
     * so exam_date reads 'YYYY-MM-DDTHH:MM'. The reminder job compared and parsed it as a plain
     * date and never reminded anyone. The SQL side is covered by
     * tests/Integration/ExamReminderTargetsTest; this covers the parsing, the marker and the
     * notification object id, which keep their date-only format.
     */
    public function testExamDateWithTimeGetsReminderThreeDaysAhead(): void {
        $examDay = (new \DateTimeImmutable('today'))->modify('+3 days');
        $rows = [[
            'user_id' => 'alice',
            'course_id' => 5,
            'course_title' => 'Network+',
            'exam_date' => $examDay->format('Y-m-d') . 'T09:00',
        ]];
        $db = new \OCA\Learning\Tests\Support\FakeDbConnection([
            new \OCA\Learning\Tests\Support\FakeQueryBuilder(\OCA\Learning\Tests\Support\FakeResult::fromFetchAll($rows)),
        ]);

        $notification = $this->makeFluentNotification();
        $notification->expects($this->once())->method('setObject')
            ->with('exam', '5-' . $examDay->format('Ymd') . '-3')
            ->willReturnSelf();
        $notification->expects($this->once())->method('setSubject')
            ->with('exam_reminder', ['days' => 3, 'course_title' => 'Network+'])
            ->willReturnSelf();
        $manager = $this->createMock(INotificationManager::class);
        $manager->method('createNotification')->willReturn($notification);
        $manager->method('getCount')->willReturn(0);
        $manager->expects($this->once())->method('notify');

        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturnCallback(
            static fn (string $user, string $app, string $key, mixed $default = ''): mixed => $default
        );
        $config->expects($this->once())->method('setUserValue')
            ->with('alice', 'learning', 'last_exam_reminder_5', $examDay->format('Y-m-d') . ':3');

        $service = new ReminderService(
            $db,
            $manager,
            $config,
            $this->createMock(StreakService::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(LeitnerService::class),
            $this->createMock(\OCA\Learning\Activity\ActivityPublisher::class),
        );

        $this->assertSame(1, $service->sendExamReminders());
    }
    /**
     * GitHub #39: the daily due reminder counted the Leitner cards of every pool, also of pools
     * whose courses switched Leitner off, while the Smart Queue leaves those out. It announced
     * "40 due cards" for a queue that then was empty.
     */
    public function testDueReminderLeavesOutPoolsTheCoursesClosedForLeitner(): void {
        $rows = [
            ['user_id' => 'alice', 'pool_id' => 43, 'cnt' => 2],
            ['user_id' => 'alice', 'pool_id' => 42, 'cnt' => 40],
            ['user_id' => 'bob', 'pool_id' => 42, 'cnt' => 7],
        ];
        $db = new \OCA\Learning\Tests\Support\FakeDbConnection([
            new \OCA\Learning\Tests\Support\FakeQueryBuilder(new \OCA\Learning\Tests\Support\FakeResult($rows)),
        ]);

        $notification = $this->makeFluentNotification();
        $notification->expects($this->once())->method('setSubject')
            ->with('due_reminder', ['due_count' => 2])
            ->willReturnSelf();
        $notification->expects($this->once())->method('setUser')->with('alice')->willReturnSelf();
        $manager = $this->createMock(INotificationManager::class);
        $manager->method('createNotification')->willReturn($notification);
        $manager->method('getCount')->willReturn(0);
        $manager->expects($this->once())->method('notify');

        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturnCallback(
            static fn (string $user, string $app, string $key, mixed $default = ''): mixed => $default
        );

        $leitner = $this->createMock(LeitnerService::class);
        $leitner->method('getLeitnerClosedPoolIds')->willReturnMap([
            ['alice', [42]],
            ['bob', [42]],
        ]);

        $service = new ReminderService(
            $db,
            $manager,
            $config,
            $this->createMock(StreakService::class),
            $this->createMock(LoggerInterface::class),
            $leitner,
            $this->createMock(\OCA\Learning\Activity\ActivityPublisher::class),
        );

        $this->assertSame(1, $service->sendDueCardReminders());
    }
}
