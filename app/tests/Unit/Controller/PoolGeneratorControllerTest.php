<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\PoolGeneratorController;
use OCA\Learning\Service\PoolGeneratorService;
use OCA\Learning\Service\TelosService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * AUDIT v5.2.1 (finding A — consent gate): PoolGeneratorController::fromText() and ::fromFile()
 * must gate on TelosService::hasAiConsent() BEFORE any PoolGeneratorService call reaches the LLM.
 * When consent is missing, both must return 403 with `consent_required=true` and never touch
 * PoolGeneratorService.
 *
 * @group audit-a-consent
 */
class PoolGeneratorControllerTest extends TestCase {
    /** @var IRequest&\PHPUnit\Framework\MockObject\MockObject */
    private $requestMock;
    /** @var PoolGeneratorService&\PHPUnit\Framework\MockObject\MockObject */
    private $serviceMock;
    /** @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $loggerMock;
    /** @var TelosService&\PHPUnit\Framework\MockObject\MockObject */
    private $telosMock;

    protected function setUp(): void {
        $this->requestMock = $this->createMock(IRequest::class);
        $this->serviceMock = $this->createMock(PoolGeneratorService::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->telosMock = $this->createMock(TelosService::class);
    }

    private function makeController(?string $userId): PoolGeneratorController {
        return new PoolGeneratorController(
            'learning',
            $this->requestMock,
            $this->serviceMock,
            $this->loggerMock,
            $this->telosMock,
            $userId
        );
    }

    // ---- fromText() ------------------------------------------------------------

    public function testFromTextBlockedWithoutConsent(): void {
        $this->telosMock->method('hasAiConsent')->with('user-A')->willReturn(false);

        $this->serviceMock->expects($this->never())->method('generateFromText');

        $resp = $this->makeController('user-A')->fromText(str_repeat('a', 60), 20);

        $this->assertSame(403, $resp->getStatus());
        $this->assertTrue($resp->getData()['consent_required'] ?? false);
    }

    public function testFromTextAllowsWhenConsentGranted(): void {
        $this->telosMock->method('hasAiConsent')->with('user-A')->willReturn(true);

        // Consent gate passed — the generator itself is reached (and fails for an unrelated reason).
        $this->serviceMock->expects($this->once())->method('generateFromText')
            ->with(str_repeat('a', 60), 'user-A', 20)
            ->willThrowException(new \RuntimeException('provider unavailable'));

        $resp = $this->makeController('user-A')->fromText(str_repeat('a', 60), 20);

        $this->assertSame(400, $resp->getStatus());
        $this->assertArrayNotHasKey('consent_required', $resp->getData());
    }

    public function testFromTextWithoutUserIsUnauthenticatedBeforeConsentCheck(): void {
        $this->telosMock->expects($this->never())->method('hasAiConsent');

        $resp = $this->makeController(null)->fromText(str_repeat('a', 60), 20);

        $this->assertSame(401, $resp->getStatus());
    }

    // ---- fromFile() ------------------------------------------------------------

    public function testFromFileBlockedWithoutConsent(): void {
        $this->telosMock->method('hasAiConsent')->with('user-A')->willReturn(false);

        $this->serviceMock->expects($this->never())->method('generateFromFile');

        $resp = $this->makeController('user-A')->fromFile('/Learning/notes.pdf', 20);

        $this->assertSame(403, $resp->getStatus());
        $this->assertTrue($resp->getData()['consent_required'] ?? false);
    }

    public function testFromFileAllowsWhenConsentGranted(): void {
        $this->telosMock->method('hasAiConsent')->with('user-A')->willReturn(true);

        $this->serviceMock->expects($this->once())->method('generateFromFile')
            ->with('/Learning/notes.pdf', 'user-A', 20)
            ->willThrowException(new \RuntimeException('unsupported file type'));

        $resp = $this->makeController('user-A')->fromFile('/Learning/notes.pdf', 20);

        $this->assertSame(400, $resp->getStatus());
        $this->assertArrayNotHasKey('consent_required', $resp->getData());
    }
}
