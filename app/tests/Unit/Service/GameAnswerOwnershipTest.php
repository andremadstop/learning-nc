<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\DuelAnswerMapper;
use OCA\Learning\Db\DuelInviteMapper;
use OCA\Learning\Db\DuelSession;
use OCA\Learning\Db\DuelSessionMapper;
use OCA\Learning\Db\GameshowAnswerMapper;
use OCA\Learning\Db\GameshowPlayer;
use OCA\Learning\Db\GameshowPlayerMapper;
use OCA\Learning\Db\GameshowSession;
use OCA\Learning\Db\GameshowSessionMapper;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\DuelService;
use OCA\Learning\Service\GameshowService;
use OCA\Learning\Service\LeagueService;
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
 * Duel and Gameshow grade an answer by its learning_answers row. The row must belong to the
 * question currently being played; otherwise a player can post the id of any correct answer
 * they know (e.g. from their own pool) and every question counts as correct.
 */
#[AllowMockObjectsWithoutExpectations]
final class GameAnswerOwnershipTest extends TestCase {
    private const CURRENT_QUESTION = 10;
    private const OWN_CORRECT_ANSWER = 501;
    private const FOREIGN_CORRECT_ANSWER = 900;

    public function testDuelRejectsCorrectAnswerOfAnotherQuestion(): void {
        $answerMapper = $this->createMock(DuelAnswerMapper::class);
        $answerMapper->method('findByDuelAndQuestion')->willReturn([]);
        $answerMapper->expects($this->never())->method('insert');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid answer');
        $this->duelService($answerMapper)->submitAnswer('abc123', 'alice', self::FOREIGN_CORRECT_ANSWER, 1000);
    }

    public function testDuelAcceptsAnswerOfCurrentQuestion(): void {
        $answerMapper = $this->createMock(DuelAnswerMapper::class);
        $answerMapper->method('findByDuelAndQuestion')->willReturn([]);
        $answerMapper->expects($this->once())->method('insert')->willReturnCallback(function ($answer) {
            $this->assertTrue((bool)$answer->getAnswerCorrect());
            throw new \LogicException('stop after insert');
        });

        $this->expectException(\LogicException::class);
        $this->duelService($answerMapper)->submitAnswer('abc123', 'alice', self::OWN_CORRECT_ANSWER, 1000);
    }

    public function testGameshowRejectsCorrectAnswerOfAnotherQuestion(): void {
        $answerMapper = $this->createMock(GameshowAnswerMapper::class);
        $answerMapper->method('findBySessionAndQuestion')->willReturn([]);
        $answerMapper->expects($this->never())->method('insert');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid answer');
        $this->gameshowService($answerMapper)->submitAnswer('abc123', 'alice', self::FOREIGN_CORRECT_ANSWER, 1000);
    }

    public function testGameshowAcceptsAnswerOfCurrentQuestion(): void {
        $answerMapper = $this->createMock(GameshowAnswerMapper::class);
        $answerMapper->method('findBySessionAndQuestion')->willReturn([]);
        $answerMapper->expects($this->once())->method('insert')->willReturnCallback(function ($answer) {
            $this->assertTrue((bool)$answer->getIsCorrect());
            throw new \LogicException('stop after insert');
        });

        $this->expectException(\LogicException::class);
        $this->gameshowService($answerMapper)->submitAnswer('abc123', 'alice', self::OWN_CORRECT_ANSWER, 1000);
    }

    /** learning_answers as the database would answer it: only rows matching every eq condition. */
    private function answersTable(): FakeDbConnection {
        $rows = [
            ['id' => self::OWN_CORRECT_ANSWER, 'question_id' => self::CURRENT_QUESTION, 'is_correct' => 1],
            ['id' => 502, 'question_id' => self::CURRENT_QUESTION, 'is_correct' => 0],
            ['id' => self::FOREIGN_CORRECT_ANSWER, 'question_id' => 99, 'is_correct' => 1],
        ];
        $builder = new class($rows) extends FakeQueryBuilder {
            public function __construct(private array $rows) {
                parent::__construct();
            }

            public function executeQuery(): FakeResult {
                $conditions = array_merge($this->whereCalls, $this->andWhereCalls);
                foreach ($this->rows as $row) {
                    $match = true;
                    foreach ($conditions as $condition) {
                        $column = preg_replace('/^\w+\./', '', (string)$condition['left']);
                        if ($condition['type'] !== 'eq' || !array_key_exists($column, $row)
                            || (int)$row[$column] !== (int)$condition['right']['value']) {
                            $match = false;
                            break;
                        }
                    }
                    if ($match) {
                        return FakeResult::fromFetch(['is_correct' => $row['is_correct']]);
                    }
                }
                return FakeResult::fromFetch(false);
            }
        };
        return new FakeDbConnection([$builder]);
    }

    private function translationService(): TranslationService {
        $translation = $this->createMock(TranslationService::class);
        $translation->method('normalizeLang')->willReturn(null);
        return $translation;
    }

    private function duelService(DuelAnswerMapper $answerMapper): DuelService {
        $session = new DuelSession();
        $session->setId(7);
        $session->setCode('abc123');
        $session->setCreatorUid('alice');
        $session->setOpponentUid('bob');
        $session->setStatus('active');
        $session->setCurrentQuestionIndex(0);
        $session->setQuestionIds(json_encode([self::CURRENT_QUESTION, 11, 12]));

        $sessionMapper = $this->createMock(DuelSessionMapper::class);
        $sessionMapper->method('findByCode')->willReturn($session);

        return new DuelService(
            $sessionMapper,
            $answerMapper,
            $this->createMock(DuelInviteMapper::class),
            $this->answersTable(),
            $this->createMock(LoggerInterface::class),
            $this->createMock(LeagueService::class),
            $this->translationService(),
            $this->createMock(IConfig::class),
            $this->createMock(CourseService::class),
            $this->createMock(QuestionService::class)
        );
    }

    private function gameshowService(GameshowAnswerMapper $answerMapper): GameshowService {
        $session = new GameshowSession();
        $session->setId(8);
        $session->setCode('abc123');
        $session->setMode('sprint');
        $session->setStatus('active');
        $session->setCurrentQuestionIndex(0);
        $session->setQuestionIds(json_encode([self::CURRENT_QUESTION, 11, 12]));

        $sessionMapper = $this->createMock(GameshowSessionMapper::class);
        $sessionMapper->method('findByCode')->willReturn($session);

        $player = new GameshowPlayer();
        $player->setSessionId(8);
        $player->setUserId('alice');
        $player->setSlot(0);
        $player->setLives(3);
        $player->setIsRemoved(false);
        $playerMapper = $this->createMock(GameshowPlayerMapper::class);
        $playerMapper->method('findBySessionAndUser')->willReturn($player);

        return new GameshowService(
            $sessionMapper,
            $playerMapper,
            $answerMapper,
            $this->answersTable(),
            $this->createMock(LoggerInterface::class),
            $this->translationService(),
            $this->createMock(IConfig::class),
            $this->createMock(CourseService::class),
            $this->createMock(IUserManager::class),
            $this->createMock(XpService::class),
            $this->createMock(StreakService::class),
            $this->createMock(QuestionService::class)
        );
    }
}
