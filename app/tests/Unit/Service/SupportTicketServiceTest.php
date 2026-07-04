<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\SupportTicket;
use OCA\Learning\Db\SupportTicketMapper;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\GeminiService;
use OCA\Learning\Service\SupportTicketService;
use OCA\Learning\Service\TelosService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * AUDIT v5.2.1 (consent-fix A): SupportTicketService::create() must ALWAYS persist the ticket via
 * SupportTicketMapper::insert() — a missing AI consent must never block ticket creation, only the
 * optional AI triage step (applyAiTriage() -> GeminiService::classifyTicket()) that runs afterwards.
 * The triage step itself is gated on BOTH `ai_enabled` app config AND TelosService::hasAiConsent().
 *
 * @group audit-consent-fix-a
 */
class SupportTicketServiceTest extends TestCase {
    /** @var SupportTicketMapper&\PHPUnit\Framework\MockObject\MockObject */
    private $ticketMapperMock;
    /** @var CourseService&\PHPUnit\Framework\MockObject\MockObject */
    private $courseServiceMock;
    /** @var GeminiService&\PHPUnit\Framework\MockObject\MockObject */
    private $geminiServiceMock;
    /** @var IConfig&\PHPUnit\Framework\MockObject\MockObject */
    private $configMock;
    /** @var TelosService&\PHPUnit\Framework\MockObject\MockObject */
    private $telosMock;

    protected function setUp(): void {
        $this->ticketMapperMock = $this->createMock(SupportTicketMapper::class);
        $this->courseServiceMock = $this->createMock(CourseService::class);
        $this->geminiServiceMock = $this->createMock(GeminiService::class);
        $this->configMock = $this->createMock(IConfig::class);
        $this->telosMock = $this->createMock(TelosService::class);
    }

    private function makeService(): SupportTicketService {
        return new SupportTicketService(
            $this->ticketMapperMock,
            $this->courseServiceMock,
            $this->geminiServiceMock,
            $this->configMock,
            $this->telosMock
        );
    }

    /** Config mock helper: only `ai_enabled` is stubbed to $value, every other key echoes its default. */
    private function stubAiEnabled(string $value): void {
        $this->configMock->method('getAppValue')->willReturnCallback(
            static function (string $app, string $key, string $default = '') use ($value): string {
                return $key === 'ai_enabled' ? $value : $default;
            }
        );
    }

    public function testCreatePersistsTicketButSkipsAiTriageWithoutConsent(): void {
        $this->stubAiEnabled('yes');
        $this->telosMock->method('hasAiConsent')->with('user-A')->willReturn(false);

        // Ticket creation must NOT depend on consent at all.
        $this->ticketMapperMock->expects($this->once())->method('insert')->willReturnArgument(0);
        $this->ticketMapperMock->expects($this->never())->method('update');
        $this->geminiServiceMock->expects($this->never())->method('classifyTicket');

        $ticket = $this->makeService()->create('user-A', 'Login broken', 'I cannot log in anymore.');

        $this->assertInstanceOf(SupportTicket::class, $ticket);
        $this->assertSame('user-A', $ticket->getUserId());
        $this->assertSame('Login broken', $ticket->getSubject());
        $this->assertSame('open', $ticket->getStatus());
        // No AI fields were ever populated.
        $this->assertNull($ticket->getAiLabel());
    }

    public function testCreateRunsAiTriageWhenAiEnabledAndConsentGranted(): void {
        $this->stubAiEnabled('yes');
        $this->telosMock->method('hasAiConsent')->with('user-A')->willReturn(true);

        $this->ticketMapperMock->expects($this->once())->method('insert')->willReturnArgument(0);
        $this->ticketMapperMock->expects($this->once())->method('update')->willReturnArgument(0);

        // Mock result matches the field structure applyAiTriage() reads: label, confidence,
        // suggested_answer, followup_question.
        $this->geminiServiceMock->expects($this->once())->method('classifyTicket')
            ->with('Login broken', 'I cannot log in anymore.')
            ->willReturn([
                'label' => 'FAQ',
                'confidence' => 0.9,
                'suggested_answer' => 'Please reset your password via the login screen.',
                'followup_question' => null,
            ]);

        $ticket = $this->makeService()->create('user-A', 'Login broken', 'I cannot log in anymore.');

        $this->assertSame('FAQ', $ticket->getAiLabel());
        $this->assertSame(0.9, $ticket->getAiConfidence());
        $this->assertSame('Please reset your password via the login screen.', $ticket->getAiDraftAnswer());
        $this->assertNull($ticket->getAiFollowupQuestion());
    }

    public function testCreateSkipsAiTriageWhenAiDisabledRegardlessOfConsent(): void {
        $this->stubAiEnabled('no');
        // Consent is granted, but ai_enabled=no must still block triage.
        $this->telosMock->method('hasAiConsent')->willReturn(true);

        $this->ticketMapperMock->expects($this->once())->method('insert')->willReturnArgument(0);
        $this->ticketMapperMock->expects($this->never())->method('update');
        $this->geminiServiceMock->expects($this->never())->method('classifyTicket');

        $ticket = $this->makeService()->create('user-A', 'Login broken', 'I cannot log in anymore.');

        $this->assertInstanceOf(SupportTicket::class, $ticket);
        $this->assertNull($ticket->getAiLabel());
    }
}
