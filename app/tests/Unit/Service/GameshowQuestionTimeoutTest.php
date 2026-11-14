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
 * Sprint and elimination used to hang for good as soon as one player let the 15 s timer run out:
 * the client blocks answering at 0, and the server only moved on once every active player had
 * answered. Now a poll (GET state or the SSE stream, both via getState) or an answer resolves the
 * question once question_started_at + 15 s + 3 s grace has passed: whoever has not answered is
 * recorded as wrong, the question is scored and the game moves on. Only the request that wins
 * the compare-and-swap on the question index does that.
 */
#[AllowMockObjectsWithoutExpectations]
final class GameshowQuestionTimeoutTest extends TestCase {
    private const PAST_DEADLINE_MS = 19000;

    private GameshowSession $session;
    /** @var array<string, GameshowPlayer> */
    private array $players = [];
    /** @var GameshowAnswer[] */
    private array $answers = [];
    private int $dbIndex = 0;
    private int $claims = 0;
    private bool $claimWins = true;
    private int $claimedStartedAt = 0;
    private int $xpAwards = 0;
    private bool $missInsertCollides = false;
    private FakeDbConnection $db;
    private GameshowService $service;

    public function testSprintMovesOnAndEndsWhenOnePlayerNeverAnswers(): void {
        $this->setUpGame('sprint', 3);

        $this->session->setQuestionStartedAt($this->nowMs() - 1000);
        $state = $this->service->submitAnswer('abc123', 'alice', 501, 0);
        $this->assertSame(0, $state['current_question_index'], 'bob may still answer');

        $this->session->setQuestionStartedAt($this->nowMs() - 16000);
        $state = $this->service->getState('abc123', 'alice');
        $this->assertSame(0, $state['current_question_index'], 'within the 3 s grace nothing moves');
        $this->assertNull($this->answerOf('bob', 0));

        $this->session->setQuestionStartedAt($this->nowMs() - self::PAST_DEADLINE_MS);
        $state = $this->service->getState('abc123', 'alice');
        $this->assertSame('active', $state['status']);
        $this->assertSame(1, $state['current_question_index'], 'the poll after the deadline moves to the next question');
        $missed = $this->answerOf('bob', 0);
        $this->assertNotNull($missed, 'bob is recorded for the question he let run out');
        $this->assertFalse((bool)$missed->getIsCorrect());
        $this->assertNull($missed->getAnswerId());
        $this->assertSame(0, $missed->getPointsEarned());
        $this->assertGreaterThan(0, $this->players['alice']->getScore(), 'alice is scored for that question');
        $this->assertGreaterThan($this->nowMs() - 5000, (int)$state['question_started_at'], 'the next question gets a fresh start time');

        foreach ([1, 2] as $index) {
            $this->service->submitAnswer('abc123', 'alice', 501 + $index, 0);
            $this->session->setQuestionStartedAt($this->nowMs() - self::PAST_DEADLINE_MS);
            $state = $this->service->getState('abc123', 'bob');
        }

        $this->assertSame('finished', $state['status'], 'the game ends although bob never answered');
        $this->assertSame(2, $state['current_question_index'], 'a finished game keeps the last question index');
        $this->assertSame(3, $this->claims);
        $this->assertSame(2, $this->xpAwards, 'XP once per player, at the single finish');
        $this->assertSame(3, $this->db->beginTransactionCalls, 'each resolution is one transaction');
        $this->assertSame(3, $this->db->commitCalls);
        $this->assertSame(0, $this->db->rollBackCalls);
    }

    public function testEliminationTakesTheLifeOfThePlayerWhoNeverAnswers(): void {
        $this->setUpGame('elimination', 5);

        $this->service->submitAnswer('abc123', 'alice', 501, 0);
        $this->session->setQuestionStartedAt($this->nowMs() - self::PAST_DEADLINE_MS);
        $state = $this->service->getState('abc123', 'alice');

        $this->assertSame(0, $this->players['bob']->getLives(), 'a missed question costs a life like a wrong one');
        $this->assertSame('finished', $state['status']);
        $this->assertSame('alice', $state['winner_user_id']);
        $this->assertSame(2, $this->xpAwards);
    }

    public function testARequestThatLosesTheClaimDoesNotScoreOrFinish(): void {
        $this->setUpGame('elimination', 5);
        $this->service->submitAnswer('abc123', 'alice', 501, 0);
        $this->claimWins = false; // another poll moved the question on first

        $this->session->setQuestionStartedAt($this->nowMs() - self::PAST_DEADLINE_MS);
        $state = $this->service->getState('abc123', 'alice');

        $this->assertSame(1, $this->claims);
        $this->assertSame(3, $this->players['bob']->getLives(), 'only the winner of the claim takes lives');
        $this->assertNull($this->answerOf('bob', 0), 'only the winner of the claim records missed answers');
        $this->assertSame('active', $state['status']);
        $this->assertSame(0, $this->xpAwards);
    }

