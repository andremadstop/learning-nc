<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\TelosController;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\TelosService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * AUDIT v5.2.1 (pre-live review R2, finding #1 — Telos interview consent gate):
 * TelosController::processInterview() sends free-text conversation to Gemini via
 * TelosService::processInterview() and previously had NO per-user AI-consent check at all
 * (unlike AiController::generate()/explain() and VirtuProfController::chat()/interviewTurn(),
 * which are already consent-gated — see AiControllerTest / VirtuProfControllerTest).
 *
 * The fix adds a `hasAiConsent()` gate right after the `userId === null` check (but — note —
 * the pre-existing `conversation === null` check actually runs BEFORE it; see
 * testProcessInterviewNullConversationReturnsBadRequestBeforeConsentCheck below, which locks in
 * that ordering so it isn't silently changed later).
 *
 * Without consent: 403 + `consent_required === true`, and TelosService::processInterview() must
 * NEVER be called (no conversation text may reach Gemini). With consent: the gate lets the
 * request through to the normal conversation validation (e.g. too-short conversation → 400,
 * with no `consent_required` key) — proving the gate ran and passed.
 *
 * @group audit-r2-finding-1
 */
class TelosControllerTest extends TestCase {
    /** @var IRequest&\PHPUnit\Framework\MockObject\MockObject */
    private $requestMock;
    /** @var TelosService&\PHPUnit\Framework\MockObject\MockObject */
    private $telosMock;
    /** @var CourseService&\PHPUnit\Framework\MockObject\MockObject */
    private $courseServiceMock;

    protected function setUp(): void {
        $this->requestMock = $this->createMock(IRequest::class);
        $this->telosMock = $this->createMock(TelosService::class);
        $this->courseServiceMock = $this->createMock(CourseService::class);
    }

    private function makeController(?string $userId): TelosController {
        return new TelosController(
            'learning',
            $this->requestMock,
            $userId,
            $this->telosMock,
            $this->courseServiceMock
        );
    }

    /**
     * Without AI consent: 403 + consent_required=true, and the LLM-facing service method must
     * never be invoked — no conversation text may reach Gemini.
     */
    public function testProcessInterviewBlockedWithoutConsent(): void {
        $this->telosMock->method('hasAiConsent')->with('user-A')->willReturn(false);

        $this->telosMock->expects($this->never())->method('processInterview');

        $resp = $this->makeController('user-A')->processInterview(str_repeat('a', 60));

        $this->assertSame(403, $resp->getStatus());
        $this->assertTrue($resp->getData()['consent_required'] ?? false);
    }

    /**
     * No userId at all → 401, and the consent gate (which would also 403) must not even run.
     */
    public function testProcessInterviewWithoutUserIsUnauthenticatedBeforeConsentCheck(): void {
        $this->telosMock->expects($this->never())->method('hasAiConsent');
        $this->telosMock->expects($this->never())->method('processInterview');

        $resp = $this->makeController(null)->processInterview(str_repeat('a', 60));

        $this->assertSame(401, $resp->getStatus());
    }

    /**
     * Consent granted, but conversation is too short (< 20 chars after trim): the consent gate
     * must run (proven via hasAiConsent expects(once)) and pass, THEN the pre-existing
     * conversation-length validation returns 400 — with no consent_required key, and
     * TelosService::processInterview() still never reached (short-circuited before Gemini).
     */
    public function testProcessInterviewConsentGrantedButConversationTooShort(): void {
        $this->telosMock->expects($this->once())->method('hasAiConsent')
            ->with('user-A')
            ->willReturn(true);
        $this->telosMock->expects($this->never())->method('processInterview');

        $resp = $this->makeController('user-A')->processInterview('too short');

        $this->assertSame(400, $resp->getStatus());
        $this->assertArrayNotHasKey('consent_required', $resp->getData());
    }

    /**
     * Documents existing (pre-fix) ordering: a null conversation is rejected by the
     * `conversation === null` check BEFORE the consent gate is reached at all — so
     * hasAiConsent() is never called in this path. This is not part of the new consent-gate
     * fix, but locking it in guards against a future refactor accidentally reordering the
     * checks and creating a case where null input silently skips the consent gate to a
     * false result (e.g. if hasAiConsent were ever moved ahead of the null check and cached
     * as `true`/`false` outside this branch).
     */
    public function testProcessInterviewNullConversationReturnsBadRequestBeforeConsentCheck(): void {
        $this->telosMock->expects($this->never())->method('hasAiConsent');
        $this->telosMock->expects($this->never())->method('processInterview');

        $resp = $this->makeController('user-A')->processInterview(null);

        $this->assertSame(400, $resp->getStatus());
        $this->assertArrayNotHasKey('consent_required', $resp->getData());
    }
}
