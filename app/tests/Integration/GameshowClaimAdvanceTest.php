<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Db\GameshowAnswer;
use OCA\Learning\Db\GameshowAnswerMapper;
use OCA\Learning\Db\GameshowPlayer;
use OCA\Learning\Db\GameshowPlayerMapper;
use OCA\Learning\Db\GameshowSession;
use OCA\Learning\Db\GameshowSessionMapper;
use OCA\Learning\Service\GameshowService;
use OCP\AppFramework\App;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * A gameshow question is resolved by whichever request sees it first: a poll after the answer
 * deadline, or the last answer. Several of them can arrive together (every player's SSE stream
 * polls), so only one may score the question and move the game on. claimAdvance is that guard;
 * it runs against the real database because the "changed rows" count differs between engines.
 */
final class GameshowClaimAdvanceTest extends TestCase {
	private const CODE = 'zclaim';

	private GameshowSessionMapper $mapper;
	/** @var int[] */
	private array $sessionIds = [];

	protected function setUp(): void {
		$this->mapper = Server::get(GameshowSessionMapper::class);
		$this->deleteRows();
	}

	protected function tearDown(): void {
		$this->deleteRows();
	}

	public function testOnlyTheFirstClaimForAQuestionWins(): void {
		$session = $this->insertSession('active', 0);
		$startedAt = intval(microtime(true) * 1000);

		$this->assertTrue($this->mapper->claimAdvance($session->getId(), 0, $startedAt));
		$this->assertFalse($this->mapper->claimAdvance($session->getId(), 0, $startedAt), 'a second request for the same question loses');

		$stored = $this->mapper->findById($session->getId());
		$this->assertSame(1, $stored->getCurrentQuestionIndex(), 'the question moved on exactly once');
		$this->assertSame($startedAt, $stored->getQuestionStartedAt());

		$this->assertTrue($this->mapper->claimAdvance($session->getId(), 1, $startedAt + 1), 'the next question can be claimed');
		$this->assertSame(2, $this->mapper->findById($session->getId())->getCurrentQuestionIndex());
	}

	public function testASessionThatIsNoLongerActiveCannotBeClaimed(): void {
		$session = $this->insertSession('finished', 0);

		$this->assertFalse($this->mapper->claimAdvance($session->getId(), 0, intval(microtime(true) * 1000)));
		$this->assertSame(0, $this->mapper->findById($session->getId())->getCurrentQuestionIndex());
	}

	/**
	 * The real service on the real database: a poll after the deadline records the silent
	 * player, scores, moves on and finally ends the game, with the transaction committed.
	 */
	public function testAPollAfterTheDeadlineMovesTheGameOnAndEndsIt(): void {
		$service = (new App('learning'))->getContainer()->get(GameshowService::class);
		$db = Server::get(IDBConnection::class);
		$session = $this->insertSession('active', 0, 2);
		foreach (['zclaim-alice' => 0, 'zclaim-bob' => 1] as $uid => $slot) {
			$player = new GameshowPlayer();
			$player->setSessionId($session->getId());
			$player->setUserId($uid);
			$player->setSlot($slot);
			$player->setDisplayName($uid);
			$player->setScore(0);
			$player->setLives(3);
			$player->setIsReady(true);
			$player->setIsRemoved(false);
			$player->setLastPoll(time());
			$player->setJoinedAt(time());
			Server::get(GameshowPlayerMapper::class)->insert($player);
		}

		foreach ([0, 1] as $index) {
			$answer = new GameshowAnswer();
			$answer->setSessionId($session->getId());
			$answer->setQuestionIndex($index);
			$answer->setPlayerUid('zclaim-alice');
			$answer->setAnswerId(1);
			$answer->setIsCorrect(true);
			$answer->setAnsweredAt(intval(microtime(true) * 1000) - 25000);
			$answer->setPointsEarned(0);
			Server::get(GameshowAnswerMapper::class)->insert($answer);

			$state = $service->getState(self::CODE, 'zclaim-alice');
			$this->assertFalse($db->inTransaction(), 'the resolution committed');
			if ($index === 0) {
				$this->assertSame('active', $state['status']);
				$this->assertSame(1, $state['current_question_index']);
				$this->setStartedAt($session->getId(), intval(microtime(true) * 1000) - 20000);
			}
		}

		$this->assertSame('finished', $state['status']);
		$this->assertSame(1, $state['current_question_index']);
		$misses = array_filter(
			Server::get(GameshowAnswerMapper::class)->findBySession($session->getId()),
			static fn (GameshowAnswer $a): bool => $a->getPlayerUid() === 'zclaim-bob'
		);
		$this->assertCount(2, $misses, 'bob is recorded once per question he let run out');
		foreach ($misses as $miss) {
			$this->assertFalse((bool)$miss->getIsCorrect());
			$this->assertNull($miss->getAnswerId());
		}
		$scores = [];
		foreach ($state['players'] as $p) {
			$scores[$p['user_id']] = $p['score'];
		}
		$this->assertGreaterThan(0, $scores['zclaim-alice']);
		$this->assertSame(0, $scores['zclaim-bob']);
	}

	private function setStartedAt(int $sessionId, int $startedAt): void {
		$session = $this->mapper->findById($sessionId);
		$session->setQuestionStartedAt($startedAt);
		$this->mapper->update($session);
	}

	private function insertSession(string $status, int $index, int $numQuestions = 3): GameshowSession {
		$session = new GameshowSession();
		$session->setCode(self::CODE);
		$session->setPoolId(1);
		$session->setMode('sprint');
		$session->setStatus($status);
		$session->setMaxPlayers(5);
		$session->setMinPlayers(2);
		$session->setQuestionIds(json_encode([10, 11, 12]));
		$session->setCurrentQuestionIndex($index);
		$session->setNumQuestions($numQuestions);
		$session->setCreatedAt(time());
		$session->setCreatorUid('claim-test');
		$session->setQuestionStartedAt(intval(microtime(true) * 1000) - 20000);
		$session = $this->mapper->insert($session);
		$this->sessionIds[] = $session->getId();
		return $session;
	}

	private function deleteRows(): void {
		foreach (['learning_gameshow_answers', 'learning_gameshow_players'] as $table) {
			foreach ($this->sessionIds as $id) {
				$qb = Server::get(IDBConnection::class)->getQueryBuilder();
				$qb->delete($table)
					->where($qb->expr()->eq('session_id', $qb->createNamedParameter($id)))
					->executeStatement();
			}
		}
		$this->sessionIds = [];
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->delete('learning_gameshow_sessions')
			->where($qb->expr()->eq('code', $qb->createNamedParameter(self::CODE)))
			->executeStatement();
	}
}
