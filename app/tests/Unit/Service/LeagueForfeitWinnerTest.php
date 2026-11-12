<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\DuelAnswerMapper;
use OCA\Learning\Db\DuelInviteMapper;
use OCA\Learning\Db\DuelSession;
use OCA\Learning\Db\DuelSessionMapper;
use OCA\Learning\Db\LeagueChallenge;
use OCA\Learning\Db\LeagueChallengeMapper;
use OCA\Learning\Db\LeagueResult;
use OCA\Learning\Db\LeagueResultMapper;
use OCA\Learning\Db\LeagueSeason;
use OCA\Learning\Db\LeagueSeasonMapper;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\DuelService;
use OCA\Learning\Service\LeagueService;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\TranslationService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCP\IConfig;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A league duel that ends because one player stopped polling is won by the player who stayed,
 * not automatically by the challenger. Only a challenge that was never played (deadline,
 * season end) keeps the old fallback "forfeit = challenger wins".
 */
#[AllowMockObjectsWithoutExpectations]
final class LeagueForfeitWinnerTest extends TestCase {
    private ?LeagueResult $inserted = null;

    public function testOpponentWhoStayedWinsLeagueDuel(): void {
        $this->leagueService('league')->recordExpiredDuel($this->duel(), 'bob');

        $this->assertNotNull($this->inserted);
        $this->assertSame('bob', $this->inserted->getWinnerUid());
        $this->assertSame(0, (int)$this->inserted->getLeaguePointsA());
        $this->assertSame(3, (int)$this->inserted->getLeaguePointsB());
    }

    public function testChallengerWhoStayedWinsLeagueDuel(): void {
        $this->leagueService('league')->recordExpiredDuel($this->duel(), 'alice');

        $this->assertSame('alice', $this->inserted->getWinnerUid());
        $this->assertSame(3, (int)$this->inserted->getLeaguePointsA());
        $this->assertSame(0, (int)$this->inserted->getLeaguePointsB());
    }

    public function testOpponentWhoStayedWinsChampionsLeagueDuel(): void {
        $this->leagueService('cl')->recordExpiredDuel($this->duel(), 'bob');

        $this->assertSame('bob', $this->inserted->getWinnerUid());
    }

    public function testDuelReportsThePlayerWhoStayedAsForfeitWinner(): void {
        $session = $this->duel();
        $session->setStatus('active');
        $session->setCreatorLastPoll(time() - 3600);
        $session->setOpponentLastPoll(time());

        $sessionMapper = $this->createMock(DuelSessionMapper::class);
        $sessionMapper->method('findByCode')->willReturn($session);
        $sessionMapper->method('update')->willThrowException(new \LogicException('stop after update'));

        $league = $this->createMock(LeagueService::class);
        $league->expects($this->once())->method('recordExpiredDuel')->with($session, 'bob');

        $translation = $this->createMock(TranslationService::class);
        $translation->method('normalizeLang')->willReturn(null);

        $duelService = new DuelService(
            $sessionMapper,
            $this->createMock(DuelAnswerMapper::class),
            $this->createMock(DuelInviteMapper::class),
            new FakeDbConnection(),
            $this->createMock(LoggerInterface::class),
            $league,
            $translation,
            $this->createMock(IConfig::class),
            $this->createMock(CourseService::class),
            $this->createMock(QuestionService::class)
        );

        $this->expectException(\LogicException::class);
        $duelService->getState('abc123', 'bob');
    }

    private function duel(): DuelSession {
        $session = new DuelSession();
        $session->setId(7);
        $session->setCode('abc123');
        $session->setCreatorUid('alice');
        $session->setOpponentUid('bob');
        $session->setStatus('expired');
        $session->setCreatorScore(40);
        $session->setOpponentScore(130);
        $session->setLeagueSeasonId(3);
        $session->setLeagueChallengeId(11);
        return $session;
    }

    private function leagueService(string $stage): LeagueService {
        $season = new LeagueSeason();
        $season->setId(3);

        $challenge = new LeagueChallenge();
        $challenge->setId(11);
        $challenge->setSeasonId(3);
        $challenge->setStage($stage);
        $challenge->setRoundNumber(2);
        $challenge->setChallengerUid('alice');
        $challenge->setOpponentUid('bob');
        $challenge->setDuelSessionId(7);
        $challenge->setStatus('accepted');

        $seasonMapper = $this->createMock(LeagueSeasonMapper::class);
        $seasonMapper->method('findById')->willReturn($season);
        $challengeMapper = $this->createMock(LeagueChallengeMapper::class);
        $challengeMapper->method('findById')->willReturn($challenge);
        $resultMapper = $this->createMock(LeagueResultMapper::class);
        $resultMapper->method('findBySeason')->willReturn([]);
        $resultMapper->method('insert')->willReturnCallback(function (LeagueResult $result): LeagueResult {
            $this->inserted = $result;
            return $result;
        });
        $duelMapper = $this->createMock(DuelSessionMapper::class);
        $duelMapper->method('findById')->willReturn($this->duel());

        return new LeagueService(
            $seasonMapper,
            $challengeMapper,
            $resultMapper,
            $this->createMock(CourseService::class),
            $this->createMock(CourseMapper::class),
            $this->createMock(CourseMemberMapper::class),
            $duelMapper,
            new FakeDbConnection(),
            $this->createMock(IUserManager::class)
        );
    }
}
