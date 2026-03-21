<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Answer;
use OCA\Learning\Db\AnswerMapper;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\PoolShareMapper;
use OCA\Learning\Db\Question;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\TrainingService;
use OCA\Learning\Service\TranslationService;
use OCA\Learning\Service\XpService;
use OCA\Learning\Tests\Support\FakeCacheFactory;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TrainingServiceTest extends TestCase {
    public function testStartSessionUsesRequestedPoolAndPersistsQuestionOrder(): void {
        $activeExamBuilder = new FakeQueryBuilder(FakeResult::fromFetch(false));
        $insertBuilder = new FakeQueryBuilder(new FakeResult(), 0, 99);
        $db = new FakeDbConnection([$activeExamBuilder, $insertBuilder]);

        $questionMapper = $this->createMock(QuestionMapper::class);
        $answerMapper = $this->createMock(AnswerMapper::class);
        $poolMapper = $this->createMock(PoolMapper::class);

        $questions = [
            $this->makeQuestion(10, 42, 'First'),
            $this->makeQuestion(11, 42, 'Second'),
        ];

        $questionMapper->expects($this->exactly(2))
            ->method('findByPoolId')
            ->with(42)
            ->willReturn($questions);
        $answerMapper->method('findByQuestion')
            ->willReturnCallback(fn(int $questionId): array => [$this->makeAnswer($questionId * 10, $questionId, 'Answer')]);
        $poolMapper->method('find')->with(42, 'alice')->willReturn(new \OCA\Learning\Db\Pool());

        $service = $this->createService(
            db: $db,
            questionMapper: $questionMapper,
            answerMapper: $answerMapper,
            poolMapper: $poolMapper
        );

        $payload = $service->startSession(42, 'alice', null, 'training');

        $this->assertSame(99, $payload['session_id']);
        $this->assertSame(2, $payload['total_questions']);
        $this->assertFalse($payload['resumed']);
        $this->assertEqualsCanonicalizing([10, 11], array_column($payload['questions'], 'id'));
        $this->assertSame(42, $insertBuilder->insertValues['pool_id']['value']);
        $this->assertSame('training', $insertBuilder->insertValues['mode']['value']);
        $this->assertSame(2, $insertBuilder->insertValues['total_questions']['value']);
    }

    public function testCompleteSessionAwardsXpUpdatesCacheAndMergesBadges(): void {
        $db = new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetch([
                'id' => 9,
                'pool_id' => 42,
                'user_id' => 'alice',
                'started_at' => 100,
                'total_questions' => 4,
                'correct_answers' => 3,
                'mode' => 'training',
                'completed_at' => null,
            ])),
            new FakeQueryBuilder(new FakeResult(), 1),
            new FakeQueryBuilder(FakeResult::fromFetch([
                'id' => 9,
                'pool_id' => 42,
                'user_id' => 'alice',
                'started_at' => 100,
                'total_questions' => 4,
                'correct_answers' => 3,
                'mode' => 'training',
                'completed_at' => 200,
            ])),
            new FakeQueryBuilder(FakeResult::fromFetch(['avg_pct' => 50])),
        ]);

        $badgeService = $this->createMock(BadgeService::class);
        $streakService = $this->createMock(StreakService::class);
        $xpService = $this->createMock(XpService::class);
        $cacheFactory = new FakeCacheFactory();

        $badgeService->expects($this->exactly(2))
            ->method('checkAndAward')
            ->willReturnOnConsecutiveCalls(
                [['badge_id' => 'session']],
                [['badge_id' => 'streak']]
            );
        $streakService->expects($this->once())
            ->method('getStreak')
            ->with('alice', true)
            ->willReturn(['current_streak' => 3]);
        $xpService->expects($this->exactly(2))
            ->method('calculateXp')
            ->with('alice')
            ->willReturnOnConsecutiveCalls(['level' => 1], ['level' => 2]);
        $xpService->expects($this->once())
            ->method('calculateSessionXp')
            ->with(
                [
                    'mode' => 'training',
                    'total_questions' => 4,
                    'correct_answers' => 3,
                    'completed_at' => 200,
                    'started_at' => 100,
                ],
                3
            )
            ->willReturn(35);
        $xpService->expects($this->once())
            ->method('incrementSessionXp')
            ->with('alice', 35, 3);

        $service = $this->createService(
            db: $db,
            badgeService: $badgeService,
            streakService: $streakService,
            xpService: $xpService,
            cacheFactory: $cacheFactory
        );

        $result = $service->completeSession(9, 'alice');

        $this->assertSame(75.0, $result['score_percentage']);
        $this->assertSame(35, $result['xp_earned']);
        $this->assertSame(1, $result['level_before']);
        $this->assertSame(2, $result['level_after']);
        $this->assertSame(50.0, $result['average_accuracy']);
        $this->assertTrue($result['is_personal_best']);
        $this->assertSame(25.0, $result['improvement']);
        $this->assertSame(
            [['badge_id' => 'session'], ['badge_id' => 'streak']],
            $result['newly_earned_badges']
        );
        $this->assertSame(['user_state_alice'], $cacheFactory->cache->removedKeys);
    }

    private function createService(
        FakeDbConnection $db,
        ?QuestionMapper $questionMapper = null,
        ?AnswerMapper $answerMapper = null,
        ?PoolMapper $poolMapper = null,
        ?BadgeService $badgeService = null,
        ?StreakService $streakService = null,
        ?XpService $xpService = null,
        ?FakeCacheFactory $cacheFactory = null
    ): TrainingService {
        $questionMapper ??= $this->createMock(QuestionMapper::class);
        $answerMapper ??= $this->createMock(AnswerMapper::class);
        $poolMapper ??= $this->createMock(PoolMapper::class);
        $shareMapper = $this->createMock(PoolShareMapper::class);
        $badgeService ??= $this->createMock(BadgeService::class);
        $streakService ??= $this->createMock(StreakService::class);
        $xpService ??= $this->createMock(XpService::class);
        $cacheFactory ??= new FakeCacheFactory();
        $translationService = $this->createMock(TranslationService::class);
        $config = $this->createMock(IConfig::class);
        $logger = $this->createMock(LoggerInterface::class);
        $courseService = $this->createMock(CourseService::class);

        $translationService->method('normalizeLang')
            ->willReturnCallback(static fn(?string $lang): ?string => $lang === '' ? null : $lang);
        $translationService->method('translateQuestions')
            ->willReturnCallback(static fn(array $questions): array => $questions);
        $config->method('getUserValue')->willReturn('');
        // logger->info() is void — no willReturn needed, mock accepts any call by default

        return new TrainingService(
            $db,
            $questionMapper,
            $answerMapper,
            $poolMapper,
            $shareMapper,
            $badgeService,
            $streakService,
            $xpService,
            $cacheFactory,
            $translationService,
            $config,
            $logger,
            $courseService
        );
    }

    private function makeQuestion(int $id, int $poolId, string $text): Question {
        $question = new Question();
        $question->setId($id);
        $question->setPoolId($poolId);
        $question->setUserId('alice');
        $question->setText($text);
        $question->setExplanation('');
        $question->setDifficulty('easy');
        $question->setQuestionType('single');
        return $question;
    }

    private function makeAnswer(int $id, int $questionId, string $text): Answer {
        $answer = new Answer();
        $answer->setId($id);
        $answer->setQuestionId($questionId);
        $answer->setText($text);
        $answer->setIsCorrect(true);
        $answer->setPosition(1);
        return $answer;
    }
}
