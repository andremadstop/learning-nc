<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\AiChatMemoryMapper;
use OCA\Learning\Service\AiChatMemoryService;
use OCA\Learning\Service\GeminiService;
use OCA\Learning\Service\TelosService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 5.5.2 (Codex review): paths that send a SECOND request after the controller's consent check
 * must check again right before sending — chat-memory compression runs after the chat answer.
 * The pool generator's per-chunk check moved into the gateway (PoolGeneratorGatewayTest).
 */
class AiConsentRecheckTest extends TestCase {
    public function testMemoryCompressionWithoutConsentSendsNothing(): void {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->expects($this->never())->method('generateNote');
        $telos = $this->createMock(TelosService::class);
        $telos->method('hasAiConsent')->with('alice')->willReturn(false);

        $service = new AiChatMemoryService(
            $this->createMock(AiChatMemoryMapper::class),
            $gemini,
            $this->createMock(LoggerInterface::class),
            $telos
        );

        $method = new \ReflectionMethod(AiChatMemoryService::class, 'compressEntries');
        $summary = $method->invoke($service, [], 'alice');

        $this->assertSame('Summary of earlier conversation covering various learning topics.', $summary);
    }
}
