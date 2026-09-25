<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\SummaryController;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\CourseSummaryService;
use OCA\Learning\Service\TelosService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * 5.5.2: the course narrative sends learning data to the LLM. It must go through
 * TelosService::hasAiConsent() (exact current consent version) instead of reading the
 * entity and accepting any non-empty version.
 */
class SummaryControllerConsentTest extends TestCase {
    private function makeController(TelosService $telos, CourseSummaryService $summary): SummaryController {
        $courses = $this->createMock(CourseService::class);
        $courses->method('findById')->willReturn(['id' => 7, 'is_instructor' => false]);

        return new SummaryController('learning', $this->createMock(IRequest::class), $summary, $courses, $telos, 'alice');
    }

    public function testNarrativeWithoutValidConsentNeverReachesTheModel(): void {
        $telos = $this->createMock(TelosService::class);
        $telos->method('getAiConsentVersion')->willReturn('0.9-stale');
        $telos->method('hasAiConsent')->with('alice')->willReturn(false);
        $summary = $this->createMock(CourseSummaryService::class);
        $summary->expects($this->never())->method('generateAndCacheNarrative');

        $data = $this->makeController($telos, $summary)->generateNarrative(7)->getData();

        $this->assertTrue($data['consent_required']);
        $this->assertNull($data['narrative']);
    }

    public function testNarrativeWithValidConsentIsGenerated(): void {
        $telos = $this->createMock(TelosService::class);
        $telos->method('hasAiConsent')->with('alice')->willReturn(true);
        $summary = $this->createMock(CourseSummaryService::class);
        $summary->expects($this->once())->method('generateAndCacheNarrative')->with(7, 'alice')->willReturn('Text');

        $data = $this->makeController($telos, $summary)->generateNarrative(7)->getData();

        $this->assertSame('Text', $data['narrative']);
    }
}
