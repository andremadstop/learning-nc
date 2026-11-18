<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\SupportTicketController;
use OCA\Learning\Db\SupportTicket;
use OCA\Learning\Db\SupportTicketMapper;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\GeminiService;
use OCA\Learning\Service\SupportTicketService;
use OCA\Learning\Service\TelosService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #59: the author of a support ticket never gets its AI triage. POST /api/support-tickets
 * runs applyAiTriage() synchronously and used to answer with the full row; GET
 * /api/support-tickets ("My tickets" in VirtuProf) used to send serializeTicket(). Both carried
 * ai_label, ai_confidence, ai_draft_answer, ai_followup_question, needs_review and answered_by.
 * The admin inbox keeps all of them.
 */
#[AllowMockObjectsWithoutExpectations]
class SupportTicketAuthorViewTest extends TestCase {
    private const STAFF_ONLY = [
        'ai_label', 'ai_confidence', 'ai_draft_answer', 'ai_followup_question', 'needs_review',
        'answered_by', 'user_id', 'context', 'context_json', 'routing_target_type', 'routing_course_id',
    ];

    /** @var SupportTicketMapper&\PHPUnit\Framework\MockObject\MockObject */
    private $mapper;
    /** @var GeminiService&\PHPUnit\Framework\MockObject\MockObject */
    private $gemini;
    /** @var TelosService&\PHPUnit\Framework\MockObject\MockObject */
    private $telos;
    /** @var IGroupManager&\PHPUnit\Framework\MockObject\MockObject */
    private $groupManager;

    protected function setUp(): void {
        $this->mapper = $this->createMock(SupportTicketMapper::class);
        $this->gemini = $this->createMock(GeminiService::class);
        $this->telos = $this->createMock(TelosService::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
    }

    private function controller(string $uid): SupportTicketController {
        $service = new SupportTicketService($this->mapper, $this->createMock(CourseService::class), $this->gemini, $this->telos);
        return new SupportTicketController('learning', $this->createMock(IRequest::class), $service, $this->createMock(CourseService::class), $this->groupManager, $uid);
    }

    private function triagedTicket(): SupportTicket {
        $ticket = new SupportTicket();
        $ticket->setId(7);
        $ticket->setUserId('learner');
        $ticket->setStatus('answered');
        $ticket->setSubject('Login broken');
        $ticket->setMessage('I cannot log in anymore.');
        $ticket->setContextJson('{"poolId":3}');
        $ticket->setCategory('technical');
        $ticket->setRoutingTargetType('admin');
        $ticket->setCreatedAt(1000);
        $ticket->setUpdatedAt(2000);
        $ticket->setAnswerText('Reset your password.');
        $ticket->setAnsweredAt(2000);
        $ticket->setAnsweredBy('admin');
        $ticket->setAiLabel('FAQ');
        $ticket->setAiConfidence(0.9);
        $ticket->setAiDraftAnswer('Please reset your password via the login screen.');
        $ticket->setAiFollowupQuestion('Which browser?');
        $ticket->setNeedsReview(true);
        return $ticket;
    }

    /** @param array<string, mixed> $ticket */
    private function assertAuthorView(array $ticket): void {
        foreach (self::STAFF_ONLY as $key) {
            $this->assertArrayNotHasKey($key, $ticket, $key . ' must not reach the author');
        }
    }

    public function testCreateAnswersTheAuthorWithoutTheTriageThatJustRan(): void {
        $this->gemini->method('isAvailable')->willReturn(true);
        $this->telos->method('hasAiConsent')->willReturn(true);
        $this->mapper->method('insert')->willReturnCallback(function (SupportTicket $t): SupportTicket {
            $t->setId(11);
            return $t;
        });
        $this->mapper->expects($this->once())->method('update')->willReturnArgument(0);
        $this->gemini->expects($this->once())->method('classifyTicket')->willReturn([
            'label' => 'Bug',
            'confidence' => 0.95,
            'suggested_answer' => null,
            'followup_question' => 'Which browser?',
        ]);

        $response = $this->controller('learner')->create('Login broken', 'I cannot log in anymore.');

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $ticket = $response->getData()['ticket'];
        $this->assertAuthorView($ticket);
        $this->assertSame(11, $ticket['id']);
        $this->assertSame('Login broken', $ticket['subject']);
        $this->assertSame('open', $ticket['status']);
    }

    public function testMyTicketsCarryWhatVirtuProfShowsAndNoTriage(): void {
        $this->mapper->method('findByUser')->with('learner', 20)->willReturn([$this->triagedTicket()]);

        $tickets = $this->controller('learner')->mine(20)->getData()['tickets'];

        $this->assertCount(1, $tickets);
        $this->assertAuthorView($tickets[0]);
        // what VirtuProfBubble's "My tickets" reads
        $this->assertSame(7, $tickets[0]['id']);
        $this->assertSame('Login broken', $tickets[0]['subject']);
        $this->assertSame('I cannot log in anymore.', $tickets[0]['message']);
        $this->assertSame('answered', $tickets[0]['status']);
        $this->assertSame('Reset your password.', $tickets[0]['answer_text']);
        $this->assertSame(1000, $tickets[0]['created_at']);
        $this->assertSame(2000, $tickets[0]['updated_at']);
    }

    public function testAnArchivedTicketStillReadsAsAnsweredForItsAuthor(): void {
        $ticket = $this->triagedTicket();
        $ticket->setStatus('archived');
        $this->mapper->method('findByUser')->willReturn([$ticket]);

        $tickets = $this->controller('learner')->mine(20)->getData()['tickets'];

        $this->assertSame('answered', $tickets[0]['status']);
    }

    public function testTheAdminInboxKeepsTheTriage(): void {
        $this->groupManager->method('isAdmin')->with('admin')->willReturn(true);
        $this->mapper->method('findByRoutingTarget')->willReturn([$this->triagedTicket()]);
        $this->mapper->method('countOpenByRoutingTarget')->willReturn(0);

        $tickets = $this->controller('admin')->adminList()->getData()['tickets'];

        $this->assertSame('FAQ', $tickets[0]['ai_label']);
        $this->assertSame('Please reset your password via the login screen.', $tickets[0]['ai_draft_answer']);
        $this->assertTrue($tickets[0]['needs_review']);
        $this->assertSame('admin', $tickets[0]['answered_by']);
    }
}
