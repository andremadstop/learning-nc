<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Certificate;
use OCA\Learning\Db\CertificateMapper;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\RecertReminderMapper;
use OCA\Learning\Service\RecertReminderService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Notification\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Phase 164 (RECERT-06) — RecertReminderService reminder idempotency.
 */
class RecertReminderServiceTest extends TestCase {

    /**
     * Reminder fires EXACTLY ONCE per (certId, threshold) across multiple job runs (RECERT-06).
     *
     * Mechanism:
     *   Run 1 — RecertReminderMapper::insertOnce(certId, 30, now) returns true (first send)
     *            → IManager::notify() called with a 'recert_reminder' notification.
     *   Run 2 — insertOnce catches UNIQUE(cert_id, threshold_days) violation → returns false
     *            ("already sent") → notify skipped.
     *   Net: IManager::notify called exactly once per (cert, threshold) pair.
     *
     * Fixture: cert expires now + 20 days — inside the T-30 window but OUTSIDE T-7, so each run
     * evaluates exactly ONE due threshold (insertOnce total = 2 across both runs).
     *
     * Clock: ITimeFactory pinned to a fixed "now" so T-30 / T-7 thresholds are deterministic.
     *
     * (164-06 harness note: the finder findActiveExpiringBetween is stubbed to yield the cert —
     * the idempotency lock itself is unchanged: exactly one notify across two runs.)
     */
    public function testOncePerThreshold(): void {
        $certMapper   = $this->createMock(CertificateMapper::class);
        $reminderMapper = $this->createMock(RecertReminderMapper::class);
        $notifManager = $this->createMock(IManager::class);
        $time         = $this->createMock(ITimeFactory::class);
        $config       = $this->createMock(IConfig::class);
        $logger       = $this->createMock(LoggerInterface::class);
        $courseMapper = $this->createMock(CourseMapper::class);

        // Fixed "now" — T-30 / T-7 window math must produce deterministic thresholds.
        $now = 1750000000;
        $time->method('getTime')->willReturn($now);

        // Threshold config: echo the shipped default ("30,7").
        $config->method('getAppValue')->willReturnCallback(
            static fn (string $app, string $key, string $default = ''): string => $default
        );

        // One active cert, 20 days before expiry: T-30 is due, T-7 is not.
        $cert = new Certificate();
        $cert->setId(42);
        $cert->setVerificationId('vid-recert-0001');
        $cert->setUserId('jmueller');
        $cert->setCourseId(7);
        $cert->setExpiresAt($now + 20 * 86400);
        $certMapper->method('findActiveExpiringBetween')->willReturn([$cert]);

        // GREEN contract: first send returns true (inserted), second hits UNIQUE → false.
        $insertOnceCalls = [];
        $reminderMapper->method('insertOnce')
            ->willReturnCallback(function (int $certId, int $days, int $sentAt) use (&$insertOnceCalls): bool {
                $insertOnceCalls[] = [$certId, $days];
                return count($insertOnceCalls) === 1; // run 1 → true, run 2 → false (UNIQUE)
            });

        // Exactly one notification across both runs — idempotency invariant.
        $notif = $this->createMock(\OCP\Notification\INotification::class);
        foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject'] as $m) {
            $notif->method($m)->willReturnSelf();
        }
        $notifManager->method('createNotification')->willReturn($notif);
        $notifManager->expects($this->once())
            ->method('notify');

        $service = new RecertReminderService(
            $certMapper,
            $reminderMapper,
            $notifManager,
            $time,
            $config,
            $logger,
            $courseMapper
        );

        // Two runs — idempotency means notify fires only once.
        $sent1 = $service->sendRecertReminders();
        $sent2 = $service->sendRecertReminders();

        $this->assertSame(1, $sent1, 'run 1 sends the T-30 reminder');
        $this->assertSame(0, $sent2, 'run 2 is deduped by the UNIQUE slot');
        $this->assertSame([[42, 30], [42, 30]], $insertOnceCalls,
            'only the due T-30 threshold is attempted (T-7 not yet reached), once per run');
    }

    /**
     * 164-07 post-impl review HIGH 3 (delivery compensation): when insertOnce commits the
     * idempotency row but IManager::notify() throws, the slot MUST be re-opened
     * (deleteByCertAndThreshold) — otherwise every future run skips the threshold and the
     * compliance reminder is silently never delivered. The next run then retries and succeeds.
     */
    public function testNotifyFailureReopensSlotForRetry(): void {
        $certMapper     = $this->createMock(CertificateMapper::class);
        $reminderMapper = $this->createMock(RecertReminderMapper::class);
        $notifManager   = $this->createMock(IManager::class);
        $time           = $this->createMock(ITimeFactory::class);
        $config         = $this->createMock(IConfig::class);
        $logger         = $this->createMock(LoggerInterface::class);
        $courseMapper   = $this->createMock(CourseMapper::class);

        $now = 1750000000;
        $time->method('getTime')->willReturn($now);
        $config->method('getAppValue')->willReturnCallback(
            static fn (string $app, string $key, string $default = ''): string => $default
        );

        $cert = new Certificate();
        $cert->setId(42);
        $cert->setVerificationId('vid-recert-0001');
        $cert->setUserId('jmueller');
        $cert->setCourseId(7);
        $cert->setExpiresAt($now + 20 * 86400); // only T-30 due
        $certMapper->method('findActiveExpiringBetween')->willReturn([$cert]);

        // The slot re-opens after the failed delivery, so BOTH runs insert successfully.
        $reminderMapper->method('insertOnce')->willReturn(true);
        // Compensation lock: the failed run must delete exactly its own (cert, threshold) row.
        $reminderMapper->expects($this->once())
            ->method('deleteByCertAndThreshold')
            ->with(42, 30);

        $notif = $this->createMock(\OCP\Notification\INotification::class);
        foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject'] as $m) {
            $notif->method($m)->willReturnSelf();
        }
        $notifManager->method('createNotification')->willReturn($notif);
        // Run 1: delivery fails. Run 2 (retry): delivery succeeds.
        $notifyCalls = 0;
        $notifManager->expects($this->exactly(2))->method('notify')
            ->willReturnCallback(function () use (&$notifyCalls): void {
                if (++$notifyCalls === 1) {
                    throw new \RuntimeException('notification backend down');
                }
            });

        $service = new RecertReminderService(
            $certMapper, $reminderMapper, $notifManager, $time, $config, $logger, $courseMapper
        );

        $sent1 = $service->sendRecertReminders(); // failed delivery → slot re-opened, 0 sent
        $sent2 = $service->sendRecertReminders(); // retry → delivered

        $this->assertSame(0, $sent1, 'a failed delivery must not count as sent');
        $this->assertSame(1, $sent2, 'the re-opened slot is retried and delivered on the next run');
    }
}
