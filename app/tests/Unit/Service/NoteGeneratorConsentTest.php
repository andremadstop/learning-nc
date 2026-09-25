<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\GeminiService;
use OCA\Learning\Service\LernbotFileService;
use OCA\Learning\Service\LernprofilService;
use OCA\Learning\Service\NoteGeneratorService;
use OCA\Learning\Service\TelosService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 5.5.2: generateSummary() sends the user's weak topic and wrongly answered questions to the
 * AI provider. It is reached from the weekly background job, which had no consent gate at all,
 * so the service itself must refuse before loading any data or calling the model.
 */
class NoteGeneratorConsentTest extends TestCase {
    public function testGenerateSummaryWithoutConsentNeverCallsTheModel(): void {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->expects($this->never())->method('generateNote');
        $profile = $this->createMock(LernprofilService::class);
        $profile->expects($this->never())->method($this->anything());
        $telos = $this->createMock(TelosService::class);
        $telos->method('hasAiConsent')->with('alice')->willReturn(false);

        $service = new NoteGeneratorService(
            $gemini,
            $this->createMock(LernbotFileService::class),
            $profile,
            new FakeDbConnection(),
            $this->createMock(LoggerInterface::class),
            $telos
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('consent_required');
        $service->generateSummary('alice', 5);
    }
}
