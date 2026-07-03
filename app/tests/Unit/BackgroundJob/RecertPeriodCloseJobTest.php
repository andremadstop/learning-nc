<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\BackgroundJob;

use OCA\Learning\BackgroundJob\RecertPeriodCloseJob;
use OCA\Learning\Service\AssignmentService;
use OCA\Learning\Service\AuditService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Phase 164 (RECERT-04) — RecertPeriodCloseJob period-close idempotency.
 *
 * run() is protected, invoked via reflection (mirrors AuditCheckpointJobTest).
 *
 * GREEN since 164-05: run() delegates to AssignmentService::closeExpiredPeriods(now), which
 * queries certs owning their idem slot with expires_at < now - grace and calls
 * closePeriod('user', user_id, course_id, cert_id) per row.
 */
class RecertPeriodCloseJobTest extends TestCase {

    private function invokeRun(RecertPeriodCloseJob $job): void {
        $method = new \ReflectionMethod($job, 'run');
        $method->setAccessible(true);
        $method->invoke($job, null);
    }

    /**
     * Period-close idempotency (SC3): running the job TWICE for the same expired period
     * must produce EXACTLY ONE new assignment row.
     *
     * Mechanism (partial mock: REAL closeExpiredPeriods + mocked closePeriod):
     *   Run 1 — the finder query returns the one expired open period (cert row 42) →
     *            closePeriod('user','jmueller',7,42) called once. In production closePeriod's
     *            CAS write NULLs the cert's active_idem_key, dropping it out of the query.
     *   Run 2 — the finder returns NOTHING (cert no longer owns its idem slot) →
     *            closePeriod NOT called.
     *   Net: closePeriod called exactly once → one new row → SC3 satisfied.
     *
     * SC2 invariant enforced in AssignmentService::closePeriod() (locked in
     * AssignmentServiceTest + CertificateVerifyServiceTest::testClosedPeriodReadsExpired):
     *   MUST NOT set revoked=true (old verify URL must read "expired", not "withdrawn").
     */
    public function testDoubleRunSingleRow(): void {
        $now = 1750000000;

        // Finder builders: run 1 yields one expired cert row; run 2 yields none (its
        // active_idem_key was NULLed by the run-1 close — self-cleaning query).
        $db = new FakeDbConnection([
            new FakeQueryBuilder(new FakeResult(fetchQueue: [
                ['id' => 42, 'user_id' => 'jmueller', 'course_id' => 7],
            ])),
            new FakeQueryBuilder(new FakeResult(fetchQueue: [])),
        ]);

        $config = $this->createMock(\OCP\IConfig::class);
        $config->method('getAppValue')->willReturn('14'); // recert_grace_days default

        // Partial mock: closeExpiredPeriods runs REAL (query + loop), closePeriod is mocked —
        // the assertion target is "exactly one close across two runs".
        $svc = $this->getMockBuilder(AssignmentService::class)
            ->setConstructorArgs([
                $db,
                $this->createMock(IGroupManager::class),
                $this->createMock(AuditService::class),
                $config,
                $this->createMock(LoggerInterface::class),
            ])
            ->onlyMethods(['closePeriod'])
            ->getMock();

        // After run 1 closes the expired period, run 2 finds nothing open.
        // Total: exactly ONE closePeriod call across both invocations — with the specific
        // cert id from the finder row (CAS pinning, 164-04 post-impl hardening).
        $svc->expects($this->once())
            ->method('closePeriod')
            ->with('user', 'jmueller', 7, 42);

        $time = $this->createMock(ITimeFactory::class);
        // Pin clock to a fixed "now" so thresholds are deterministic.
        $time->method('getTime')->willReturn($now);

        $logger = $this->createMock(LoggerInterface::class);
        $job = new RecertPeriodCloseJob($time, $svc, $logger);

        $this->invokeRun($job);
        $this->invokeRun($job);
    }
}
