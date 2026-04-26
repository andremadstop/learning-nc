<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\VirtuProfController;
use OCA\Learning\Service\AiChatMemoryService;
use OCA\Learning\Service\GeminiService;
use OCA\Learning\Service\LernplanService;
use OCA\Learning\Service\NoteGeneratorService;
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
            $this->userManagerMock
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
}
