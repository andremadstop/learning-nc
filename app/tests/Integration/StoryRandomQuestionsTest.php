<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Service\StoryEngineService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * 2026-11 audit S05: the Story skill-check questions were drawn with ORDER BY RANDOM(), a
 * function MySQL/MariaDB do not have — every linear campaign with a skill check failed there.
 * Runs against the real database, because only the database can reject the function.
 */
final class StoryRandomQuestionsTest extends TestCase {
	private const POOL_NAME = 'Story Random IT Zebrafinch';
	private const USER = 'story-random-it';

	private IDBConnection $db;

	protected function setUp(): void {
		$this->db = Server::get(IDBConnection::class);
		$this->cleanUp();
		$this->seedPoolWithQuestion();
	}

	protected function tearDown(): void {
		$this->cleanUp();
	}

	public function testFilteredSkillCheckQuestionsAreDrawn(): void {
		$questions = $this->fetch('zebrafinch', 1);

		$this->assertCount(1, $questions);
		$this->assertSame('Which port does HTTPS use? (story-random-it)', $questions[0]['text']);
		$this->assertCount(2, $questions[0]['answers']);
	}

	public function testUnfilteredDrawRunsOnThisDatabase(): void {
		$this->assertIsArray($this->fetch(null, 3));
	}

	private function fetch(?string $filter, int $count): array {
		$method = new \ReflectionMethod(StoryEngineService::class, 'fetchFilteredQuestions');
		return $method->invoke(Server::get(StoryEngineService::class), self::USER, $filter, $count, 0);
	}

	private function seedPoolWithQuestion(): void {
		$now = time();
		$pool = $this->db->getQueryBuilder();
		$pool->insert('learning_pools')->values([
			'user_id' => $pool->createNamedParameter(self::USER),
			'name' => $pool->createNamedParameter(self::POOL_NAME),
			'created_at' => $pool->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'updated_at' => $pool->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
		$poolId = $pool->getLastInsertId();

		$question = $this->db->getQueryBuilder();
		$question->insert('learning_questions')->values([
			'pool_id' => $question->createNamedParameter($poolId, IQueryBuilder::PARAM_INT),
			'user_id' => $question->createNamedParameter(self::USER),
			'text' => $question->createNamedParameter('Which port does HTTPS use? (story-random-it)'),
			'created_at' => $question->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'updated_at' => $question->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		])->executeStatement();
		$questionId = $question->getLastInsertId();

		foreach (['443' => true, '80' => false] as $text => $correct) {
			$answer = $this->db->getQueryBuilder();
			$answer->insert('learning_answers')->values([
				'question_id' => $answer->createNamedParameter($questionId, IQueryBuilder::PARAM_INT),
				'text' => $answer->createNamedParameter((string)$text),
				'is_correct' => $answer->createNamedParameter($correct, IQueryBuilder::PARAM_BOOL),
			])->executeStatement();
		}
	}

	private function cleanUp(): void {
		$ids = $this->db->getQueryBuilder();
		$ids->select('id')->from('learning_pools')
			->where($ids->expr()->eq('user_id', $ids->createNamedParameter(self::USER)));
		$result = $ids->executeQuery();
		$poolIds = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();
		if ($poolIds === []) {
			return;
		}

		$q = $this->db->getQueryBuilder();
		$q->select('id')->from('learning_questions')
			->where($q->expr()->in('pool_id', $q->createNamedParameter($poolIds, IQueryBuilder::PARAM_INT_ARRAY)));
		$result = $q->executeQuery();
		$questionIds = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();

		if ($questionIds !== []) {
			$a = $this->db->getQueryBuilder();
			$a->delete('learning_answers')
				->where($a->expr()->in('question_id', $a->createNamedParameter($questionIds, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
			$dq = $this->db->getQueryBuilder();
			$dq->delete('learning_questions')
				->where($dq->expr()->in('id', $dq->createNamedParameter($questionIds, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
		$p = $this->db->getQueryBuilder();
		$p->delete('learning_pools')
			->where($p->expr()->in('id', $p->createNamedParameter($poolIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeStatement();
	}
}
