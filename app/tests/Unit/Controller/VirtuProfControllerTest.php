<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\VirtuProfController;
use OCA\Learning\Service\AiChatMemoryService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\GeminiService;
use OCA\Learning\Service\LernplanService;
use OCA\Learning\Service\NoteGeneratorService;
use OCA\Learning\Service\PoolService;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\RagContextService;
use OCA\Learning\Service\SupportTicketService;
use OCA\Learning\Service\TelosService;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Phase 153 Plan 03 — MIGR-01 / MIGR-02 first-touch-coercion contract.
 *
 * Asserts the rewritten VirtuProfController::getSkin() (Plan 03 Task 1)
 * uses IConfig::getUserKeys() empty-vs-non-empty as the new-vs-existing
 * user discriminator (RESEARCH.md Pattern 1):
 *
 *   1. Existing user (any pre-existing learning.* user_config key but no
 *      virtuprof_skin row) → first-touch writes 'nova' (Zero-Change-Default).
 *
 *   2. Brand-new user (zero learning.* user_config keys) → first-touch
 *      writes 'prof_lern_classic' (new-user default per v4.4.0 milestone).
 *
 *   3. User with existing virtuprof_skin row → fast path returns row value
 *      directly without scanning getUserKeys (O(1) on the row).
 *
 *   4. User whose stored row contains an orphan/dropped skin id (e.g.
 *      'einstein_v0_dropped') → normalizeSkin() falls back to 'nova'
 *      (allowlist sanitization on read).
 *
 * @group migr-01-02
 */
class VirtuProfControllerTest extends TestCase {
    /** @var IConfig&\PHPUnit\Framework\MockObject\MockObject */
    private $configMock;
    /** @var IRequest&\PHPUnit\Framework\MockObject\MockObject */
    private $requestMock;
    /** @var GeminiService&\PHPUnit\Framework\MockObject\MockObject */
    private $geminiMock;
    /** @var RagContextService&\PHPUnit\Framework\MockObject\MockObject */
    private $ragMock;
    /** @var AiChatMemoryService&\PHPUnit\Framework\MockObject\MockObject */
    private $chatMemoryMock;
    /** @var NoteGeneratorService&\PHPUnit\Framework\MockObject\MockObject */
    private $noteGenMock;
    /** @var LernplanService&\PHPUnit\Framework\MockObject\MockObject */
    private $lernplanMock;
    /** @var SupportTicketService&\PHPUnit\Framework\MockObject\MockObject */
    private $ticketMock;
    /** @var TelosService&\PHPUnit\Framework\MockObject\MockObject */
    private $telosMock;
    /** @var IUserManager&\PHPUnit\Framework\MockObject\MockObject */
    private $userManagerMock;
    /** @var CourseService&\PHPUnit\Framework\MockObject\MockObject */
    private $courseMock;
    /** @var PoolService&\PHPUnit\Framework\MockObject\MockObject */
    private $poolMock;
    /** @var QuestionService&\PHPUnit\Framework\MockObject\MockObject */
    private $questionMock;

    protected function setUp(): void {
        $this->configMock = $this->createMock(IConfig::class);
        $this->requestMock = $this->createMock(IRequest::class);
        $this->geminiMock = $this->createMock(GeminiService::class);
        $this->ragMock = $this->createMock(RagContextService::class);
        $this->chatMemoryMock = $this->createMock(AiChatMemoryService::class);
        $this->noteGenMock = $this->createMock(NoteGeneratorService::class);
        $this->lernplanMock = $this->createMock(LernplanService::class);
        $this->ticketMock = $this->createMock(SupportTicketService::class);
        $this->telosMock = $this->createMock(TelosService::class);
        $this->userManagerMock = $this->createMock(IUserManager::class);
        $this->courseMock = $this->createMock(CourseService::class);
        $this->poolMock = $this->createMock(PoolService::class);
        $this->questionMock = $this->createMock(QuestionService::class);
    }

    /**
     * Build a controller bound to $userId. Per-test mock returns are configured
     * before this is called so the buildStatePayload() call sees the right values.
     */
    private function makeController(string $userId): VirtuProfController {
        return new VirtuProfController(
            'learning',
            $this->requestMock,
            $this->configMock,
            $userId,
            $this->geminiMock,
            $this->ragMock,
            $this->chatMemoryMock,
            $this->noteGenMock,
            $this->lernplanMock,
            $this->ticketMock,
            $this->telosMock,
            $this->userManagerMock,
            $this->courseMock,
            $this->poolMock,
            $this->questionMock
        );
    }

