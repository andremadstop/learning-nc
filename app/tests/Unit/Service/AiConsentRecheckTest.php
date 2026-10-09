<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\AiChatMemory;
use OCA\Learning\Db\AiChatMemoryMapper;
use OCA\Learning\Service\AiChatMemoryService;
use OCA\Learning\Service\GeminiService;
use OCA\Learning\Service\Llm\LlmConsentException;
use OCA\Learning\Service\Llm\LlmProviderException;
use OCA\Learning\Service\TelosService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 5.5.2 (Codex review): paths that send a SECOND request after the controller's consent check
 * must check again right before sending — chat-memory compression runs after the chat answer.
 * The pool generator's per-chunk check moved into the gateway (PoolGeneratorGatewayTest).
 *
 * 5.7.0 review finding (Task B5): when compression cannot run — no consent, consent revoked
 * meanwhile, budget exhausted, provider error, empty answer — it is skipped without data loss.
 * The ten oldest entries stay; they used to be deleted and replaced by a fixed placeholder.
 */
class AiConsentRecheckTest extends TestCase {
    private AiChatMemoryMapper&MockObject $mapper;
    private GeminiService&MockObject $gemini;
    private TelosService&MockObject $telos;
    /** @var list<string> roles of the rows inserted */
    private array $inserted = [];

    protected function setUp(): void {
        $this->mapper = $this->createMock(AiChatMemoryMapper::class);
        $this->gemini = $this->createMock(GeminiService::class);
        $this->telos = $this->createMock(TelosService::class);

        // the cap is exceeded after this exchange: 52 entries, the oldest ten are up for compression
        $this->mapper->method('countByUser')->willReturn(52);
        $oldest = [];
        for ($i = 1; $i <= 10; $i++) {
            $e = new AiChatMemory();
            $e->setId($i);
            $e->setUserId('alice');
            $e->setRole($i % 2 === 1 ? 'user' : 'assistant');
            $e->setMessage('alte Nachricht ' . $i);
            $e->setCreatedAt(1000 + $i);
            $oldest[] = $e;
        }
        $this->mapper->method('findOldestByUser')->willReturn($oldest);
        $this->mapper->method('insert')->willReturnCallback(function (AiChatMemory $e): AiChatMemory {
            $this->inserted[] = $e->getRole();
            return $e;
        });
    }

    private function service(): AiChatMemoryService {
        return new AiChatMemoryService($this->mapper, $this->gemini, $this->createMock(LoggerInterface::class), $this->telos);
    }

    private function assertOriginalsKept(): void {
        $this->mapper->expects($this->never())->method('deleteByIds');
        $this->service()->saveExchange('alice', 'Frage', 'Antwort', false);
        $this->assertSame(['user', 'assistant'], $this->inserted, 'only the new exchange is stored, no placeholder summary');
    }

    public function testMemoryCompressionWithoutConsentSendsNothingAndKeepsTheOriginals(): void {
        $this->telos->method('hasAiConsent')->with('alice')->willReturn(false);
        $this->gemini->expects($this->never())->method('generateNote');

        $this->assertOriginalsKept();
    }

    /** @return array<string, array{\Throwable|string}> */
    public static function compressionFailures(): array {
        return [
            'consent revoked meanwhile' => [new LlmConsentException()],
            'budget exhausted' => [new LlmProviderException('rate_limit')],
            'provider error' => [new LlmProviderException('timeout')],
            'not configured' => [new \RuntimeException('no provider')],
            'empty answer' => ['   '],
        ];
    }

    /** @dataProvider compressionFailures */
    public function testAFailedCompressionKeepsTheOriginals(\Throwable|string $outcome): void {
        $this->telos->method('hasAiConsent')->willReturn(true);
        $call = $this->gemini->expects($this->once())->method('generateNote');
        if ($outcome instanceof \Throwable) {
            $call->willThrowException($outcome);
        } else {
            $call->willReturn($outcome);
        }

        $this->assertOriginalsKept();
    }

    public function testASuccessfulCompressionReplacesTheOriginalsWithTheSummary(): void {
        $this->telos->method('hasAiConsent')->willReturn(true);
        $this->gemini->method('generateNote')->willReturn('Kurz: VLANs und Subnetze.');
        $this->mapper->expects($this->once())->method('deleteByIds')->with(range(1, 10));

        $this->service()->saveExchange('alice', 'Frage', 'Antwort', false);

        $this->assertSame(['user', 'assistant', 'summary'], $this->inserted);
    }
}
