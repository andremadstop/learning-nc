<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\ReminderService;
use OCA\Learning\Service\StreakService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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

    private function makeService(INotificationManager $manager, ?IConfig $config = null): ReminderService {
        return new ReminderService(
            $this->createMock(IDBConnection::class),
            $manager,
            $config ?? $this->createMock(IConfig::class),
            $this->createMock(StreakService::class),
            $this->createMock(LoggerInterface::class),
        );
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
}
