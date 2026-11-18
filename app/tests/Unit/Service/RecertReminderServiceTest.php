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
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

/**
 * Phase 164 (RECERT-06) — RecertReminderService reminder idempotency (outbox pattern).
 *
 * Outbox semantics (164-07 review pass 2): insertOnce = CLAIM (sent_at), markDelivered =
 * PROOF (delivered_at). A claim without proof is retried once stale — the row's mere
 * existence is never treated as "sent".
 */
#[AllowMockObjectsWithoutExpectations]
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
            'activity'       => $this->createMock(\OCA\Learning\Activity\ActivityPublisher::class),
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
            $mocks['activity'],
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
     * GitHub #85: a delivered T-30 reminder is also a "certificate expiring" activity, once —
     * the second run skips both.
     */
    public function testADeliveredReminderIsAlsoAnExpiringActivity(): void {
        [$service, $m] = $this->makeService(self::NOW);
        $m['certMapper']->method('findActiveExpiringBetween')->willReturn([$this->expiringCert(self::NOW)]);
        $m['reminderMapper']->method('findByCertAndThreshold')
            ->willReturnOnConsecutiveCalls(null, $this->reminderRow(self::NOW, self::NOW));
        $m['reminderMapper']->method('insertOnce')->willReturn(true);
        $activities = [];
        $m['activity']->method('toUser')->willReturnCallback(function (...$args) use (&$activities): void {
            $activities[] = $args;
        });

        $service->sendRecertReminders();
        $service->sendRecertReminders();

        $this->assertSame([['certificate_expiring', 'jmueller', 7, '', ['days_left' => 30]]], $activities);
    }

    /**
     * GitHub #85: an active certificate that has expired gets one "expired" activity, claimed
     * through the reminder outbox with threshold 0 (which no reminder threshold can be); a claim
     * row already there means it was announced, and a renewed certificate (no longer active) is
     * not in the query at all.
     */
    public function testAnExpiredCertificateIsAnnouncedOnce(): void {
        [$service, $m] = $this->makeService(self::NOW);
        $expired = $this->expiringCert(self::NOW);
        $expired->setExpiresAt(self::NOW - 3600);
        $expired->setActiveIdemKey('jmueller:7');
        $windows = [];
        $m['certMapper']->method('findActiveExpiringBetween')->willReturn([]);
        $m['certMapper']->method('findExpiredBetween')->willReturnCallback(
            function (int $after, int $before) use ($expired, &$windows): array {
                $windows[] = [$after, $before];
                return [$expired];
            }
        );
        $claimed = null;
        $m['reminderMapper']->method('findByCertAndThreshold')->willReturnCallback(
            function (int $certId, int $days) use (&$claimed): ?RecertReminder {
                return $days === 0 ? $claimed : null;
            }
        );
        $m['reminderMapper']->expects($this->once())->method('insertOnce')->with(42, 0, self::NOW)
            ->willReturnCallback(function () use (&$claimed): bool {
                $claimed = $this->reminderRow(self::NOW, null);
                return true;
            });
        $m['reminderMapper']->expects($this->once())->method('markDelivered')->with(42, 0, self::NOW);
        $m['notifManager']->expects($this->never())->method('notify');
        $activities = [];
        $m['activity']->method('toUser')->willReturnCallback(function (...$args) use (&$activities): void {
            $activities[] = $args;
        });

        $this->assertSame(0, $service->sendRecertReminders(), 'no bell: the expired activity is not a reminder');
        $service->sendRecertReminders();

        $this->assertSame([['certificate_expired', 'jmueller', 7, null, []]], $activities);
        $this->assertSame([self::NOW - 7 * 86400, self::NOW], $windows[0], 'looks back one week');
    }

    /**
     * GitHub #85 review: with recert_grace_days = 0 the daily RecertPeriodCloseJob can release
     * the slot of an expired certificate before the reminder sweep runs (from 09:00). The
     * certificate is still the newest one for user + course, so the learner must still get the
     * "certificate expired" activity. Before the fix the sweep only read slot owners and stayed
     * silent.
     */
    public function testAnExpiredCertificateWhoseSlotTheCloseJobReleasedIsStillAnnounced(): void {
        [$service, $m] = $this->makeService(self::NOW);
        $expired = $this->expiringCert(self::NOW);
        $expired->setExpiresAt(self::NOW - 3600);
        $expired->setActiveIdemKey(null); // released by closePeriod
        $m['certMapper']->method('findActiveExpiringBetween')->willReturn([]);
        $m['certMapper']->method('findExpiredBetween')->willReturn([$expired]);
        $m['certMapper']->method('findByUserAndCourse')->with('jmueller', 7)->willReturn($expired);
        $m['reminderMapper']->method('findByCertAndThreshold')->willReturn(null);
        $m['reminderMapper']->method('insertOnce')->willReturn(true);
        $activities = [];
        $m['activity']->method('toUser')->willReturnCallback(function (...$args) use (&$activities): void {
            $activities[] = $args;
        });

        $service->sendRecertReminders();

        $this->assertSame([['certificate_expired', 'jmueller', 7, null, []]], $activities);
    }

    /**
     * GitHub #85: a certificate that gave up its slot because the learner RENEWED (a newer
     * certificate exists for user + course) expires silently — the learner is covered.
     */
    public function testARenewedCertificateExpiresSilently(): void {
        [$service, $m] = $this->makeService(self::NOW);
        $old = $this->expiringCert(self::NOW);
        $old->setExpiresAt(self::NOW - 3600);
        $old->setActiveIdemKey(null);
        $renewal = $this->expiringCert(self::NOW);
        $renewal->setId(43);
        $renewal->setActiveIdemKey('jmueller:7');
        $m['certMapper']->method('findActiveExpiringBetween')->willReturn([]);
        $m['certMapper']->method('findExpiredBetween')->willReturn([$old]);
        $m['certMapper']->method('findByUserAndCourse')->willReturn($renewal);
        $m['reminderMapper']->expects($this->never())->method('insertOnce');
        $m['activity']->expects($this->never())->method('toUser');

        $service->sendRecertReminders();
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
        // Pass-3 lock: the stale retry MUST go through the atomic re-claim CAS (winner).
        $m['reminderMapper']->expects($this->once())->method('reclaimStale')
            ->with(42, 30, self::NOW + 86400, self::NOW + 86400 - 3600)
            ->willReturn(true);
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

    /**
     * Pass-3 lock (stale-retry race): when a concurrent runner wins the atomic re-claim of the
     * SAME stale pending row (reclaimStale returns false), this runner must NOT deliver —
     * without the CAS both runners would notify() and double-bell.
     */
    public function testStaleReclaimLoserNeverNotifies(): void {
        [$service, $m] = $this->makeService(self::NOW);
        $m['certMapper']->method('findActiveExpiringBetween')->willReturn([$this->expiringCert(self::NOW)]);

        // Stale pending claim (2h old, not delivered) — both runners see it; WE lose the CAS.
        $m['reminderMapper']->method('findByCertAndThreshold')
            ->willReturn($this->reminderRow(self::NOW - 7200, null));
        $m['reminderMapper']->expects($this->once())->method('reclaimStale')
            ->with(42, 30, self::NOW, self::NOW - 3600)
            ->willReturn(false); // concurrent runner re-claimed first
        $m['reminderMapper']->expects($this->never())->method('insertOnce');
        $m['reminderMapper']->expects($this->never())->method('markDelivered');
        $m['notifManager']->expects($this->never())->method('notify');

        $this->assertSame(0, $service->sendRecertReminders(),
            'the re-claim CAS loser must skip — exactly one runner delivers');
    }
}
