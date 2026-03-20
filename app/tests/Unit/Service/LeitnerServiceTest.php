<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\PoolShareMapper;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\LeitnerService;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\TranslationService;
use OCA\Learning\Service\XpService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class LeitnerServiceTest extends TestCase {
    public function testGetStatsCalculatesMasteryAndAccuracy(): void {
        $db = new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetchAll([
                ['box' => 1, 'count' => 1],
                ['box' => 5, 'count' => 3],
            ])),
            new FakeQueryBuilder(FakeResult::fromFetch(['due_count' => 2])),
            new FakeQueryBuilder(FakeResult::fromFetch([
                'total_correct' => 8,
                'total_answered' => 10,
            ])),
        ]);

        $poolMapper = $this->createMock(PoolMapper::class);
        $poolMapper->method('find')->with(42, 'alice')->willReturn(new \stdClass());

        $service = $this->createService($db, $poolMapper);

        $stats = $service->getStats(42, 'alice');

        $this->assertSame(4, $stats['total']);
        $this->assertSame(3, $stats['mastered']);
        $this->assertSame(75.0, $stats['mastery_percentage']);
        $this->assertSame(2, $stats['due_count']);
        $this->assertSame(80.0, $stats['accuracy']);
    }

    public function testGetDueQuestionsClampsLimitLoadsAnswersAndUsesDueFilter(): void {
        $itemsBuilder = new FakeQueryBuilder(FakeResult::fromFetchAll([
            [
                'id' => 77,
                'question_id' => 10,
                'text' => 'When is it due?',
                'question_type' => 'single',
                'pbq_subtype' => null,
                'pbq_config' => null,
            ],
        ]));
        $answersBuilder = new FakeQueryBuilder(FakeResult::fromFetchAll([
            [
                'id' => 99,
                'question_id' => 10,
                'text' => 'Today',
                'is_correct' => true,
                'position' => 1,
            ],
        ]));

        $db = new FakeDbConnection([$itemsBuilder, $answersBuilder]);
        $poolMapper = $this->createMock(PoolMapper::class);
        $poolMapper->method('find')->with(42, 'alice')->willReturn(new \stdClass());

        $service = $this->createService($db, $poolMapper);

        $items = $service->getDueQuestions(42, 'alice', 999);

        $this->assertSame(100, $itemsBuilder->maxResults);
        $this->assertSame(99, $items[0]['answers'][0]['id']);
        $this->assertContains(
            'lte',
            array_map(
                static fn(array $condition): string => $condition['type'] ?? '',
                $itemsBuilder->andWhereCalls
            )
        );
    }

    private function createService(FakeDbConnection $db, PoolMapper $poolMapper): LeitnerService {
        $shareMapper = $this->createMock(PoolShareMapper::class);
        $badgeService = $this->createMock(BadgeService::class);
        $streakService = $this->createMock(StreakService::class);
        $xpService = $this->createMock(XpService::class);
        $translationService = $this->createMock(TranslationService::class);
        $config = $this->createMock(IConfig::class);
        $courseService = $this->createMock(CourseService::class);

        $translationService->method('normalizeLang')
            ->willReturnCallback(static fn(?string $lang): ?string => $lang === '' ? null : $lang);
        $translationService->method('translateQuestions')
            ->willReturnCallback(static fn(array $items): array => $items);
        $config->method('getUserValue')->willReturn('');

        return new LeitnerService(
            $db,
            $poolMapper,
            $shareMapper,
            $badgeService,
            $streakService,
            $xpService,
            new \OCA\Learning\Tests\Support\FakeCacheFactory(),
            $translationService,
            $config,
            $courseService
        );
    }
}
