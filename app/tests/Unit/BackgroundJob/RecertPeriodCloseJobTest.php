<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\BackgroundJob;

use OCA\Learning\BackgroundJob\RecertPeriodCloseJob;
use OCA\Learning\Service\AssignmentService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Phase 164 (RECERT-04) — RecertPeriodCloseJob period-close idempotency.
 *
 * run() is protected, invoked via reflection (mirrors AuditCheckpointJobTest).
 *
 * Expected-RED: all tests in this file FAIL against the no-op skeleton (impl in 164-05).
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
     * Mechanism:
     *   Run 1 — job finds the one open expired period → calls AssignmentService::closePeriod()
     *            → closePeriod inserts a fresh assignment row (new active_period_key).
     *   Run 2 — period is now closed; job finds no open expired periods → closePeriod NOT called.
     *   Net: closePeriod called exactly once → one new row → SC3 satisfied.
     *
     * SC2 invariant enforced in AssignmentService::closePeriod() (164-02 stub):
     *   MUST NOT set revoked=true (old verify URL must read "expired", not "withdrawn").
     *
     * RED mechanism: RecertPeriodCloseJob::run() is a no-op stub (impl in 164-05);
     *   AssignmentService::closePeriod never called → expects(once()) FAILS.
     * GREEN trigger (164-05): run() queries expired open periods and calls closePeriod() for
     *   each found period; second run finds none → total one call → assertion passes.
     */
    public function testDoubleRunSingleRow(): void {
        $svc = $this->createMock(AssignmentService::class);

        // After run 1 closes the expired period, run 2 finds nothing open.
        // Total: exactly ONE closePeriod call across both invocations.
        $svc->expects($this->once())
            ->method('closePeriod');

        $time = $this->createMock(ITimeFactory::class);
        // Pin clock to a fixed "now" so thresholds are deterministic in 164-05.
        $time->method('getTime')->willReturn(1750000000);

        $logger = $this->createMock(LoggerInterface::class);
        $job = new RecertPeriodCloseJob($time, $svc, $logger);

        $this->invokeRun($job);
        $this->invokeRun($job);
    }
}