    /**
     * Set up the full stack of getUserValue() return values for a state-payload
     * call. Configures every key buildStatePayload() reads other than virtuprof_skin
     * (which the per-test customizes).
     *
     * @param string $virtuProfSkinReturn the value getUserValue returns for virtuprof_skin
     */
    private function configureStatePayloadReads(string $virtuProfSkinReturn): void {
        // willReturnMap: [args..., return]. Keys read by buildStatePayload + getSkin
        // (getUserValue is the only read interface; setUserValue/getUserKeys handled per-test).
        $this->configMock->method('getUserValue')->willReturnCallback(
            function (string $userId, string $appName, string $key, string $default = '') use ($virtuProfSkinReturn) {
                if ($key === 'virtuprof_skin') {
                    return $virtuProfSkinReturn;
                }
                // All other keys: return the default (no influence on skin resolution).
                return $default;
            }
        );

        // ai_enabled flag (read in isAiFeatureAvailable inside buildStatePayload)
        $this->configMock->method('getAppValue')->willReturn('no');
        $this->geminiMock->method('isAvailable')->willReturn(false);
    }

    /**
     * MIGR-01: existing user with at least one learning.* key but no
     * virtuprof_skin row → first-touch-coercion writes 'nova'.
     */
    public function testGetSkin_existingUser_resolvesNova(): void {
        $this->configureStatePayloadReads('');  // sentinel: no virtuprof_skin row

        $this->configMock->method('getUserKeys')
            ->with('user-A', 'learning')
            ->willReturn(['consent_ai', 'interface_language']);

        // Coercion writes nova back exactly once
        $this->configMock->expects($this->once())
            ->method('setUserValue')
            ->with('user-A', 'learning', 'virtuprof_skin', 'nova');

        $controller = $this->makeController('user-A');
        $payload = $controller->getState()->getData();

        $this->assertSame('nova', $payload['skin']);
    }

    /**
     * MIGR-02: brand-new user with zero learning.* keys → first-touch-coercion
     * writes 'prof_lern_classic'.
     */
    public function testGetSkin_newUser_resolvesProfLernClassic(): void {
        $this->configureStatePayloadReads('');  // sentinel: no virtuprof_skin row

        $this->configMock->method('getUserKeys')
            ->with('user-B', 'learning')
            ->willReturn([]);

        // Coercion writes prof_lern_classic back exactly once
        $this->configMock->expects($this->once())
            ->method('setUserValue')
            ->with('user-B', 'learning', 'virtuprof_skin', 'prof_lern_classic');

        $controller = $this->makeController('user-B');
        $payload = $controller->getState()->getData();

        $this->assertSame('prof_lern_classic', $payload['skin']);
    }

    /**
     * Fast path: user already has a virtuprof_skin row → return it directly,
     * never call getUserKeys (would be O(N) on keyset, regression).
     */
    public function testGetSkin_existingRow_returnsRowValueWithoutKeyScan(): void {
        $this->configureStatePayloadReads('kosmologe');

        // Fast path — no key-scan, no write
        $this->configMock->expects($this->never())->method('getUserKeys');
        $this->configMock->expects($this->never())->method('setUserValue');

        $controller = $this->makeController('user-C');
        $payload = $controller->getState()->getData();

        $this->assertSame('kosmologe', $payload['skin']);
    }

    /**
     * Allowlist sanitization: stored row contains an orphan/dropped skin id
     * (e.g. from a removed Phase) → normalizeSkin() falls back to 'nova'.
     * Fast path still applies (no key-scan, no double-write).
     */
    public function testGetSkin_orphanSkin_normalizesToNova(): void {
        $this->configureStatePayloadReads('einstein_v0_dropped');

        // Fast path — no key-scan, no coercion write
        $this->configMock->expects($this->never())->method('getUserKeys');
        $this->configMock->expects($this->never())->method('setUserValue');

        $controller = $this->makeController('user-D');
        $payload = $controller->getState()->getData();

        $this->assertSame('nova', $payload['skin']);
    }

    // ---- AUDIT HIGH-03 (consent) + HIGH-02 (RAG IDOR) ------------------------------------

    /** Enable the AI feature (admin toggle + provider available) for the chat-path tests. */
    private function enableAi(): void {
        $this->configMock->method('getAppValue')
            ->willReturnCallback(fn(string $app, string $key, string $default = ''): string
                => $key === 'ai_enabled' ? 'yes' : $default);
        $this->geminiMock->method('isAvailable')->willReturn(true);
        // getUserValue is read for language/skin etc. — return the default everywhere.
        $this->configMock->method('getUserValue')
            ->willReturnCallback(fn(string $u, string $a, string $k, string $d = ''): string => $d);
    }