    public function testARealAnswerRacingTheMissRowRollsTheResolutionBack(): void {
        $this->setUpGame('elimination', 5);
        $this->service->submitAnswer('abc123', 'alice', 501, 0);
        $this->missInsertCollides = true; // bob's own answer hit the unique index first

        $this->session->setQuestionStartedAt($this->nowMs() - self::PAST_DEADLINE_MS);
        $this->service->getState('abc123', 'alice');

        $this->assertSame(1, $this->db->rollBackCalls, 'claim and partial work are undone together');
        $this->assertSame(0, $this->db->commitCalls);
        $this->assertSame(3, $this->players['bob']->getLives(), 'no life is taken by a rolled-back resolution');
        $this->assertSame(0, $this->xpAwards);
    }

    public function testAnAnswerAfterTheDeadlineCountsAsWrongAndTheGameMovesOn(): void {
        $this->setUpGame('sprint', 3);
        $this->service->submitAnswer('abc123', 'alice', 501, 0);
        $this->session->setQuestionStartedAt($this->nowMs() - self::PAST_DEADLINE_MS);

        $state = $this->service->submitAnswer('abc123', 'bob', 501, 0);

        $this->assertFalse($state['my_answer_correct'], 'too late is answered like a wrong answer');
        $this->assertArrayHasKey('correct_answer_id', $state);
        $this->assertSame(1, $state['current_question_index']);
        $bob = $this->answerOf('bob', 0);
        $this->assertNotNull($bob);
        $this->assertNull($bob->getAnswerId(), 'the late answer is not stored, the miss is');
        $this->assertNull($this->answerOf('bob', 1), 'the late answer is not filed under the next question either');
        $this->assertSame(0, $this->players['bob']->getScore());
    }

    public function testWhenEveryoneAnswersTheQuestionStillMovesOnAtOnce(): void {
        $this->setUpGame('sprint', 3);
        $this->session->setQuestionStartedAt($this->nowMs() - 1000);

        $this->service->submitAnswer('abc123', 'alice', 501, 0);
        $state = $this->service->submitAnswer('abc123', 'bob', 501, 0);

        $this->assertSame(1, $state['current_question_index']);
        $this->assertSame(1, $this->claims);
        $this->assertCount(2, $this->answers, 'no extra miss rows when everyone answered');
    }

    /**
     * Audit #160: every client plays a 5.55 s reveal before it shows the next question. The next
     * question's clock starts after it, not at the moment of resolution (which left ~9.5 s).
     */
    public function testTheNextQuestionStartsAfterTheReveal(): void {
        $this->setUpGame('sprint', 3);
        $this->session->setQuestionStartedAt($this->nowMs() - 1000);

        $before = $this->nowMs();
        $this->service->submitAnswer('abc123', 'alice', 501, 0);
        $state = $this->service->submitAnswer('abc123', 'bob', 501, 0);
        $after = $this->nowMs();

        $this->assertSame(1, $state['current_question_index']);
        $this->assertGreaterThanOrEqual($before + GameshowService::REVEAL_MS, (int)$state['question_started_at']);
        $this->assertLessThanOrEqual($after + GameshowService::REVEAL_MS, (int)$state['question_started_at']);
        $this->assertSame((int)$state['question_started_at'], $this->claimedStartedAt, 'the claim writes the same start');
        $this->assertGreaterThanOrEqual($before, (int)$state['server_now_ms'], 'the client gets the server clock');
        $this->assertLessThanOrEqual($after, (int)$state['server_now_ms']);
    }

    public function testAnAnswerBeforeTheQuestionStartsCountsAsGivenAtTheStart(): void {
        $this->setUpGame('sprint', 3);
        $start = $this->nowMs() + 4000;
        $this->session->setQuestionStartedAt($start);

        $this->service->submitAnswer('abc123', 'alice', 501, 0);

        $this->assertSame($start, $this->answerOf('alice', 0)?->getAnsweredAt(), 'no time bonus from answering during the reveal');
    }

    /** Audit #227: the board-game modes are gone; a leftover session cannot be played. */
    public function testALeftoverBoardGameSessionIsRefused(): void {
        $this->setUpGame('lernwuerfel', 3);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('This game mode is no longer supported');
        $this->service->submitAnswer('abc123', 'alice', 501, 0);
    }

    /** Audit #159: elimination never scores, so the history names the last player standing. */
    public function testEliminationHistoryNamesTheLastPlayerStanding(): void {
        $this->setUpGame('elimination', 5);
        $this->session->setStatus('finished');
        $this->players['bob']->setLives(0);

        $history = $this->service->getHistory('alice');

        $this->assertSame('alice', $history[0]['winner']);
    }

    public function testSprintHistoryStillNamesTheHighestScore(): void {
        $this->setUpGame('sprint', 3);
        $this->session->setStatus('finished');
        $this->players['bob']->setScore(700);
        $this->players['alice']->setScore(300);

        $this->assertSame('bob', $this->service->getHistory('alice')[0]['winner']);
    }

