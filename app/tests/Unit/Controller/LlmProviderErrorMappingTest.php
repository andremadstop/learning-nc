<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\LernplanController;
use OCA\Learning\Controller\NoteGeneratorController;
use OCA\Learning\Service\LernplanService;
use OCA\Learning\Service\Llm\LlmConsentException;
use OCA\Learning\Service\Llm\LlmProviderException;
use OCA\Learning\Service\NoteGeneratorService;
use OCA\Learning\Service\TelosService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 2026-11 audit (S12): since the LLM gateway, a failed model call is an LlmProviderException whose
 * message is only its category ('timeout', 'disabled', 'rate_limit' ...). The note generator and the
 * weekly plan still matched the old Gemini texts ('API key not configured', 'HTTP ', 'curl error'),
 * so every provider failure became a 500 "failed: timeout". They must map the category instead.
 */
#[AllowMockObjectsWithoutExpectations]
class LlmProviderErrorMappingTest extends TestCase {

    /** @return array<string, array{0: LlmProviderException, 1: int}> */
    public static function providerErrors(): array {
        return [
            'timeout'     => [new LlmProviderException('timeout'), 503],
            'network'     => [new LlmProviderException('network'), 503],
            'overloaded'  => [new LlmProviderException('overloaded'), 503],
            'empty'       => [new LlmProviderException('empty'), 503],
            'disabled'    => [new LlmProviderException('disabled'), 503],
            'config'      => [new LlmProviderException('config', '', 'admin-only detail'), 503],
            'auth'        => [new LlmProviderException('auth'), 503],
            'rate_limit'  => [new LlmProviderException('rate_limit'), 429],
            'refusal'     => [new LlmProviderException('refusal'), 500],
            'bad_request' => [new LlmProviderException('bad_request'), 500],
        ];
    }

    private function telos(): TelosService {
        $telos = $this->createMock(TelosService::class);
        $telos->method('hasAiConsent')->willReturn(true);
        return $telos;
    }

    private function noteController(NoteGeneratorService $notes): NoteGeneratorController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, $default = null) => $key === 'pool_id' ? 5 : $default
        );
        // the first access query (ownership) finds a row
        $db = new FakeDbConnection([new FakeQueryBuilder(new FakeResult([['id' => 5]]))]);
        return new NoteGeneratorController('learning', $request, 'alice', $notes, $db, $this->telos());
    }

    private static function assertNoCategoryLeak(LlmProviderException $e, array $data): void {
        $error = (string)($data['error'] ?? '');
        self::assertNotSame('', $error);
        self::assertStringNotContainsString('failed: ' . $e->category, $error);
        self::assertStringNotContainsString('admin-only detail', (string)json_encode($data));
    }

    #[DataProvider('providerErrors')]
    public function testNoteGeneratorMapsProviderCategory(LlmProviderException $e, int $status): void {
        $notes = $this->createMock(NoteGeneratorService::class);
        $notes->expects($this->once())->method('generateSummary')->willThrowException($e);

        $resp = $this->noteController($notes)->generate();

        self::assertSame($status, $resp->getStatus());
        self::assertArrayNotHasKey('consent_required', $resp->getData());
        self::assertNoCategoryLeak($e, $resp->getData());
    }

    public function testNoteGeneratorLateConsentStaysConsentRequired(): void {
        $notes = $this->createMock(NoteGeneratorService::class);
        $notes->method('generateSummary')->willThrowException(new LlmConsentException());

        $resp = $this->noteController($notes)->generate();

        self::assertSame(403, $resp->getStatus());
        self::assertTrue($resp->getData()['consent_required'] ?? false);
    }

    public function testNoteGeneratorOtherRuntimeErrorUnchanged(): void {
        $notes = $this->createMock(NoteGeneratorService::class);
        $notes->method('generateSummary')->willThrowException(new \RuntimeException('Pool has no questions'));

        $resp = $this->noteController($notes)->generate();

        self::assertSame(500, $resp->getStatus());
        self::assertSame('Note generation failed: Pool has no questions', $resp->getData()['error']);
    }

    #[DataProvider('providerErrors')]
    public function testLernplanMapsProviderCategory(LlmProviderException $e, int $status): void {
        $plans = $this->createMock(LernplanService::class);
        $plans->expects($this->once())->method('generateWeeklyPlan')->willThrowException($e);
        $controller = new LernplanController('learning', $this->createMock(IRequest::class), 'alice', $plans, $this->telos());

        $resp = $controller->generatePlan();

        self::assertSame($status, $resp->getStatus());
        self::assertArrayNotHasKey('consent_required', $resp->getData());
        self::assertNoCategoryLeak($e, $resp->getData());
    }

    public function testFortschrittNotFoundStays404(): void {
        $plans = $this->createMock(LernplanService::class);
        $plans->method('generateFortschritt')->willThrowException(new \RuntimeException('Course not found'));
        $controller = new LernplanController('learning', $this->createMock(IRequest::class), 'alice', $plans, $this->telos());

        $resp = $controller->generateFortschritt();

        self::assertSame(404, $resp->getStatus());
    }
}
