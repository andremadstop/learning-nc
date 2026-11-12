<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Db\AnswerMapper;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\PoolShareMapper;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Service\PoolBulkImportService;
use OCA\Learning\Service\PoolService;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\TranslationService;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The pool import (PoolBulkImportService, GitHub #34) on a real database: a pool whose question
 * insert fails in the database is rolled back completely — no pool row, no questions — while the
 * pools before and after it are stored, and the connection keeps working afterwards. On
 * PostgreSQL a failed statement aborts the open transaction (25P02); a COMMIT on it acts as a
 * silent ROLLBACK, so a service that committed instead of rolling back would report success.
 *
 * How the failure is produced: a QuestionMapper whose insert() reuses the id of the question
 * inserted just before it, for the question with the marker text. The INSERT that reaches the
 * database is the real one of the real mapper, and it fails on the primary key (unique
 * violation) — after pool B's row and its first question are already in B's transaction. No
 * input is used that slips past today's validation: such a test would silently stop reaching
 * the database the day the missing check is added.
 */
final class PoolBulkImportTest extends TestCase {
	private const USER = 'pool-bulk-import-it';
	private const FAIL_MARKER = 'B-Q2-FAIL-AT-DB';

	private IDBConnection $db;
	private IL10N $l;

	protected function setUp(): void {
		$this->db = Server::get(IDBConnection::class);
		$this->l = Server::get(IFactory::class)->get('learning', 'en');
		$this->cleanUp();
	}

	protected function tearDown(): void {
		$this->cleanUp();
	}

	public function testFailingPoolIsRolledBackAndTheOthersAreStored(): void {
		$mapper = new class(Server::get(IDBConnection::class), self::FAIL_MARKER) extends QuestionMapper {
			public ?int $lastInsertedId = null;
			public ?\Throwable $failure = null;

			public function __construct(IDBConnection $db, private string $marker) {
				parent::__construct($db);
			}

			public function insert(Entity $entity): Entity {
				if ($entity->getText() === $this->marker && $this->lastInsertedId !== null) {
					// The real INSERT of this question, with the primary key of the previous one.
					$entity->setId($this->lastInsertedId);
					try {
						return parent::insert($entity);
					} catch (\Throwable $e) {
						$this->failure = $e;
						throw $e;
					}
				}
				$inserted = parent::insert($entity);
				$this->lastInsertedId = (int)$inserted->getId();
				return $inserted;
			}
		};
		$service = $this->serviceWith($mapper);

		$results = $service->import($this->document(), self::USER, false, $this->l);

		// The failure really came from the database, not from a check in PHP.
		$this->assertInstanceOf(DbException::class, $mapper->failure, 'pool B must fail on a database statement');
		$this->assertSame(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION, $mapper->failure->getReason());
		$this->assertFalse($this->db->inTransaction(), 'the import must not leave a transaction open');

		$this->assertCount(3, $results);
		[$a, $b, $c] = $results;

		$this->assertSame([], $a['errors']);
		$this->assertSame(2, $a['imported']);
		$this->assertNotNull($a['id']);
		$this->assertSame([], $c['errors']);
		$this->assertSame(2, $c['imported']);
		$this->assertNotNull($c['id']);
		$this->assertNull($b['id']);
		$this->assertSame(0, $b['imported']);
		$this->assertNotSame([], $b['errors']);

		// What is in the database, not what the result says.
		$this->assertSame(['Import A', 'Import C'], $this->poolNames());
		$this->assertSame(2, $this->questionCount((int)$a['id']));
		$this->assertSame(4, $this->answerCount((int)$a['id']));
		$this->assertSame(2, $this->questionCount((int)$c['id']));
		$this->assertSame(4, $this->answerCount((int)$c['id']));
		$this->assertSame(0, $this->questionsOutsideAAndC(), 'no question of pool B may survive');

		// The connection still writes after the failure: a new pool is there when read back.
		$after = Server::get(PoolService::class)->create('Written after the failure', null, self::USER);
		Server::get(QuestionService::class)->create((int)$after->getId(), self::USER, 'After question', null, 'medium', [
			['text' => 'right', 'is_correct' => true],
			['text' => 'wrong', 'is_correct' => false],
		], 'single');
		$this->assertFalse($this->db->inTransaction());
		$this->assertSame(['Import A', 'Import C', 'Written after the failure'], $this->poolNames());
		$this->assertSame(1, $this->questionCount((int)$after->getId()));
	}

	public function testDryRunWritesNothing(): void {
		$service = $this->serviceWith(Server::get(QuestionMapper::class));
		$before = $this->tableCounts();

		$results = $service->import($this->document(), self::USER, true, $this->l);

		$this->assertSame($before, $this->tableCounts());
		$this->assertFalse($this->db->inTransaction());
		$this->assertSame([2, 2, 2], array_map(static fn (array $r): int => $r['importable'], $results));
		$this->assertSame([0, 0, 0], array_map(static fn (array $r): int => $r['imported'], $results));
		$this->assertSame([null, null, null], array_map(static fn (array $r): ?int => $r['id'], $results));
	}

	private function serviceWith(QuestionMapper $questionMapper): PoolBulkImportService {
		$questions = new QuestionService(
			$questionMapper,
			Server::get(AnswerMapper::class),
			Server::get(PoolShareMapper::class),
			Server::get(PoolMapper::class),
			Server::get(IDBConnection::class),
			Server::get(TranslationService::class),
			Server::get(LoggerInterface::class),
		);
		return new PoolBulkImportService(
			Server::get(PoolService::class),
			$questions,
			Server::get(IDBConnection::class),
			Server::get(LoggerInterface::class),
		);
	}

	/** @return array<string, mixed> */
	private function document(): array {
		$question = static fn (string $text): array => [
			'text' => $text,
			'answers' => [
				['text' => 'right', 'is_correct' => true],
				['text' => 'wrong', 'is_correct' => false],
			],
		];
		return ['pools' => [
			['name' => 'Import A', 'questions' => [$question('A question 1'), $question('A question 2')]],
			['name' => 'Import B', 'questions' => [$question('B question 1'), $question(self::FAIL_MARKER)]],
			['name' => 'Import C', 'questions' => [$question('C question 1'), $question('C question 2')]],
		]];
	}

	/** @return list<string> names of the test user's pools, sorted */
	private function poolNames(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('name')->from('learning_pools')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::USER)));
		$result = $qb->executeQuery();
		$names = [];
		while (($name = $result->fetchOne()) !== false) {
			$names[] = (string)$name;
		}
		$result->closeCursor();
		sort($names);
		return $names;
	}

	private function questionCount(int $poolId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'n'))->from('learning_questions')
			->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT)));
		return $this->fetchInt($qb);
	}

	private function answerCount(int $poolId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('a.id', 'n'))->from('learning_answers', 'a')
			->innerJoin('a', 'learning_questions', 'q', $qb->expr()->eq('a.question_id', 'q.id'))
			->where($qb->expr()->eq('q.pool_id', $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT)));
		return $this->fetchInt($qb);
	}

	/** Questions of the user that belong to none of the user's pools (B's must not survive). */
	private function questionsOutsideAAndC(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('q.id', 'n'))->from('learning_questions', 'q')
			->leftJoin('q', 'learning_pools', 'p', $qb->expr()->eq('q.pool_id', 'p.id'))
			->where($qb->expr()->eq('q.user_id', $qb->createNamedParameter(self::USER)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('p.id'),
				$qb->expr()->notIn('p.name', $qb->createNamedParameter(['Import A', 'Import C'], IQueryBuilder::PARAM_STR_ARRAY))
			));
		return $this->fetchInt($qb);
	}

	/** @return array<string, int> row count of every table the import writes */
	private function tableCounts(): array {
		$counts = [];
		foreach (['learning_pools', 'learning_questions', 'learning_answers'] as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->count('*', 'n'))->from($table);
			$counts[$table] = $this->fetchInt($qb);
		}
		return $counts;
	}

	private function fetchInt(IQueryBuilder $qb): int {
		$result = $qb->executeQuery();
		$n = (int)$result->fetchOne();
		$result->closeCursor();
		return $n;
	}

	/**
	 * Everything the test user owns, deleted explicitly (answers, questions, pools) — also what a
	 * broken import leaves behind without reporting an id.
	 */
	private function cleanUp(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('learning_questions')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::USER)));
		$result = $qb->executeQuery();
		$questionIds = [];
		while (($id = $result->fetchOne()) !== false) {
			$questionIds[] = (int)$id;
		}
		$result->closeCursor();
		foreach (array_chunk($questionIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('learning_answers')
				->where($qb->expr()->in('question_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
		foreach (['learning_questions', 'learning_pools'] as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::USER)))
				->executeStatement();
		}
	}
}
