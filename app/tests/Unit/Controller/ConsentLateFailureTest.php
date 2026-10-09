<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\AiController;
use OCA\Learning\Controller\LernplanController;
use OCA\Learning\Controller\SummaryController;
use OCA\Learning\Controller\TelosController;
use OCA\Learning\Service\AIService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\CourseSummaryService;
use OCA\Learning\Service\LernplanService;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\ConsentService;
use OCA\Learning\Service\Llm\LlmConsentException;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\TelosService;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 5.7.0 review finding: the controller's early consent check passes, then the user revokes (or the
 * admin changes the recipient) before the gateway sends. The gateway refuses — nothing leaves —
 * and the facades rethrow LlmConsentException. One level up it must still become the
 * consent_required answer the client re-opens the dialog on, not a generic error, a silent null
 * or a 500 carrying the exception message.
 */
class ConsentLateFailureTest extends TestCase {
    private TelosService&MockObject $telos;

    protected function setUp(): void {
        $this->telos = $this->createMock(TelosService::class);
        // the early check passes: consent disappears only afterwards
        $this->telos->method('hasAiConsent')->willReturn(true);
    }

    private static function assertConsentRequired(int $status, mixed $data, int $expectedStatus = 403): void {
        self::assertSame($expectedStatus, $status);
        self::assertIsArray($data);
        self::assertTrue($data['consent_required'] ?? false, 'the client must learn that consent is required');
    }

    public function testTelosInterview(): void {
        $this->telos->method('processInterview')->willThrowException(new LlmConsentException());
        $controller = new TelosController('learning', $this->createMock(IRequest::class), 'alice', $this->telos,
            $this->createMock(CourseService::class), $this->createMock(ConsentService::class), $this->createMock(ConnectionStore::class));

        $resp = $controller->processInterview(str_repeat('Ich lerne für Security+. ', 3));

        self::assertConsentRequired($resp->getStatus(), $resp->getData());
    }

    /** Same answer shape as the controller's own early check (200 + consent_required). */
    public function testCourseNarrative(): void {
        $summary = $this->createMock(CourseSummaryService::class);
        $summary->method('generateAndCacheNarrative')->willThrowException(new LlmConsentException());
        $courses = $this->createMock(CourseService::class);
        $courses->method('findById')->willReturn(['id' => 7, 'is_instructor' => false]);
        $controller = new SummaryController('learning', $this->createMock(IRequest::class), $summary, $courses, $this->telos, 'alice');

        $resp = $controller->generateNarrative(7);

        self::assertConsentRequired($resp->getStatus(), $resp->getData(), 200);
        self::assertNull($resp->getData()['narrative']);
    }

    private function aiController(AIService $ai, QuestionService $questions): AiController {
        return new AiController('learning', $this->createMock(IRequest::class), $ai, $questions,
            $this->createMock(IDBConnection::class), $this->createMock(LoggerInterface::class), $this->telos, 'alice');
    }

    public function testAiGenerate(): void {
        $ai = $this->createMock(AIService::class);
        $ai->method('generateQuestions')->willThrowException(new LlmConsentException());

        $resp = $this->aiController($ai, $this->createMock(QuestionService::class))->generate(5, str_repeat('a', 60), 10, 'de');

        self::assertConsentRequired($resp->getStatus(), $resp->getData());
    }

    public function testAiExplain(): void {
        $ai = $this->createMock(AIService::class);
        $ai->method('schedulePrompt')->willThrowException(new LlmConsentException());
        $questions = $this->createMock(QuestionService::class);
        $questions->method('find')->willReturn(['id' => 3, 'pool_id' => 0, 'text' => 'Q', 'answers' => [], 'explanation' => '']);

        $resp = $this->aiController($ai, $questions)->explain(3);

        self::assertConsentRequired($resp->getStatus(), $resp->getData());
    }

    /** Late consent failure in the weekly plan: 403 consent_required, not "Generation failed" 500. */
    public function testLernplan(): void {
        $plans = $this->createMock(LernplanService::class);
        $plans->method('generateWeeklyPlan')->willThrowException(new LlmConsentException());
        $controller = new LernplanController('learning', $this->createMock(IRequest::class), 'alice', $plans, $this->telos);

        $resp = $controller->generatePlan();

        self::assertConsentRequired($resp->getStatus(), $resp->getData());
        self::assertStringNotContainsString('Generation failed', (string)json_encode($resp->getData()));
    }
}
