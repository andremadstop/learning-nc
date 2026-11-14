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
 * Remediation queue limit (audit #77) and the trouble_fixer badge notification (audit #76).
 */
#[AllowMockObjectsWithoutExpectations]
final class LeitnerQueueAndBadgeTest extends TestCase {

    private function service(FakeDbConnection $db, ?BadgeService $badges = null): LeitnerService {
        $streakService = $this->createMock(StreakService::class);
        $streakService->method('getStreak')->willReturn(['current_streak' => 0]);
        $xpService = $this->createMock(XpService::class);
        $xpService->method('calculateXp')->willReturn(['level' => 1]);
        $xpService->method('applyMultiplier')->willReturnArgument(0);
        $translationService = $this->createMock(TranslationService::class);
        $translationService->method('normalizeLang')->willReturn(null);
        $translationService->method('translateQuestions')->willReturnCallback(static fn(array $items): array => $items);
        $translationService->method('translateAnswerRows')->willReturnCallback(static fn(array $rows): array => $rows);
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturn('');

        return new LeitnerService(
            $db,
            $this->createMock(PoolMapper::class),
            $this->createMock(PoolShareMapper::class),
            $badges ?? $this->createMock(BadgeService::class),
            $streakService,
            $xpService,
            new FakeCacheFactory(),
            $translationService,
            $config,
            $this->createMock(CourseService::class),
            $this->createMock(LernprofilService::class),
            new FsrsService()
        );
    }

    public function testRemediationLimitAppliesAfterTheAccuracyFilter(): void {
        $rows = [];
        // 20 items with many errors but ≥30 % accuracy come first (ORDER BY incorrect_count DESC) …
        for ($i = 1; $i <= 20; $i++) {
            $rows[] = ['id' => $i, 'question_id' => 100 + $i, 'pool_id' => 1, 'correct_count' => 8, 'incorrect_count' => 10,
                'question_type' => 'single', 'pbq_config' => null];
        }
        // … then 5 items at 0 %: these are the trouble spots
        for ($i = 21; $i <= 25; $i++) {
            $rows[] = ['id' => $i, 'question_id' => 100 + $i, 'pool_id' => 1, 'correct_count' => 0, 'incorrect_count' => 3,
                'question_type' => 'single', 'pbq_config' => null];
        }
        $itemsBuilder = new FakeQueryBuilder(FakeResult::fromFetchAll($rows));
        $db = new FakeDbConnection([$itemsBuilder, new FakeQueryBuilder(FakeResult::fromFetchAll([]))]);

        $items = $this->service($db)->getRemediationQueue('alice', 3);

        $this->assertNull($itemsBuilder->maxResults, 'a SQL LIMIT before the PHP filter cuts the trouble spots off');
        $this->assertSame([21, 22, 23], array_column($items, 'id'));
    }

    public function testTroubleFixerBadgeIsNotifiedOnce(): void {
        $badge = ['id' => 'trouble_fixer', 'name' => 'Trouble Fixer'];
        $badges = $this->createMock(BadgeService::class);
        $badges->expects($this->once())->method('checkAndAward')
            ->with('alice', 'trouble_fix', [], false)
            ->willReturn([$badge]);
        $badges->expects($this->once())->method('dispatchNotifications')->with('alice', [$badge]);

        $db = new FakeDbConnection([
            // item in box 1
            new FakeQueryBuilder(FakeResult::fromFetch([
                'id' => 77, 'question_id' => 10, 'pool_id' => 5, 'user_id' => 'alice', 'box' => 1,
                'next_review' => 0, 'correct_count' => 0, 'incorrect_count' => 1, 'last_rating' => null,
                'stability' => null, 'difficulty' => null, 'last_reviewed' => null,
            ])),
            new FakeQueryBuilder(FakeResult::fromFetch(['question_type' => 'single'])),
            new FakeQueryBuilder(FakeResult::fromFetch(['is_correct' => true])),
            new FakeQueryBuilder(null, 1), // CAS update of the item
            new FakeQueryBuilder(FakeResult::fromFetch(['cnt' => 1])), // cards today
            new FakeQueryBuilder(FakeResult::fromFetch(['daily_goal' => 20])),
            new FakeQueryBuilder(FakeResult::fromFetch(false)), // active exam check
            new FakeQueryBuilder(FakeResult::fromFetchAll([['id' => 3, 'text' => 'A']])),
        ]);

        $response = $this->service($db, $badges)->answerQuestion(77, 3, 'alice');

        $this->assertTrue($response['correct']);
        $this->assertSame([$badge], $response['newly_earned_badges']);
    }
}
