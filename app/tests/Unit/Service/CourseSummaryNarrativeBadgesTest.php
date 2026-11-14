<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\RagChunkMapper;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\CourseSummaryService;
use OCA\Learning\Service\GeminiService;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\LlmPurpose;
use OCA\Learning\Service\Llm\LlmSession;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Audit #71: the narrative prompt read the badges through a key getUserBadges() never returns
 * ('badge_key'), so it always said 'VERDIENTE BADGES: Keine' — and the list held unearned badges.
 */
#[AllowMockObjectsWithoutExpectations]
class CourseSummaryNarrativeBadgesTest extends TestCase {
    public function testTheNarrativePromptNamesOnlyTheEarnedBadges(): void {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('begin')->willReturn(new LlmSession(ConnectionStore::legacyGemini(true), 'alice', LlmPurpose::CourseNarrative, 0));
        $prompt = null;
        $gemini->method('generateNote')->willReturnCallback(function (string $sys, string $user) use (&$prompt): string {
            $prompt = $user;
            return 'Narrative';
        });

        $service = $this->getMockBuilder(CourseSummaryService::class)
            ->setConstructorArgs([
                new FakeDbConnection([]),
                $this->createMock(CourseMemberMapper::class),
                $this->createMock(BadgeService::class),
                $this->createMock(StreakService::class),
                $this->createMock(RagChunkMapper::class),
                $gemini,
            ])
            ->onlyMethods(['getSnapshot', 'getStudentSummary'])
            ->getMock();
        $service->method('getSnapshot')->willReturn(null);
        $service->method('getStudentSummary')->willReturn([
            'badges' => [
                ['badge_id' => 'first_steps', 'name' => 'Erste Schritte', 'earned' => true],
                ['badge_id' => 'streak_7', 'name' => 'Wochenserie', 'earned' => true],
                ['badge_id' => 'marathon', 'name' => 'Marathon', 'earned' => false],
            ],
        ]);

        $this->assertSame('Narrative', $service->generateAndCacheNarrative(7, 'alice'));
        $this->assertIsString($prompt);
        $this->assertStringContainsString('VERDIENTE BADGES: Erste Schritte, Wochenserie', $prompt);
        $this->assertStringNotContainsString('Marathon', $prompt);
    }
}
