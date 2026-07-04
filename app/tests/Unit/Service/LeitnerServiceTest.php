<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\PoolShareMapper;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\FsrsService;
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
        $poolMapper->method('find')->with(42, 'alice')->willReturn(new \OCA\Learning\Db\Pool());

        $service = $this->createService($db, $poolMapper);

        $stats = $service->getStats(42, 'alice');

        $this->assertSame(4, $stats['total']);
        $this->assertSame(3, $stats['mastered']);
        $this->assertSame(75.0, $stats['mastery_percentage']);
        $this->assertSame(2, $stats['due_count']);
        $this->assertSame(80.0, $stats['accuracy']);
    }

    public function testGetDueQuestionsLoadsAnswersAndUsesDueFilter(): void {
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
        $poolMapper->method('find')->with(42, 'alice')->willReturn(new \OCA\Learning\Db\Pool());

        $service = $this->createService($db, $poolMapper);

        $items = $service->getDueQuestions(42, 'alice', 999);

        $this->assertSame(99, $items[0]['answers'][0]['id']);
        $this->assertContains(
            'lte',
            array_map(
                static fn(array $condition): string => $condition['type'] ?? '',
                $itemsBuilder->andWhereCalls
            )
        );
    }

    public function testGetDueQuestionsSortsByRetrievabilityAscending(): void {
        $now = time();
        $itemsBuilder = new FakeQueryBuilder(FakeResult::fromFetchAll([
            [
                'id' => 77,
                'question_id' => 10,
                'text' => 'Newer card',
                'question_type' => 'single',
                'pbq_subtype' => null,
                'pbq_config' => null,
                'next_review' => $now - 60,
                'stability' => 1.0,
                'last_reviewed' => $now - 86400,
                'box' => 2,
            ],
            [
                'id' => 78,
                'question_id' => 11,
                'text' => 'Older card',
                'question_type' => 'single',
                'pbq_subtype' => null,
                'pbq_config' => null,
                'next_review' => $now - 30,
                'stability' => 10.0,
                'last_reviewed' => $now - (30 * 86400),
                'box' => 4,
            ],
        ]));
        $answersBuilder = new FakeQueryBuilder(FakeResult::fromFetchAll([
            [
                'id' => 99,
                'question_id' => 10,
                'text' => 'A',
                'is_correct' => true,
                'position' => 1,
            ],
            [
                'id' => 100,
                'question_id' => 11,
                'text' => 'B',
                'is_correct' => true,
                'position' => 1,
            ],
        ]));

        $db = new FakeDbConnection([$itemsBuilder, $answersBuilder]);
        $poolMapper = $this->createMock(PoolMapper::class);
        $poolMapper->method('find')->with(42, 'alice')->willReturn(new \OCA\Learning\Db\Pool());

        $service = $this->createService($db, $poolMapper);

        $items = $service->getDueQuestions(42, 'alice', 10);

        $this->assertSame(11, $items[0]['question_id']);
        $this->assertGreaterThan($items[0]['retrievability'], $items[1]['retrievability']);
    }

    public function testGetDueQuestionsStripsIsCorrectFromAnswers(): void {
        $itemsBuilder = new FakeQueryBuilder(FakeResult::fromFetchAll([
            [
                'id' => 1,
                'question_id' => 10,
                'text' => 'Q',
                'question_type' => 'single',
                'pbq_subtype' => null,
                'pbq_config' => null,
            ],
        ]));
        $answersBuilder = new FakeQueryBuilder(FakeResult::fromFetchAll([
            ['id' => 99, 'question_id' => 10, 'text' => 'A', 'is_correct' => true, 'position' => 1],
            ['id' => 100, 'question_id' => 10, 'text' => 'B', 'is_correct' => false, 'position' => 2],
        ]));

        $db = new FakeDbConnection([$itemsBuilder, $answersBuilder]);
        $poolMapper = $this->createMock(PoolMapper::class);
        $poolMapper->method('find')->with(42, 'alice')->willReturn(new \OCA\Learning\Db\Pool());

        $service = $this->createService($db, $poolMapper);

        $items = $service->getDueQuestions(42, 'alice', 10);

        $this->assertNotEmpty($items[0]['answers']);
        foreach ($items[0]['answers'] as $answer) {
            $this->assertArrayNotHasKey('is_correct', $answer, 'is_correct must never leak to the client before submission');
        }
    }

    public function testGetDueQuestionsNormalizesLegacyMultipleLabelToMulti(): void {
        $itemsBuilder = new FakeQueryBuilder(FakeResult::fromFetchAll([
            [
                'id' => 1,
                'question_id' => 10,
                'text' => 'Pick all that apply',
                'question_type' => 'multiple',
                'pbq_subtype' => null,
                'pbq_config' => null,
            ],
        ]));
        $answersBuilder = new FakeQueryBuilder(FakeResult::fromFetchAll([
            ['id' => 99, 'question_id' => 10, 'text' => 'A', 'is_correct' => true, 'position' => 1],
            ['id' => 100, 'question_id' => 10, 'text' => 'B', 'is_correct' => true, 'position' => 2],
        ]));

        $db = new FakeDbConnection([$itemsBuilder, $answersBuilder]);
        $poolMapper = $this->createMock(PoolMapper::class);
        $poolMapper->method('find')->with(42, 'alice')->willReturn(new \OCA\Learning\Db\Pool());

        $service = $this->createService($db, $poolMapper);

        $items = $service->getDueQuestions(42, 'alice', 10);

        $this->assertSame('multi', $items[0]['question_type']);
    }

    public function testGetDueQuestionsUpgradesSingleWithMultiCorrectToMulti(): void {
        $itemsBuilder = new FakeQueryBuilder(FakeResult::fromFetchAll([
            [
                'id' => 1,
                'question_id' => 10,
                'text' => 'Which two apply?',
                'question_type' => 'single',
                'pbq_subtype' => null,
                'pbq_config' => null,
            ],
        ]));
        $answersBuilder = new FakeQueryBuilder(FakeResult::fromFetchAll([
            ['id' => 99, 'question_id' => 10, 'text' => 'A', 'is_correct' => true, 'position' => 1],
            ['id' => 100, 'question_id' => 10, 'text' => 'B', 'is_correct' => true, 'position' => 2],
            ['id' => 101, 'question_id' => 10, 'text' => 'C', 'is_correct' => false, 'position' => 3],
        ]));

        $db = new FakeDbConnection([$itemsBuilder, $answersBuilder]);
        $poolMapper = $this->createMock(PoolMapper::class);
        $poolMapper->method('find')->with(42, 'alice')->willReturn(new \OCA\Learning\Db\Pool());

        $service = $this->createService($db, $poolMapper);

        $items = $service->getDueQuestions(42, 'alice', 10);

        $this->assertSame('multi', $items[0]['question_type']);
    }

    /**
     * AUDIT v5.2.1 (pre-live review, finding D): while an active exam session is open on the pool,
     * getDueQuestions() must strip `explanation` and recursively strip PBQ solution keys
     * (correct/correct_position/correct_problem/correct_solution/expected/expected_value/answer_key)
     * from `pbq_config` — otherwise the spaced-repetition due-queue becomes an exam-answer oracle.
     * Non-solution keys (e.g. render config like `domain`, or a nested `keep`) must survive.
     */
    public function testGetDueQuestionsStripsExplanationAndPbqSolutionKeysDuringActiveExam(): void {
        $itemsBuilder = new FakeQueryBuilder(FakeResult::fromFetchAll([
            [
                'id' => 1,
                'question_id' => 10,
                'text' => 'Configure the switch',
                'question_type' => 'pbq',
                'pbq_subtype' => 'switch_config',
                'explanation' => 'Because VLAN 10 is correct',
                'pbq_config' => json_encode([
                    'domain' => 'switching',
                    'correct' => ['vlan' => 10],
                    'nested' => ['correct_solution' => 'do-this', 'keep' => 'safe'],
                ]),
            ],
        ]));
        $answersBuilder = new FakeQueryBuilder(FakeResult::fromFetchAll([
            ['id' => 99, 'question_id' => 10, 'text' => 'A', 'is_correct' => true, 'position' => 1],
        ]));
        // Third getQueryBuilder() call is hasActiveExamOnPool() — a found row means "exam active".
        $examBuilder = new FakeQueryBuilder(FakeResult::fromFetch(['id' => 1]));

        $db = new FakeDbConnection([$itemsBuilder, $answersBuilder, $examBuilder]);
        $poolMapper = $this->createMock(PoolMapper::class);
        $poolMapper->method('find')->with(42, 'alice')->willReturn(new \OCA\Learning\Db\Pool());

        $service = $this->createService($db, $poolMapper);

        $items = $service->getDueQuestions(42, 'alice', 10);

        $this->assertArrayNotHasKey('explanation', $items[0], 'explanation must be withheld during an active exam');
        $this->assertIsArray($items[0]['pbq_config']);
        $this->assertArrayNotHasKey('correct', $items[0]['pbq_config']);
        $this->assertArrayNotHasKey('correct_solution', $items[0]['pbq_config']['nested']);
        $this->assertSame('switching', $items[0]['pbq_config']['domain'], 'non-solution keys must survive');
        $this->assertSame('safe', $items[0]['pbq_config']['nested']['keep'], 'nested non-solution keys must survive');
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

        $lernprofilService = $this->createMock(\OCA\Learning\Service\LernprofilService::class);

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
            $courseService,
            $lernprofilService,
            new FsrsService()
        );
    }
}
