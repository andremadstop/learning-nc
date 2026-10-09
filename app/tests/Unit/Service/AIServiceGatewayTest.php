<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\AIService;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\LlmConsentException;
use OCA\Learning\Service\Llm\LlmGateway;
use OCA\Learning\Service\Llm\LlmPurpose;
use OCA\Learning\Service\Llm\LlmRequest;
use OCA\Learning\Service\Llm\LlmResult;
use OCA\Learning\Service\Llm\LlmSession;
use OCA\Learning\Service\LlmService;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Task B4: the KI explanation and question generation send through LlmGateway. The 5.5 Nextcloud
 * TaskProcessing/TextProcessing path is gone (the connection snapshot, and so the consent, never
 * names it). A consent missing at begin() refuses before a task exists; a consent lost before
 * send() marks the task failed with consent_required and reaches the caller.
 */
class AIServiceGatewayTest extends TestCase {
    private const OUTPUT_MARKER = 'OUTPUT-MARKER-4b2e';

    private LlmGateway&MockObject $gateway;
    private LoggerInterface&MockObject $logger;
    /** @var array<string, mixed> what the task cache holds */
    private array $cached = [];

    protected function setUp(): void {
        $this->gateway = $this->createMock(LlmGateway::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function service(): AIService {
        // the unit-test OCP stubs have no ICache; the service only calls set() and get()
        $cached = &$this->cached;
        $cache = new class($cached) {
            /** @var array<string, mixed> */
            private array $values;

            /** @param array<string, mixed> $values */
            public function __construct(array &$values) {
                $this->values = &$values;
            }

            public function set(string $key, mixed $value, int $ttl = 0): bool {
                $this->values[$key] = $value;
                return true;
            }

            public function get(string $key): mixed {
                return $this->values[$key] ?? null;
            }
        };
        $factory = $this->createMock(ICacheFactory::class);
        $factory->method('createDistributed')->willReturn($cache);
        $llm = $this->createMock(LlmService::class);
        $llm->method('isAvailable')->willReturn(true);
        return new AIService($factory, $this->logger, $llm, $this->gateway);
    }

    private static function session(string $uid, LlmPurpose $purpose): LlmSession {
        return new LlmSession(ConnectionStore::legacyGemini(true), $uid, $purpose, 0);
    }

    public function testExplainSendsOnceForTheUserWithPurposeExplain(): void {
        $this->gateway->expects($this->once())->method('begin')->with('alice', LlmPurpose::Explain)
            ->willReturn(self::session('alice', LlmPurpose::Explain));
        $this->gateway->expects($this->once())->method('send')
            ->with($this->isInstanceOf(LlmSession::class), $this->callback(static fn (LlmRequest $r) => $r->userPrompt === 'prompt'))
            ->willReturn(new LlmResult('explanation', 1, 1, 'm'));

        $service = $this->service();
        $taskId = $service->schedulePrompt('prompt', 'alice');

        $status = $service->getTaskStatus($taskId, 'alice');
        $this->assertSame('completed', $status['status']);
        $this->assertSame('explanation', $status['output']);
    }

    /**
     * Spec 4.3 / review finding (MED): IDs of tasks the 5.5 path queued in Nextcloud are no longer
     * known here. Polling one reports a failed task with error `expired` (the frontend then stops
     * polling and shows the error), as does a task whose cache entry ran out.
     */
    public function testAnUnknownTaskIdReportsAnExpiredFailedTask(): void {
        $this->gateway->expects($this->never())->method('begin');
        $status = $this->service()->getTaskStatus(4711, 'alice');
        $this->assertSame('failed', $status['status']);
        $this->assertSame('expired', $status['error']);
        $this->assertSame([], $status['questions']);
    }

    public function testAnotherUsersTaskIsStillRefused(): void {
        $this->gateway->method('begin')->willReturn(self::session('alice', LlmPurpose::Explain));
        $this->gateway->method('send')->willReturn(new LlmResult('explanation', 1, 1, 'm'));
        $service = $this->service();
        $taskId = $service->schedulePrompt('prompt', 'alice');

        $this->expectExceptionMessage('Not your task');
        $service->getTaskStatus($taskId, 'mallory');
    }

    public function testQuestionGenerationUsesItsOwnPurpose(): void {
        $this->gateway->expects($this->once())->method('begin')->with('alice', LlmPurpose::QuestionGenerate)
            ->willReturn(self::session('alice', LlmPurpose::QuestionGenerate));
        $this->gateway->expects($this->once())->method('send')->willReturn(new LlmResult('[]', 1, 1, 'm'));

        $this->service()->generateQuestions('Ein Text über VLANs.', 3, 'de', 'alice');
    }

    public function testWithoutConsentNoTaskIsCreatedAndNothingIsSent(): void {
        $this->gateway->method('begin')->willThrowException(new LlmConsentException());
        $this->gateway->expects($this->never())->method('send');

        try {
            $this->service()->schedulePrompt('prompt', 'alice');
            $this->fail('consent error swallowed');
        } catch (LlmConsentException) {
        }
        $this->assertSame([], $this->cached);
    }

    public function testConsentLostBeforeSendFailsTheTaskAndReachesTheCaller(): void {
        $this->gateway->method('begin')->willReturn(self::session('alice', LlmPurpose::Explain));
        $this->gateway->method('send')->willThrowException(new LlmConsentException());

        try {
            $this->service()->schedulePrompt('prompt', 'alice');
            $this->fail('consent error swallowed');
        } catch (LlmConsentException) {
        }
        $this->assertCount(1, $this->cached);
        $task = array_values($this->cached)[0];
        $this->assertSame('failed', $task['status']);
        $this->assertSame('consent_required', $task['error']);
    }

    public function testTheLegacyTaskProcessingPathIsGone(): void {
        $class = new \ReflectionClass(AIService::class);
        $this->assertFalse($class->hasProperty('taskProcessingManager'));
        $this->assertFalse($class->hasProperty('textProcessingManager'));
        $this->assertFalse($class->hasMethod('useLegacyTaskBackend'));
        $this->assertFalse($class->hasMethod('scheduleTaskProcessing'));
    }

    public function testUnparsableOutputIsNotLogged(): void {
        $this->logger->expects($this->atLeastOnce())->method('warning')->with(
            $this->anything(),
            $this->callback(fn (array $ctx) => !str_contains((string)json_encode($ctx), self::OUTPUT_MARKER)),
        );

        $this->assertSame([], $this->service()->parseGeneratedQuestions('not json ' . self::OUTPUT_MARKER));
    }
}
