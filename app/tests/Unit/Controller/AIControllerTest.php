<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\AIController;
use OCA\Learning\Service\AIService;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\TelosService;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * AUDIT v5.2.1 (finding A — consent gate): AIController::generate() and ::explain() must gate on
 * TelosService::hasAiConsent() BEFORE any AIService/QuestionService call reaches the LLM. When
 * consent is missing, both must return 403 with `consent_required=true` and never touch AIService.
 *
 * @group audit-a-consent
 */
class AIControllerTest extends TestCase {
    /** @var IRequest&\PHPUnit\Framework\MockObject\MockObject */
    private $requestMock;
    /** @var AIService&\PHPUnit\Framework\MockObject\MockObject */
    private $aiServiceMock;
    /** @var QuestionService&\PHPUnit\Framework\MockObject\MockObject */
    private $questionServiceMock;
    /** @var IDBConnection&\PHPUnit\Framework\MockObject\MockObject */
    private $dbMock;
    /** @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $loggerMock;
    /** @var TelosService&\PHPUnit\Framework\MockObject\MockObject */
    private $telosMock;

    protected function setUp(): void {
        $this->requestMock = $this->createMock(IRequest::class);
        $this->aiServiceMock = $this->createMock(AIService::class);
        $this->questionServiceMock = $this->createMock(QuestionService::class);
        $this->dbMock = $this->createMock(IDBConnection::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->telosMock = $this->createMock(TelosService::class);
    }

    private function makeController(?string $userId): AIController {
        return new AIController(
            'learning',
            $this->requestMock,
            $this->aiServiceMock,
            $this->questionServiceMock,
            $this->dbMock,
            $this->loggerMock,
            $this->telosMock,
            $userId
        );
    }

    // ---- generate() ---------------------------------------------------------

    public function testGenerateBlockedWithoutConsent(): void {
        $this->telosMock->method('hasAiConsent')->with('user-A')->willReturn(false);

        $this->aiServiceMock->expects($this->never())->method('generateQuestions');
        $this->questionServiceMock->expects($this->never())->method('verifyEditAccess');

        $resp = $this->makeController('user-A')->generate(5, str_repeat('a', 60), 10, 'de');

        $this->assertSame(403, $resp->getStatus());
        $this->assertTrue($resp->getData()['consent_required'] ?? false);
    }

    public function testGenerateAllowsWhenConsentGranted(): void {
        $this->telosMock->method('hasAiConsent')->with('user-A')->willReturn(true);

        // Consent gate passed — the next failure (no edit access) proves the gate let the request
        // through, but it is a DIFFERENT error than consent_required.
        $this->questionServiceMock->expects($this->once())->method('verifyEditAccess')
            ->with(5, 'user-A')
            ->willThrowException(new \Exception('no access'));
        $this->aiServiceMock->expects($this->never())->method('generateQuestions');

        $resp = $this->makeController('user-A')->generate(5, str_repeat('a', 60), 10, 'de');

        $this->assertSame(403, $resp->getStatus());
        $this->assertArrayNotHasKey('consent_required', $resp->getData());
    }

    // ---- explain() -----------------------------------------------------------

    public function testExplainBlockedWithoutConsent(): void {
        $this->telosMock->method('hasAiConsent')->with('user-A')->willReturn(false);

        $this->aiServiceMock->expects($this->never())->method('schedulePrompt');
        $this->questionServiceMock->expects($this->never())->method('find');

        $resp = $this->makeController('user-A')->explain(123);

        $this->assertSame(403, $resp->getStatus());
        $this->assertTrue($resp->getData()['consent_required'] ?? false);
    }

    public function testExplainAllowsWhenConsentGranted(): void {
        $this->telosMock->method('hasAiConsent')->with('user-A')->willReturn(true);

        // Consent gate passed — question lookup is reached (and fails for an unrelated reason).
        $this->questionServiceMock->expects($this->once())->method('find')
            ->with(123, 'user-A')
            ->willThrowException(new \Exception('not found'));
        $this->aiServiceMock->expects($this->never())->method('schedulePrompt');

        $resp = $this->makeController('user-A')->explain(123);

        $this->assertSame(404, $resp->getStatus());
        $this->assertArrayNotHasKey('consent_required', $resp->getData());
    }

    public function testExplainWithoutUserIsUnauthenticatedBeforeConsentCheck(): void {
        // No userId at all → 401, and the consent gate (which would also 403) must not even run.
        $this->telosMock->expects($this->never())->method('hasAiConsent');

        $resp = $this->makeController(null)->explain(123);

        $this->assertSame(401, $resp->getStatus());
    }
}
