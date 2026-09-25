<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\AiChatMemoryMapper;
use OCA\Learning\Service\AiChatMemoryService;
use OCA\Learning\Service\GeminiService;
use OCA\Learning\Service\LlmService;
use OCA\Learning\Service\PoolGeneratorService;
use OCA\Learning\Service\PoolService;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\TelosService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 5.5.2 (Codex review): paths that send a SECOND request after the controller's consent check
 * must check again right before sending — chat-memory compression runs after the chat answer,
 * and the pool generator sends one request per text chunk.
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

    public function testPoolGeneratorStopsWhenConsentIsGoneBetweenChunks(): void {
        $llm = $this->createMock(LlmService::class);
        $llm->method('isAvailable')->willReturn(true);
        $llm->expects($this->once())->method('generateText')->willReturn('[]');
        $telos = $this->createMock(TelosService::class);
        $telos->method('hasAiConsent')->with('dozent')->willReturnOnConsecutiveCalls(true, false, false, false);

        $service = new PoolGeneratorService(
            $llm,
            $this->createMock(PoolService::class),
            $this->createMock(QuestionService::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(\OCP\Files\IRootFolder::class),
            $telos
        );

        // Long enough text to be split into several chunks.
        $text = str_repeat("Ein Absatz über Subnetting und VLANs mit genug Inhalt für einen Abschnitt.\n\n", 400);
        $service->generateFromText($text, 'dozent', 30);
    }

    public function testPoolGeneratorWithoutConsentRefusesBeforeFirstChunk(): void {
        $llm = $this->createMock(LlmService::class);
        $llm->method('isAvailable')->willReturn(true);
        $llm->expects($this->never())->method('generateText');
        $telos = $this->createMock(TelosService::class);
        $telos->method('hasAiConsent')->willReturn(false);

        $service = new PoolGeneratorService(
            $llm,
            $this->createMock(PoolService::class),
            $this->createMock(QuestionService::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(\OCP\Files\IRootFolder::class),
            $telos
        );

        $this->expectExceptionMessage('consent_required');
        $service->generateFromText(str_repeat('Genug Text für einen Abschnitt. ', 20), 'dozent', 5);
    }
}
