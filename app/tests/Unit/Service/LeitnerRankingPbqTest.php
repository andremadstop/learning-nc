<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\PoolShareMapper;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\FsrsService;
use OCA\Learning\Service\LeitnerService;
use OCA\Learning\Service\LernprofilService;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\TranslationService;
use OCA\Learning\Service\XpService;
use OCA\Learning\Tests\Support\FakeCacheFactory;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * A ranking PBQ answered in the Leitner/FSRS queue is graded like in training: items in their
 * correct_position score points, and at least half of them make the answer correct.
 */
#[AllowMockObjectsWithoutExpectations]
final class LeitnerRankingPbqTest extends TestCase {
    private const RANKING_CONFIG = [
        'items' => [
            ['id' => 'a', 'text' => 'First', 'correct_position' => 1],
            ['id' => 'b', 'text' => 'Second', 'correct_position' => 2],
            ['id' => 'c', 'text' => 'Third', 'correct_position' => 3],
        ],
    ];

    public function testCorrectRankingIsCorrect(): void {
        $response = $this->answer(['a' => 1, 'b' => 2, 'c' => 3]);

        $this->assertTrue($response['correct']);
        $this->assertSame(3, $response['pbq_points']);
        $this->assertSame(3, $response['pbq_max_points']);
    }

    public function testWrongRankingIsWrong(): void {
        $response = $this->answer(['a' => 3, 'b' => 1, 'c' => 2]);

        $this->assertFalse($response['correct']);
        $this->assertSame(0, $response['pbq_points']);
        $this->assertSame(3, $response['pbq_max_points']);
    }

    private function answer(array $positions): array {
        $db = new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetch([
                'id' => 77, 'question_id' => 10, 'pool_id' => 5, 'user_id' => 'alice', 'box' => 1,
                'next_review' => 0, 'correct_count' => 0, 'incorrect_count' => 0, 'last_rating' => null,
            ])),
            new FakeQueryBuilder(FakeResult::fromFetch(['question_type' => 'pbq'])),
            new FakeQueryBuilder(FakeResult::fromFetch([
                'pbq_subtype' => 'ranking',
                'pbq_config' => json_encode(self::RANKING_CONFIG),
            ])),
        ]);

        $streakService = $this->createMock(StreakService::class);
        $streakService->method('getStreak')->willReturn(['current_streak' => 0]);
        $xpService = $this->createMock(XpService::class);
        $xpService->method('calculateXp')->willReturn(['level' => 1]);
        $translationService = $this->createMock(TranslationService::class);
        $translationService->method('normalizeLang')->willReturn(null);
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturn('');

        $service = new LeitnerService(
            $db,
            $this->createMock(PoolMapper::class),
            $this->createMock(PoolShareMapper::class),
            $this->createMock(BadgeService::class),
            $streakService,
            $xpService,
            new FakeCacheFactory(),
            $translationService,
            $config,
            $this->createMock(CourseService::class),
            $this->createMock(LernprofilService::class),
            new FsrsService()
        );

        // preview: score only, no write phase
        return $service->answerQuestion(77, null, 'alice', null, null, $positions, null, true);
    }
}