    private function setUpGame(string $mode, int $numQuestions): void {
        $session = new GameshowSession();
        $session->setId(8);
        $session->setCode('abc123');
        $session->setMode($mode);
        $session->setStatus('active');
        $session->setMinPlayers(2);
        $session->setCurrentQuestionIndex(0);
        $session->setNumQuestions($numQuestions);
        $session->setQuestionIds(json_encode(range(10, 10 + $numQuestions - 1)));
        $session->setQuestionStartedAt($this->nowMs());
        $this->session = $session;

        foreach (['alice' => 0, 'bob' => 1] as $uid => $slot) {
            $player = new GameshowPlayer();
            $player->setSessionId(8);
            $player->setUserId($uid);
            $player->setDisplayName($uid);
            $player->setSlot($slot);
            $player->setLives(3);
            $player->setScore(0);
            $player->setIsReady(true);
            $player->setIsRemoved(false);
            $player->setLastPoll(time());
            $this->players[$uid] = $player;
        }

        $sessionMapper = $this->createMock(GameshowSessionMapper::class);
        $sessionMapper->method('findByCode')->willReturnCallback(fn (): GameshowSession => $this->session);
        $sessionMapper->method('findFinishedByUser')->willReturnCallback(fn (): array => [$this->session]);
        // A fresh read carries what the claim wrote, like the database row would.
        $sessionMapper->method('findById')->willReturnCallback(function (): GameshowSession {
            $stored = clone $this->session;
            if ($stored->getCurrentQuestionIndex() !== $this->dbIndex) {
                $stored->setCurrentQuestionIndex($this->dbIndex);
                $stored->setQuestionStartedAt($this->claimedStartedAt);
            }
            return $stored;
        });
        $sessionMapper->method('update')->willReturnCallback(function (GameshowSession $s): GameshowSession {
            $this->dbIndex = (int)$s->getCurrentQuestionIndex();
            $this->session = $s;
            return $s;
        });
        $sessionMapper->method('claimAdvance')->willReturnCallback(function (int $id, int $from, int $startedAt): bool {
            $this->claims++;
            if (!$this->claimWins || $this->dbIndex !== $from || $this->session->getStatus() !== 'active') {
                return false;
            }
            $this->dbIndex = $from + 1;
            $this->claimedStartedAt = $startedAt;
            return true;
        });

        $playerMapper = $this->createMock(GameshowPlayerMapper::class);
        $playerMapper->method('findBySession')->willReturnCallback(fn (): array => array_values($this->players));
        $playerMapper->method('findBySessionAndUser')->willReturnCallback(fn (int $sid, string $uid): GameshowPlayer => $this->players[$uid]);
        $playerMapper->method('update')->willReturnArgument(0);

        $answerMapper = $this->createMock(GameshowAnswerMapper::class);
        $answerMapper->method('insert')->willReturnCallback(function (GameshowAnswer $a): GameshowAnswer {
            if ($this->missInsertCollides && $a->getAnswerId() === null) {
                throw new \OCP\DB\Exception(\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION, 'duplicate');
            }
            foreach ($this->answers as $existing) {
                if ($existing->getQuestionIndex() === $a->getQuestionIndex() && $existing->getPlayerUid() === $a->getPlayerUid()) {
                    throw new \LogicException('unique index lgsa_sess_q_player_uidx violated');
                }
            }
            $this->answers[] = $a;
            return $a;
        });
        $answerMapper->method('update')->willReturnArgument(0);
        $answerMapper->method('findBySessionAndQuestion')->willReturnCallback(
            fn (int $sid, int $qi): array => array_values(array_filter($this->answers, fn (GameshowAnswer $a): bool => $a->getQuestionIndex() === $qi))
        );
        $answerMapper->method('findBySession')->willReturnCallback(fn (): array => $this->answers);

        $translation = $this->createMock(TranslationService::class);
        $translation->method('normalizeLang')->willReturn(null);

        $streak = $this->createMock(StreakService::class);
        $streak->method('getStreak')->willReturn(['current_streak' => 0]);
        $xp = $this->createMock(XpService::class);
        $xp->method('calculateSessionXp')->willReturn(10);
        $xp->method('incrementSessionXp')->willReturnCallback(function (): void {
            $this->xpAwards++;
        });

        $builders = [];
        for ($i = 0; $i < 20; $i++) {
            $builders[] = new FakeQueryBuilder(FakeResult::fromFetch(['is_correct' => 1]));
        }

        $this->db = new FakeDbConnection($builders);
        $this->service = new GameshowService(
            $sessionMapper,
            $playerMapper,
            $answerMapper,
            $this->db,
            $this->createMock(LoggerInterface::class),
            $translation,
            $this->createMock(IConfig::class),
            $this->createMock(CourseService::class),
            $this->createMock(IUserManager::class),
            $xp,
            $streak,
            $this->createMock(QuestionService::class)
        );
    }

    private function answerOf(string $uid, int $questionIndex): ?GameshowAnswer {
        foreach ($this->answers as $answer) {
            if ($answer->getPlayerUid() === $uid && $answer->getQuestionIndex() === $questionIndex) {
                return $answer;
            }
        }
        return null;
    }

    private function nowMs(): int {
        return intval(microtime(true) * 1000);
    }
}
