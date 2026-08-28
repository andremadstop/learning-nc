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

    // =========================================================================
    // Codeberg #4 — TypeError in saveTelos(): '' where ?array was declared
    // =========================================================================

    /**
     * The exact call from the reporter's stack trace:
     *   TelosController->saveTelos(Array, '', '', '', 'private')
     *
     * The onboarding wizard posted the list fields as empty strings. With the old
     * `?array $help_offer` hint PHP raised a TypeError inside NC's Dispatcher — BEFORE the
     * method body ran, so the controller's own try/catch could not see it. Result: an
     * unhandled 500 and no telos profile ever saved for anyone finishing onboarding.
     *
     * RED against the pre-fix signature: the call itself raises TypeError, so the test errors.
     */
    public function testSaveTelosAcceptsEmptyStringHelpLists(): void {
        $telos = ['role' => 'student', 'experience_level' => 'beginner'];

        $this->telosMock->expects($this->once())
            ->method('saveTelos')
            ->with('user-A', $telos, [
                'bio' => '',
                'help_offer' => [],
                'help_wanted' => [],
                'visibility' => 'private',
            ])
            ->willReturn(['onboarding_completed' => true]);

        $resp = $this->makeController('user-A')->saveTelos($telos, '', '', '', 'private');

        $this->assertSame(200, $resp->getStatus());
    }

    /**
     * A client that posts the raw textarea value instead of splitting it must end up with the
     * same list the profile form produces — same separators as the frontend's splitListValue().
     */
    public function testSaveTelosSplitsCommaSeparatedHelpLists(): void {
        $telos = ['role' => 'student'];
        $captured = null;

        $this->telosMock->expects($this->once())
            ->method('saveTelos')
            ->willReturnCallback(function (string $userId, array $t, array $extra) use (&$captured) {
                $captured = $extra;
                return ['onboarding_completed' => true];
            });

        $resp = $this->makeController('user-A')->saveTelos(
            $telos,
            null,
            "PHP, Linux; Docker\nBash",
            'Kubernetes',
            null
        );

        $this->assertSame(200, $resp->getStatus());
        $this->assertSame(['PHP', 'Linux', 'Docker', 'Bash'], $captured['help_offer']);
        $this->assertSame(['Kubernetes'], $captured['help_wanted']);
    }

    /**
     * Arrays must keep behaving exactly as before — the union type is additive, not a rewrite.
     */
    public function testSaveTelosStillAcceptsArrayHelpLists(): void {
        $telos = ['role' => 'student'];
        $captured = null;

        $this->telosMock->expects($this->once())
            ->method('saveTelos')
            ->willReturnCallback(function (string $userId, array $t, array $extra) use (&$captured) {
                $captured = $extra;
                return ['onboarding_completed' => true];
            });

        $resp = $this->makeController('user-A')->saveTelos($telos, null, ['PHP', ' PHP ', ''], ['Linux'], null);

        $this->assertSame(200, $resp->getStatus());
        $this->assertSame(['PHP'], $captured['help_offer']);
        $this->assertSame(['Linux'], $captured['help_wanted']);
    }

    /**
     * A malformed `telos` must give a 400 with a readable error, not a TypeError-500.
     */
    public function testSaveTelosRejectsNonArrayTelosWithBadRequest(): void {
        $this->telosMock->expects($this->never())->method('saveTelos');

        $resp = $this->makeController('user-A')->saveTelos('not-an-array');

        $this->assertSame(400, $resp->getStatus());
        $this->assertSame('Telos payload is required', $resp->getData()['error']);
    }

    /**
     * updateTelos() carries the identical signature and the identical trap.
     */
    public function testUpdateTelosAcceptsStringHelpLists(): void {
        $captured = null;

        $this->telosMock->expects($this->once())
            ->method('updateFields')
            ->willReturnCallback(function (string $userId, array $fields) use (&$captured) {
                $captured = $fields;
                return ['onboarding_completed' => true];
            });

        $resp = $this->makeController('user-A')->updateTelos(null, null, 'PHP, Linux', null, null);

        $this->assertSame(200, $resp->getStatus());
        $this->assertSame(['PHP', 'Linux'], $captured['help_offer']);
        $this->assertArrayNotHasKey('help_wanted', $captured);
    }

    /**
     * Deliberate semantic, locked in: an empty string on updateTelos() CLEARS the list.
     * That matches the profile form, where emptying the textarea posts [] to clear it — the
     * string form is just the unsplit spelling of the same intent.
     */
    public function testUpdateTelosEmptyStringClearsHelpList(): void {
        $captured = null;

        $this->telosMock->expects($this->once())
            ->method('updateFields')
            ->willReturnCallback(function (string $userId, array $fields) use (&$captured) {
                $captured = $fields;
                return ['onboarding_completed' => true];
            });

        $resp = $this->makeController('user-A')->updateTelos(null, null, '', null, null);

        $this->assertSame(200, $resp->getStatus());
        $this->assertSame([], $captured['help_offer']);
    }

    /**
     * A non-array `telos` on updateTelos() must be rejected outright.
     */
    public function testUpdateTelosRejectsNonArrayTelos(): void {
        $this->telosMock->expects($this->never())->method('updateFields');

        $resp = $this->makeController('user-A')->updateTelos('not-an-array');

        $this->assertSame(400, $resp->getStatus());
        $this->assertSame('Telos payload must be an object', $resp->getData()['error']);
    }

    /**
     * Codex audit, B2: rejecting has to happen BEFORE the other fields are assembled.
     * Merely skipping the malformed telos let a mixed payload half-save — the bio was stored
     * and the endpoint answered 200, which is a worse outcome than the TypeError it replaced.
     */
    public function testUpdateTelosRejectsMixedPayloadWithoutSavingAnything(): void {
        $this->telosMock->expects($this->never())->method('updateFields');

        $resp = $this->makeController('user-A')->updateTelos('not-an-array', 'changed bio');

        $this->assertSame(400, $resp->getStatus());
        $this->assertSame('Telos payload must be an object', $resp->getData()['error']);
    }

    // =========================================================================
    // Codex audit, B1 — an emptied help list was silently preserved
    // =========================================================================

    /**
     * saveTelos() is a FULL save, and the help topics are published to classmates through
     * ClassbookService. Forwarding the list only when it was non-empty meant a user who had
     * shared a topic could not withdraw it: emptying the textarea and saving kept the old
     * value in the database and on the classbook page.
     *
     * An omitted field (null) still means "leave as is"; a supplied but empty one means clear.
     */
    public function testSaveTelosClearsHelpListsWhenExplicitlyEmpty(): void {
        $captured = null;
        $this->telosMock->expects($this->once())
            ->method('saveTelos')
            ->willReturnCallback(function (string $userId, array $t, array $extra) use (&$captured) {
                $captured = $extra;
                return ['onboarding_completed' => true];
            });

        $resp = $this->makeController('user-A')->saveTelos(['role' => 'student'], null, [], [], null);

        $this->assertSame(200, $resp->getStatus());
        $this->assertSame([], $captured['help_offer'], 'an explicitly empty array must clear');
        $this->assertSame([], $captured['help_wanted'], 'an explicitly empty array must clear');
    }

    /**
     * Same for the string spelling — an emptied textarea posted unsplit.
     */
    public function testSaveTelosClearsHelpListsForEmptyString(): void {
        $captured = null;
        $this->telosMock->expects($this->once())
            ->method('saveTelos')
            ->willReturnCallback(function (string $userId, array $t, array $extra) use (&$captured) {
                $captured = $extra;
                return ['onboarding_completed' => true];
            });

        $this->makeController('user-A')->saveTelos(['role' => 'student'], null, '', '', null);

        $this->assertSame([], $captured['help_offer']);
        $this->assertSame([], $captured['help_wanted']);
    }

    /**
     * The other half of the distinction: a field the client did not send at all stays absent,
     * so TelosService leaves the stored value alone.
     */
    public function testSaveTelosOmitsHelpListsWhenNotSuppliedAtAll(): void {
        $captured = null;
        $this->telosMock->expects($this->once())
            ->method('saveTelos')
            ->willReturnCallback(function (string $userId, array $t, array $extra) use (&$captured) {
                $captured = $extra;
                return ['onboarding_completed' => true];
            });

        $this->makeController('user-A')->saveTelos(['role' => 'student']);

        $this->assertArrayNotHasKey('help_offer', $captured, 'null means leave as is, not clear');
        $this->assertArrayNotHasKey('help_wanted', $captured);
    }
}
