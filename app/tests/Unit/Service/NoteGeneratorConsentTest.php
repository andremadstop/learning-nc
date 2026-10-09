<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\GeminiService;
use OCA\Learning\Service\LernbotFileService;
use OCA\Learning\Service\LernplanService;
use OCA\Learning\Service\LernprofilService;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\LlmConsentException;
use OCA\Learning\Service\Llm\LlmPurpose;
use OCA\Learning\Service\Llm\LlmSession;
use OCA\Learning\Service\NoteGeneratorService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 5.5.2: generateSummary() sends the user's weak topic and wrongly answered questions to the
 * AI provider. It is reached from the weekly background job, which had no consent gate at all,
 * so the service itself must refuse before loading any data or calling the model.
 *
 * 5.7.0 review finding (MED): the session is begun before the learning data is read, so the
 * snapshot and the consent epoch are fixed for the whole assembly and the prompt is sent with
 * exactly that session (plan: begin before building the context). Same for the Lernplan.
 */
class NoteGeneratorConsentTest extends TestCase {
    private GeminiService&MockObject $gemini;
    private LernprofilService&MockObject $profile;

    protected function setUp(): void {
        $this->gemini = $this->createMock(GeminiService::class);
        $this->profile = $this->createMock(LernprofilService::class);
    }

    private function service(): NoteGeneratorService {
        return new NoteGeneratorService(
            $this->gemini,
            $this->createMock(LernbotFileService::class),
            $this->profile,
            new FakeDbConnection(),
            $this->createMock(LoggerInterface::class)
        );
    }

    private static function session(LlmPurpose $p): LlmSession {
        return new LlmSession(ConnectionStore::legacyGemini(true), 'alice', $p, 3);
    }

    public function testGenerateSummaryWithoutConsentNeverCallsTheModel(): void {
        $this->gemini->method('begin')->with('alice', LlmPurpose::Note)->willThrowException(new LlmConsentException());
        $this->gemini->expects($this->never())->method('generateNote');
        $this->gemini->expects($this->never())->method('resolveResponseLanguage');
        $this->profile->expects($this->never())->method($this->anything());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('consent_required');
        $this->service()->generateSummary('alice', 5);
    }

    /**
     * 5.7.0 Task B5: the refusal is an LlmConsentException (message still consent_required), so
     * the weekly job counts it as skipped_consent and controllers answer consent_required.
     */
    public function testGenerateSummaryWithoutConsentThrowsTheConsentException(): void {
        $this->gemini->method('begin')->with('alice', LlmPurpose::WeeklyNote)->willThrowException(new LlmConsentException());
        $this->gemini->expects($this->never())->method('generateNote');

        $this->expectException(LlmConsentException::class);
        $this->service()->generateSummary('alice', 5, null, LlmPurpose::WeeklyNote);
    }

    /** No pool row: the service stops after begin(), before reading anything else. */
    public function testTheSessionIsBegunBeforeAnyLearningDataIsRead(): void {
        $this->gemini->expects($this->once())->method('begin')->with('alice', LlmPurpose::WeeklyNote)
            ->willReturn(self::session(LlmPurpose::WeeklyNote));
        $this->profile->expects($this->never())->method($this->anything());

        try {
            $this->service()->generateSummary('alice', 5, null, LlmPurpose::WeeklyNote);
            $this->fail('pool 5 does not exist');
        } catch (LlmConsentException $e) {
            $this->fail('consent was given');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not found', $e->getMessage());
        }
    }

    /** A caller's session is used as is: no second begin(). */
    public function testACallersSessionIsNotBegunAgain(): void {
        $this->gemini->expects($this->never())->method('begin');

        $this->expectException(\RuntimeException::class);
        $this->service()->generateSummary('alice', 5, null, LlmPurpose::WeeklyNote, self::session(LlmPurpose::WeeklyNote));
    }

    public function testTheLernplanIsBegunBeforeTheWeakTopicsAreReadAndSentWithThatSession(): void {
        $order = [];
        $session = self::session(LlmPurpose::Lernplan);
        $this->gemini->method('begin')->willReturnCallback(function (string $uid, LlmPurpose $p) use (&$order, $session): LlmSession {
            $order[] = 'begin:' . $uid . ':' . $p->value;
            return $session;
        });
        $this->profile->method('getWeakestTopics')->willReturnCallback(function () use (&$order): array {
            $order[] = 'weak_topics';
            return [];
        });
        $this->gemini->expects($this->once())->method('generateNote')
            ->with($this->anything(), $this->anything(), 'alice', LlmPurpose::Lernplan, $this->identicalTo($session))
            ->willReturn('# Plan');

        $plans = new LernplanService($this->gemini, $this->createMock(LernbotFileService::class), $this->profile,
            $this->createMock(IConfig::class), $this->createMock(LoggerInterface::class));
        $plans->generateWeeklyPlan('alice');

        $this->assertSame(['begin:alice:lernplan', 'weak_topics'], $order);
    }

    public function testTheLernplanWithoutConsentReadsNothing(): void {
        $this->gemini->method('begin')->willThrowException(new LlmConsentException());
        $this->profile->expects($this->never())->method($this->anything());
        $this->gemini->expects($this->never())->method('generateNote');

        $plans = new LernplanService($this->gemini, $this->createMock(LernbotFileService::class), $this->profile,
            $this->createMock(IConfig::class), $this->createMock(LoggerInterface::class));

        $this->expectException(LlmConsentException::class);
        $plans->generateWeeklyPlan('alice');
    }
}
