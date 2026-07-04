<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\NoteGeneratorController;
use OCA\Learning\Service\NoteGeneratorService;
use OCA\Learning\Service\TelosService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * AUDIT v5.2.1 (finding A — consent gate): NoteGeneratorController::generate() must gate on
 * TelosService::hasAiConsent() BEFORE any NoteGeneratorService call reaches the LLM. When consent
 * is missing, it must return 403 with `consent_required=true` and never touch NoteGeneratorService.
 *
 * @group audit-a-consent
 */
class NoteGeneratorControllerTest extends TestCase {
    /** @var NoteGeneratorService&\PHPUnit\Framework\MockObject\MockObject */
    private $noteGenMock;
    /** @var TelosService&\PHPUnit\Framework\MockObject\MockObject */
    private $telosMock;

    protected function setUp(): void {
        $this->noteGenMock = $this->createMock(NoteGeneratorService::class);
        $this->telosMock = $this->createMock(TelosService::class);
    }

    /**
     * @param IRequest&\PHPUnit\Framework\MockObject\MockObject $request
     */
    private function makeController($request, FakeDbConnection $db, ?string $userId): NoteGeneratorController {
        return new NoteGeneratorController(
            'learning',
            $request,
            $userId,
            $this->noteGenMock,
            $db,
            $this->telosMock
        );
    }

    public function testGenerateBlockedWithoutConsent(): void {
        $this->telosMock->method('hasAiConsent')->with('user-A')->willReturn(false);

        // The consent gate runs before pool_id is even read from the request, so no getParam
        // stubbing is required — but the mock must not be touched by generateSummary either way.
        $request = $this->createMock(IRequest::class);
        $this->noteGenMock->expects($this->never())->method('generateSummary');

        $resp = $this->makeController($request, new FakeDbConnection(), 'user-A')->generate();

        $this->assertSame(403, $resp->getStatus());
        $this->assertTrue($resp->getData()['consent_required'] ?? false);
    }

    public function testGenerateAllowsWhenConsentGranted(): void {
        $this->telosMock->method('hasAiConsent')->with('user-A')->willReturn(true);

        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, $default = null) => $key === 'pool_id' ? 5 : $default
        );

        // Consent gate passed — a fresh FakeDbConnection answers every access-check query with "no
        // row found" (default FakeQueryBuilder → empty FakeResult → fetch()===false), so
        // userCanAccessPool() denies access. That is a DIFFERENT error than consent_required, which
        // proves the gate let the request through before this unrelated failure.
        $this->noteGenMock->expects($this->never())->method('generateSummary');

        $resp = $this->makeController($request, new FakeDbConnection(), 'user-A')->generate();

        $this->assertSame(403, $resp->getStatus());
        $this->assertArrayNotHasKey('consent_required', $resp->getData());
    }

    public function testGenerateWithoutUserIsUnauthenticatedBeforeConsentCheck(): void {
        $this->telosMock->expects($this->never())->method('hasAiConsent');

        $request = $this->createMock(IRequest::class);

        $resp = $this->makeController($request, new FakeDbConnection(), null)->generate();

        $this->assertSame(401, $resp->getStatus());
    }
}