    /**
     * HIGH-03: with the AI feature enabled but NO per-user consent, chat() must return
     * consent_required and never reach the LLM or RAG (no data leaves for Gemini).
     */
    public function testChatWithoutConsentIsBlocked(): void {
        $this->enableAi();
        $this->telosMock->method('getAiConsentVersion')->with('user-A')->willReturn(null);

        $this->geminiMock->expects($this->never())->method('chat');
        $this->ragMock->expects($this->never())->method('buildContext');

        $resp = $this->makeController('user-A')->chat('was ist ein vlan', 5, 5, 5);

        $this->assertSame(403, $resp->getStatus());
        $this->assertTrue($resp->getData()['consent_required'] ?? false);
    }

    /**
     * HIGH-02: a consenting user who supplies pool/course/lastWrong ids they cannot access
     * must have that context DROPPED — buildContext is never called with foreign ids, so no
     * foreign pool questions / answer keys / course chunks are loaded. Access checks throw for
     * every id here, so all three are nulled and buildContext is skipped entirely.
     */
    public function testChatDropsInaccessibleLearningContext(): void {
        $this->enableAi();
        $this->telosMock->method('getAiConsentVersion')->willReturn('v1');

        // No access to ANY of the supplied context ids.
        $this->courseMock->method('findById')->willThrowException(new \RuntimeException('no access'));
        $this->poolMock->method('findByIdWithShareAccess')->willThrowException(new \RuntimeException('no access'));
        $this->questionMock->method('find')->willThrowException(new \RuntimeException('no access'));

        // All context dropped → buildContext must never run (it is only called when at least
        // one id is non-null after filtering).
        $this->ragMock->expects($this->never())->method('buildContext');

        // The chat still answers generically.
        $this->chatMemoryMock->method('loadMemory')->willReturn([]);
        $this->telosMock->method('getTelos')->willReturn(['telos' => null]);
        $this->userManagerMock->method('get')->willReturn(null);
        $this->geminiMock->expects($this->once())->method('chat')
            ->willReturn(['answer' => 'generic', 'fallback' => false, 'reason' => '']);

        $resp = $this->makeController('user-A')->chat('was ist ein vlan', 99, 99, 99);

        $this->assertSame(200, $resp->getStatus());
    }

    /**
     * HIGH-02 happy path: for context the user CAN access, buildContext receives the real ids
     * — the drop only removes foreign context, never legitimate learning context.
     */
    public function testChatKeepsAccessibleLearningContext(): void {
        $this->enableAi();
        $this->telosMock->method('getAiConsentVersion')->willReturn('v1');

        // Access granted for all ids (methods return without throwing).
        $this->courseMock->method('findById')->willReturn(['id' => 7]);
        $this->poolMock->method('findByIdWithShareAccess')->willReturn(['id' => 5]);
        $this->questionMock->method('find')->willReturn(['id' => 3]);
        $this->questionMock->method('isExamActiveOnPool')->willReturn(false); // no active exam

        // MED-10: 6th arg suppressAnswers=false when no exam is active.
        $this->ragMock->expects($this->once())->method('buildContext')
            ->with('user-A', 5, 7, 3, 'was ist ein vlan', false)
            ->willReturn(['chunks' => []]);

        $this->chatMemoryMock->method('loadMemory')->willReturn([]);
        $this->telosMock->method('getTelos')->willReturn(['telos' => null]);
        $this->userManagerMock->method('get')->willReturn(null);
        $this->geminiMock->method('chat')
            ->willReturn(['answer' => 'ok', 'fallback' => false, 'reason' => '']);

        $resp = $this->makeController('user-A')->chat('was ist ein vlan', 5, 7, 3);

        $this->assertSame(200, $resp->getStatus());
    }

    /**
     * MED-10: during an active exam on the pool, buildContext is called with
     * suppressAnswers=true so pool answer keys and the last-wrong correct answer are withheld
     * (VirtuProf cannot be used as an exam oracle).
     */
    public function testChatSuppressesAnswerContextDuringActiveExam(): void {
        $this->enableAi();
        $this->telosMock->method('getAiConsentVersion')->willReturn('v1');
        $this->poolMock->method('findByIdWithShareAccess')->willReturn(['id' => 5]);
        $this->questionMock->method('isExamActiveOnPool')->with(5, 'user-A')->willReturn(true);

        $this->ragMock->expects($this->once())->method('buildContext')
            ->with('user-A', 5, null, null, 'was ist ein vlan', true) // suppressAnswers=true
            ->willReturn(['chunks' => []]);

        $this->chatMemoryMock->method('loadMemory')->willReturn([]);
        $this->telosMock->method('getTelos')->willReturn(['telos' => null]);
        $this->userManagerMock->method('get')->willReturn(null);
        $this->geminiMock->method('chat')
            ->willReturn(['answer' => 'ok', 'fallback' => false, 'reason' => '']);

        $resp = $this->makeController('user-A')->chat('was ist ein vlan', 5);

        $this->assertSame(200, $resp->getStatus());
    }

