<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\BackgroundJob;

use OCA\Learning\BackgroundJob\AuditCheckpointJob;
use OCA\Learning\Service\AuditCheckpointService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Phase 161 (AUDIT-04) — AuditCheckpointJob.
 *
 * The job is a thin, fault-isolating wrapper: it delegates to createCheckpoint() and MUST swallow
 * every Throwable so a signing/DB failure never propagates to the NC scheduler (must_haves truth #4).
 *
 * run() is protected, so it is invoked via reflection.
 */
class AuditCheckpointJobTest extends TestCase {

    private function invokeRun(AuditCheckpointJob $job): void {
        $method = new \ReflectionMethod($job, 'run');
        $method->setAccessible(true);
        $method->invoke($job, null);
    }

    public function testRunDelegatesToCreateCheckpoint(): void {
        $service = $this->createMock(AuditCheckpointService::class);
        $service->expects($this->once())->method('createCheckpoint');

        $job = new AuditCheckpointJob(
            $this->createMock(ITimeFactory::class),
            $service,
            $this->createMock(LoggerInterface::class)
        );

        $this->invokeRun($job);
    }

    public function testRunSwallowsExceptionAndLogsWarning(): void {
        $service = $this->createMock(AuditCheckpointService::class);
        $service->method('createCheckpoint')
            ->willThrowException(new \RuntimeException('signing key missing'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('AuditCheckpointJob failed'),
                $this->arrayHasKey('app')
            );

        $job = new AuditCheckpointJob(
            $this->createMock(ITimeFactory::class),
            $service,
            $logger
        );

        // Must NOT rethrow — the scheduler never sees the exception.
        $this->invokeRun($job);
        $this->addToAssertionCount(1);
    }
}
