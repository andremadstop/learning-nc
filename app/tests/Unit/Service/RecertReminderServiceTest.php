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
}
