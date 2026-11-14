<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\DuelAnswer;
use OCA\Learning\Db\DuelAnswerMapper;
use OCA\Learning\Db\DuelInviteMapper;
use OCA\Learning\Db\DuelSession;
use OCA\Learning\Db\DuelSessionMapper;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\DuelService;
use OCA\Learning\Service\LeagueService;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\TranslationService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
final class DuelServerTimeAndEmptyPoolTest extends TestCase {

    /**
     * Audit #22/#73: the speed bonus compares both players' answered_at. A client value
     * (answeredAt = 1, or a device clock that runs behind) must not be stored — the server
     * receipt time is.
     */
    public function testAnsweredAtIsTheServerReceiptTimeNotTheClientValue(): void {
        $session = $this->session('active');
        $sessionMapper = $this->createMock(DuelSessionMapper::class);
        $sessionMapper->method('findByCode')->willReturn($session);

        $stored = null;
        $answerMapper = $this->createMock(DuelAnswerMapper::class);
        $answerMapper->method('findByDuelAndQuestion')->willReturn([]);
        $answerMapper->expects($this->once())->method('insert')->willReturnCallback(function (DuelAnswer $a) use (&$stored) {
            $stored = $a->getAnsweredAt();
            throw new \LogicException('stop after insert');
        });

        $db = new FakeDbConnection([new FakeQueryBuilder(FakeResult::fromFetch(['is_correct' => 1]))]);
        $before = intval(microtime(true) * 1000);
        try {
            $this->service($sessionMapper, $answerMapper, $db)->submitAnswer('abc123', 'alice', 501, 1);
            $this->fail('insert was not reached');
        } catch (\LogicException $e) {
            // expected
        }
        $after = intval(microtime(true) * 1000);

        $this->assertNotNull($stored);
        $this->assertGreaterThanOrEqual($before, $stored);
        $this->assertLessThanOrEqual($after, $stored);
    }

    /**
     * Audit #74: a rematch on a pool without playable questions (e.g. PBQ only) must be
     * refused instead of creating an active duel with no current question.
     */
    public function testRematchOnPoolWithoutPlayableQuestionsIsRefused(): void {
        $session = $this->session('finished');
        $sessionMapper = $this->createMock(DuelSessionMapper::class);
        $sessionMapper->method('findByCode')->willReturn($session);
        $sessionMapper->expects($this->never())->method('insert');

        $db = new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetch(false)), // findPendingRematch: none
            new FakeQueryBuilder(new FakeResult([])),            // selectQuestions: no rows
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No questions available in this pool');
        $this->service($sessionMapper, $this->createMock(DuelAnswerMapper::class), $db)->rematch('abc123', 'alice');
    }

    private function session(string $status): DuelSession {
        $session = new DuelSession();
        $session->setId(7);
        $session->setCode('abc123');
        $session->setCreatorUid('alice');
        $session->setOpponentUid('bob');
        $session->setPoolId(3);
        $session->setCourseId(null);
        $session->setStatus($status);
        $session->setCurrentQuestionIndex(0);
        $session->setQuestionIds(json_encode([10, 11, 12]));
        return $session;
    }

    private function service(DuelSessionMapper $sessionMapper, DuelAnswerMapper $answerMapper, FakeDbConnection $db): DuelService {
        $translation = $this->createMock(TranslationService::class);
        $translation->method('normalizeLang')->willReturn(null);
        return new DuelService(
            $sessionMapper,
            $answerMapper,
            $this->createMock(DuelInviteMapper::class),
            $db,
            $this->createMock(LoggerInterface::class),
            $this->createMock(LeagueService::class),
            $translation,
            $this->createMock(IConfig::class),
            $this->createMock(CourseService::class),
            $this->createMock(QuestionService::class)
        );
    }
}
