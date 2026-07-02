<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\CertificateMapper;
use OCA\Learning\Db\RecertReminderMapper;
use OCA\Learning\Service\RecertReminderService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\Notification\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Phase 164 (RECERT-06) — RecertReminderService reminder idempotency.
 *
 * Expected-RED: all tests in this file FAIL/ERROR against the no-op skeleton (impl in 164-06).
 */
class RecertReminderServiceTest extends TestCase {

    /**
     * Reminder fires EXACTLY ONCE per (certId, threshold) across multiple job runs (RECERT-06).
     *
     * Mechanism:
     *   Run 1 — RecertReminderMapper::insertOnce(certId, threshold, now) returns true (first send)
     *            → IManager::notify() called with a 'recert_reminder' notification.
     *   Run 2 — insertOnce catches UNIQUE(cert_id, threshold_days) violation → returns false
     *            ("already sent") → notify skipped.
     *   Net: IManager::notify called exactly once per (cert, threshold) pair.
     *
     * Clock: ITimeFactory pinned to a fixed "now" so T-30 / T-7 thresholds are deterministic.
     *
     * RED mechanism: RecertReminderService::sendRecertReminders() throws LogicException('164-06')
     *   on the first call → test ERRORS → RED.
     *   (In addition, IManager::notify was expected once but called 0 times → mock failure.)
     * GREEN trigger (164-06): method implemented; insertOnce idempotency prevents 2nd notify.
     */
    public function testOncePerThreshold(): void {
        $certMapper   = $this->createMock(CertificateMapper::class);
        $reminderMapper = $this->createMock(RecertReminderMapper::class);
        $notifManager = $this->createMock(IManager::class);
        $time         = $this->createMock(ITimeFactory::class);
        $config       = $this->createMock(IConfig::class);
        $logger       = $this->createMock(LoggerInterface::class);

        // Fixed "now" — T-30 / T-7 window math must produce deterministic thresholds.
        $now = 1750000000;
        $time->method('getTime')->willReturn($now);

        // GREEN contract: first send returns true (inserted), second hits UNIQUE → false.
        $reminderMapper->method('insertOnce')
            ->willReturnOnConsecutiveCalls(true, false);

        // Exactly one notification across both runs — idempotency invariant.
        $notifManager->expects($this->once())
            ->method('notify');

        $service = new RecertReminderService(
            $certMapper,
            $reminderMapper,
            $notifManager,
            $time,
            $config,
            $logger
        );

        // Two runs — idempotency means notify fires only once.
        // RED: sendRecertReminders() throws LogicException('164-06') → ERROR → RED.
        // GREEN (164-06): first run sends notification; second run's insertOnce returns false
        //   (UNIQUE violation caught) → no second notification.
        $service->sendRecertReminders();
        $service->sendRecertReminders();
    }
}
