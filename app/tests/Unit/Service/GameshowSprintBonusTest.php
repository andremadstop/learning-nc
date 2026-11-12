<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\GameshowAnswer;
use OCA\Learning\Db\GameshowAnswerMapper;
use OCA\Learning\Db\GameshowPlayer;
use OCA\Learning\Db\GameshowPlayerMapper;
use OCA\Learning\Db\GameshowSession;
use OCA\Learning\Db\GameshowSessionMapper;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\GameshowService;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\TranslationService;
use OCA\Learning\Service\XpService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\IConfig;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The sprint time bonus is at most 500 and measured on the server clock. questionStartedAt is
 * server time; a client timestamp from a clock that runs behind (or a forged one) used to give
 * a negative "elapsed" and a bonus of any size.
 */
#[AllowMockObjectsWithoutExpectations]
final class GameshowSprintBonusTest extends TestCase {
    public function testClientClockBehindServerDoesNotInflateBonus(): void {
        $startedAt = intval(microtime(true) * 1000) - 2000;
        $answer = $this->answerSprint($startedAt, $startedAt - 60000);

        $this->assertLessThanOrEqual(1000, $answer->getPointsEarned(), '500 base + at most 500 bonus');
        $this->assertGreaterThanOrEqual(500, $answer->getPointsEarned());
        $this->assertGreaterThanOrEqual($startedAt, $answer->getAnsweredAt(), 'answer time is the server receipt time');
    }

    public function testClientClockAheadDoesNotCostTheBonus(): void {
        $startedAt = intval(microtime(true) * 1000) - 1000;
        $answer = $this->answerSprint($startedAt, $startedAt + 3600000);

        $this->assertGreaterThan(500, $answer->getPointsEarned(), 'fast answer keeps a time bonus');
    }

    private function answerSprint(int $questionStartedAt, int $clientAnsweredAt): GameshowAnswer {
        $session = new GameshowSession();
        $session->setId(8);
        $session->setCode('abc123');
        $session->setMode('sprint');
        $session->setStatus('active');
        $session->setCurrentQuestionIndex(0);
        $session->setNumQuestions(3);
        $session->setQuestionIds(json_encode([10, 11, 12]));
        $session->setQuestionStartedAt($questionStartedAt);

        $sessionMapper = $this->createMock(GameshowSessionMapper::class);
        $sessionMapper->method('findByCode')->willReturn($session);
        $sessionMapper->method('update')->willThrowException(new \LogicException('stop after scoring'));

        $player = new GameshowPlayer();
        $player->setSessionId(8);
        $player->setUserId('alice');
        $player->setSlot(0);
        $player->setLives(3);
        $player->setIsRemoved(false);
        $playerMapper = $this->createMock(GameshowPlayerMapper::class);
        $playerMapper->method('findBySessionAndUser')->willReturn($player);
        $playerMapper->method('findBySession')->willReturn([$player]);

        $stored = [];
        $answerMapper = $this->createMock(GameshowAnswerMapper::class);
        $answerMapper->method('insert')->willReturnCallback(function (GameshowAnswer $a) use (&$stored): GameshowAnswer {
            $stored[] = $a;
            return $a;
        });
        $answerMapper->method('findBySessionAndQuestion')->willReturnCallback(function () use (&$stored): array {
            return $stored;
        });
        $answerMapper->method('findBySession')->willReturnCallback(function () use (&$stored): array {
            return $stored;
        });

        $translation = $this->createMock(TranslationService::class);
        $translation->method('normalizeLang')->willReturn(null);

        $service = new GameshowService(
            $sessionMapper,
            $playerMapper,
            $answerMapper,
            new FakeDbConnection([new FakeQueryBuilder(FakeResult::fromFetch(['is_correct' => 1]))]),
            $this->createMock(LoggerInterface::class),
            $translation,
            $this->createMock(IConfig::class),
            $this->createMock(CourseService::class),
            $this->createMock(IUserManager::class),
            $this->createMock(XpService::class),
            $this->createMock(StreakService::class),
            $this->createMock(QuestionService::class)
        );

        try {
            $service->submitAnswer('abc123', 'alice', 501, $clientAnsweredAt);
            $this->fail('expected the session update to stop the flow');
        } catch (\LogicException $e) {
            $this->assertSame('stop after scoring', $e->getMessage());
        }

        $this->assertCount(1, $stored);
        return $stored[0];
    }
}
