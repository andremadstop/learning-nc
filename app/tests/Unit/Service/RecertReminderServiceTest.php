<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Certificate;
use OCA\Learning\Db\CertificateMapper;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\RecertReminder;
use OCA\Learning\Db\RecertReminderMapper;
use OCA\Learning\Service\RecertReminderService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Phase 164 (RECERT-06) — RecertReminderService reminder idempotency (outbox pattern).
 *
 * Outbox semantics (164-07 review pass 2): insertOnce = CLAIM (sent_at), markDelivered =
 * PROOF (delivered_at). A claim without proof is retried once stale — the row's mere
 * existence is never treated as "sent".
 */
class RecertReminderServiceTest extends TestCase {

    private const NOW = 1750000000;

    /** @return array{RecertReminderService, array<string, object>} service + mocks by name */
    private function makeService(int|callable $clock): array {
        $mocks = [
            'certMapper'     => $this->createMock(CertificateMapper::class),
            'reminderMapper' => $this->createMock(RecertReminderMapper::class),
            'notifManager'   => $this->createMock(IManager::class),
            'time'           => $this->createMock(ITimeFactory::class),
            'config'         => $this->createMock(IConfig::class),
            'logger'         => $this->createMock(LoggerInterface::class),
            'courseMapper'   => $this->createMock(CourseMapper::class),
        ];

        if (is_callable($clock)) {
            $mocks['time']->method('getTime')->willReturnCallback($clock);
        } else {
            $mocks['time']->method('getTime')->willReturn($clock);
        }

        // Threshold config: echo the shipped default ("30,7").
        $mocks['config']->method('getAppValue')->willReturnCallback(
            static fn (string $app, string $key, string $default = ''): string => $default
        );

        $notif = $this->createMock(INotification::class);
        foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject'] as $m) {
            $notif->method($m)->willReturnSelf();
        }
        $mocks['notifManager']->method('createNotification')->willReturn($notif);
        // getCount 0 = no identical notification in the bell yet (NC-level dedupe stays open)
        $mocks['notifManager']->method('getCount')->willReturn(0);

        $service = new RecertReminderService(
            $mocks['certMapper'],
            $mocks['reminderMapper'],
            $mocks['notifManager'],
            $mocks['time'],
            $mocks['config'],
            $mocks['logger'],
            $mocks['courseMapper'],
        );
        return [$service, $mocks];
    }

    /** One active cert, 20 days before expiry: T-30 is due, T-7 is not. */
    private function expiringCert(int $now): Certificate {
        $cert = new Certificate();
        $cert->setId(42);
        $cert->setVerificationId('vid-recert-0001');
        $cert->setUserId('jmueller');
        $cert->setCourseId(7);
        $cert->setExpiresAt($now + 20 * 86400);
        return $cert;
    }

    private function reminderRow(int $sentAt, ?int $deliveredAt): RecertReminder {
        $row = new RecertReminder();
        $row->setCertId(42);
        $row->setThresholdDays(30);
        $row->setSentAt($sentAt);
        $row->setDeliveredAt($deliveredAt);
        return $row;
    }

    /**
     * Reminder fires EXACTLY ONCE per (certId, threshold) across multiple job runs (RECERT-06).
     *
     * Run 1 — no row → claim (insertOnce) → notify → markDelivered.
     * Run 2 — delivered row found → permanent skip (no claim, no notify).
     * Fixture is inside T-30 but outside T-7, so exactly ONE threshold is evaluated per run.
     */
    public function testOncePerThreshold(): void {
        [$service, $m] = $this->makeService(self::NOW);
        $m['certMapper']->method('findActiveExpiringBetween')->willReturn([$this->expiringCert(self::NOW)]);

        $m['reminderMapper']->method('findByCertAndThreshold')
            ->willReturnOnConsecutiveCalls(null, $this->reminderRow(self::NOW, self::NOW));
        $m['reminderMapper']->expects($this->once())->method('insertOnce')
            ->with(42, 30, self::NOW)->willReturn(true);
        $m['reminderMapper']->expects($this->once())->method('markDelivered')
            ->with(42, 30, self::NOW);
        $m['notifManager']->expects($this->once())->method('notify');

        $this->assertSame(1, $service->sendRecertReminders(), 'run 1 delivers the T-30 reminder');
        $this->assertSame(0, $service->sendRecertReminders(), 'run 2 skips the delivered row');
    }

    /**
     * 164-07 review pass 2 (outbox retry): a claim whose delivery FAILED leaves delivered_at
     * NULL — the next (daily) run finds the stale pending row and retries until delivery
     * succeeds. This survives even the failure mode where the old delete-compensation itself
     * would have failed: the row's existence is a claim, never a delivery proof.
     */
    public function testFailedDeliveryIsRetriedViaStalePendingClaim(): void {
        $clock = self::NOW;
        [$service, $m] = $this->makeService(function () use (&$clock): int {
            return $clock;
        });
        $m['certMapper']->method('findActiveExpiringBetween')->willReturnCallback(
            fn (): array => [$this->expiringCert(self::NOW)]
        );

        // Run 1: no row → claim wins. Run 2 (next day): stale pending claim from run 1.
        $m['reminderMapper']->method('findByCertAndThreshold')
            ->willReturnOnConsecutiveCalls(null, $this->reminderRow(self::NOW, null));
        $m['reminderMapper']->expects($this->once())->method('insertOnce')->willReturn(true);
        // Delivery proof only on the successful retry (run 2's clock).
        $m['reminderMapper']->expects($this->once())->method('markDelivered')
            ->with(42, 30, self::NOW + 86400);

        // Run 1: delivery fails. Run 2 (retry): delivery succeeds.
        $notifyCalls = 0;
        $m['notifManager']->expects($this->exactly(2))->method('notify')
            ->willReturnCallback(function () use (&$notifyCalls): void {
                if (++$notifyCalls === 1) {
                    throw new \RuntimeException('notification backend down');
                }
            });

        $sent1 = $service->sendRecertReminders(); // claim committed, delivery failed → 0
        $clock = self::NOW + 86400;               // next daily run
        $sent2 = $service->sendRecertReminders(); // stale pending → retried → delivered

        $this->assertSame(0, $sent1, 'a failed delivery must not count as sent');
        $this->assertSame(1, $sent2, 'the stale pending claim is retried and delivered next run');
    }

    /**
     * Concurrency guard: a FRESH pending claim (younger than the retry window) belongs to a
     * concurrent runner mid-delivery — it must NOT be retried (no double-bell window).
     */
    public function testFreshPendingClaimIsNotRetried(): void {
        [$service, $m] = $this->makeService(self::NOW);
        $m['certMapper']->method('findActiveExpiringBetween')->willReturn([$this->expiringCert(self::NOW)]);

        // Claimed 60s ago by another runner, not yet delivered.
        $m['reminderMapper']->method('findByCertAndThreshold')
            ->willReturn($this->reminderRow(self::NOW - 60, null));
        $m['reminderMapper']->expects($this->never())->method('insertOnce');
        $m['reminderMapper']->expects($this->never())->method('markDelivered');
        $m['notifManager']->expects($this->never())->method('notify');

        $this->assertSame(0, $service->sendRecertReminders(),
            'a fresh pending claim is left to its owning runner');
    }
}
