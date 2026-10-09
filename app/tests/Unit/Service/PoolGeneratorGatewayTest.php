<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\LlmConsentException;
use OCA\Learning\Service\Llm\LlmGateway;
use OCA\Learning\Service\Llm\LlmProviderException;
use OCA\Learning\Service\Llm\LlmPurpose;
use OCA\Learning\Service\Llm\LlmResult;
use OCA\Learning\Service\Llm\LlmSession;
use OCA\Learning\Service\LlmService;
use OCA\Learning\Service\PoolGeneratorService;
use OCA\Learning\Service\PoolService;
use OCA\Learning\Service\QuestionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Task B4: the pool generator begins one gateway session for the lecturer (purpose pool_generate)
 * and sends one request per text chunk through it; send() re-checks consent before each chunk.
 * At most 10 chunks are sent, however long the material is.
 */
class PoolGeneratorGatewayTest extends TestCase {
    private LlmGateway&MockObject $gateway;

    protected function setUp(): void {
        $this->gateway = $this->createMock(LlmGateway::class);
    }

    private function service(): PoolGeneratorService {
        $llm = $this->createMock(LlmService::class);
        $llm->method('isAvailable')->willReturn(true);
        return new PoolGeneratorService(
            $llm,
            $this->gateway,
            $this->createMock(PoolService::class),
            $this->createMock(QuestionService::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(\OCP\Files\IRootFolder::class),
        );
    }

    private static function session(): LlmSession {
        return new LlmSession(ConnectionStore::legacyGemini(true), 'dozent', LlmPurpose::PoolGenerate, 0);
    }

    /** Exactly $chunks chunks of 3000 words (the service trims the trailing space). */
    private static function material(int $chunks): string {
        return str_repeat('Subnetting VLAN Routing Switching Firewall Protokolle ', 500 * $chunks);
    }

    public function testWithoutConsentNothingIsSent(): void {
        $this->gateway->expects($this->once())->method('begin')->with('dozent', LlmPurpose::PoolGenerate)
            ->willThrowException(new LlmConsentException());
        $this->gateway->expects($this->never())->method('send');

        $this->expectException(LlmConsentException::class);
        $this->expectExceptionMessage('consent_required');
        $this->service()->generateFromText(str_repeat('Genug Text für einen Abschnitt. ', 20), 'dozent', 5);
    }

    public function testConsentLostBetweenChunksStopsSending(): void {
        $this->gateway->expects($this->once())->method('begin')->willReturn(self::session());
        $this->gateway->expects($this->exactly(2))->method('send')->willReturnOnConsecutiveCalls(
            new LlmResult('[]', 1, 1, 'm'),
            $this->throwException(new LlmConsentException()),
        );

        $this->assertSame([], $this->service()->generateFromText(self::material(4), 'dozent', 30));
    }

    public function testConsentLostBeforeTheFirstChunkReachesTheCaller(): void {
        $this->gateway->method('begin')->willReturn(self::session());
        $this->gateway->expects($this->once())->method('send')->willThrowException(new LlmConsentException());

        $this->expectException(LlmConsentException::class);
        $this->service()->generateFromText(self::material(3), 'dozent', 30);
    }

    public function testAFailedChunkDoesNotStopTheOthers(): void {
        $this->gateway->method('begin')->willReturn(self::session());
        $this->gateway->expects($this->exactly(3))->method('send')->willReturnOnConsecutiveCalls(
            $this->throwException(new LlmProviderException('timeout')),
            new LlmResult('[]', 1, 1, 'm'),
            new LlmResult('[]', 1, 1, 'm'),
        );

        $this->service()->generateFromText(self::material(3), 'dozent', 30);
    }

    public function testAtMostTenChunksAreSent(): void {
        $this->gateway->method('begin')->willReturn(self::session());
        $this->gateway->expects($this->exactly(10))->method('send')->willReturn(new LlmResult('[]', 1, 1, 'm'));

        $this->service()->generateFromText(self::material(14), 'dozent', 30);
    }
}