    /** HIGH-03: interviewTurn (free text to Gemini) is consent-gated too. */
    public function testInterviewTurnWithoutConsentIsBlocked(): void {
        $this->enableAi();
        $this->telosMock->method('getAiConsentVersion')->willReturn('');

        $this->geminiMock->expects($this->never())->method('generateInterviewTurn');

        $resp = $this->makeController('user-A')->interviewTurn([['question' => 'q', 'answer' => 'a']], 2);

        $this->assertSame(403, $resp->getStatus());
        $this->assertTrue($resp->getData()['consent_required'] ?? false);
    }

    // ---- AUDIT v5.2.1 (pre-live review, finding E) — client questionContext exam-oracle guard ----

    /**
     * Build the questionContext payload shared by both finding-E tests below. When $questionId is
     * null, the key is omitted entirely (matches a real "no specific question" chat, not a
     * client-sent null) so the question-level vs. pool-level exam-check branches stay unambiguous.
     */
    private function tamperedQuestionContext(?int $questionId): array {
        $ctx = [
            'questionText' => 'Which VLAN is correct?',
            'answers' => ['10', '20'],
            'explanation' => 'VLAN 10 because...',
            'correctAnswerIndex' => 0,
            'pbqConfig' => ['correct' => ['vlan' => 10]],
        ];
        if ($questionId !== null) {
            $ctx['questionId'] = $questionId;
        }
        return $ctx;
    }

    /**
     * Assert that the questionContext GeminiService::chat() actually received (5th positional arg)
     * has the answer-bearing fields stripped, and that the hint level (6th arg) was capped to <=2.
     */
    private function expectSanitizedContextReachesGemini(): void {
        $this->geminiMock->expects($this->once())->method('chat')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(function ($ctx): bool {
                    $this->assertIsArray($ctx);
                    $this->assertArrayNotHasKey('explanation', $ctx, 'explanation must not reach Gemini during an active exam');
                    $this->assertArrayNotHasKey('correctAnswerIndex', $ctx, 'correctAnswerIndex must not reach Gemini during an active exam');
                    $this->assertNull($ctx['pbqConfig'], 'pbqConfig solution must be nulled during an active exam');
                    return true;
                }),
                $this->callback(function ($hintLevel): bool {
                    $this->assertLessThanOrEqual(2, $hintLevel, 'hintLevel must be capped to 2 during an active exam');
                    return true;
                })
            )
            ->willReturn(['answer' => 'ok', 'fallback' => false, 'reason' => '']);

        $this->chatMemoryMock->method('loadMemory')->willReturn([]);
        $this->telosMock->method('getTelos')->willReturn(['telos' => null]);
        $this->userManagerMock->method('get')->willReturn(null);
    }

    /**
     * Question-level exam check: questionContext carries a questionId with an active exam on its
     * pool (isExamActiveForQuestion=true) → explanation/correctAnswerIndex/pbqConfig are stripped
     * from what reaches Gemini, and hintLevel is capped, even though no poolId was supplied.
     */
    public function testChatStripsAnswerBearingContextWhenExamActiveOnQuestion(): void {
        $this->enableAi();
        $this->telosMock->method('getAiConsentVersion')->willReturn('v1');
        $this->questionMock->method('isExamActiveForQuestion')->with(3, 'user-A')->willReturn(true);
        $this->ragMock->expects($this->never())->method('buildContext'); // no poolId/courseId/lastWrong → no RAG context

        $this->expectSanitizedContextReachesGemini();

        $resp = $this->makeController('user-A')->chat(
            'was ist ein vlan',
            null,
            null,
            null,
            $this->tamperedQuestionContext(3),
            3
        );

        $this->assertSame(200, $resp->getStatus());
    }

    /**
     * Pool-level exam check: questionContext carries no questionId, but poolId has an active exam
     * (isExamActiveOnPool=true) → same stripping/capping applies via the elseif branch.
     */
    public function testChatStripsAnswerBearingContextWhenExamActiveOnPoolWithoutQuestionId(): void {
        $this->enableAi();
        $this->telosMock->method('getAiConsentVersion')->willReturn('v1');
        $this->poolMock->method('findByIdWithShareAccess')->willReturn(['id' => 5]);
        $this->questionMock->method('isExamActiveOnPool')->with(5, 'user-A')->willReturn(true);
        $this->ragMock->method('buildContext')->willReturn(['chunks' => []]);

        $this->expectSanitizedContextReachesGemini();

        $resp = $this->makeController('user-A')->chat(
            'was ist ein vlan',
            5,
            null,
            null,
            $this->tamperedQuestionContext(null),
            3
        );

        $this->assertSame(200, $resp->getStatus());
    }
}
