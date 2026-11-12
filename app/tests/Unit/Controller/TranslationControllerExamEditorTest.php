<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\TranslationController;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\TranslationService;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * 2026-11 audit (same class as recheck D1): TranslationDialog loads the question translations and
 * PUTs every language back with `explanation: explanation || null`. While a pool editor had a graded
 * exam of their own open on the pool, the GET withheld the translated explanations, and saving the
 * dialog overwrote each stored explanation translation with NULL. Editors are exempt, like in find().
 */
#[AllowMockObjectsWithoutExpectations]
class TranslationControllerExamEditorTest extends TestCase {
    private function controller(bool $withhold, TranslationService $service): TranslationController {
        $questions = $this->createMock(QuestionService::class);
        // The exam is open in both cases; only the editor exemption decides.
        $questions->method('isExamActiveForQuestion')->willReturn(true);
        $questions->method('mustWithholdSolutionForQuestion')->willReturn($withhold);
        return new TranslationController('learning', $this->createMock(IRequest::class), $service, $questions, 'editor');
    }

    public function testEditorWithOwnOpenExamGetsTheExplanationsToSaveBack(): void {
        $service = $this->createMock(TranslationService::class);
        $service->expects($this->once())->method('getQuestionTranslations')->with(5, false)->willReturn([]);
        $this->controller(false, $service)->questionTranslations(5);
    }

    public function testLearnerWithOpenExamStillGetsThemWithheld(): void {
        $service = $this->createMock(TranslationService::class);
        $service->expects($this->once())->method('getQuestionTranslations')->with(5, true)->willReturn([]);
        $this->controller(true, $service)->questionTranslations(5);
    }
}
