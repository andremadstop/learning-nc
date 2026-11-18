<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Service\QuestionService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #98 (review) on a real database: the question list finds an opened question's page by
 * searching its pool for the ID. The numeric query also matches every text with those digits,
 * newest first and capped at 50, so an older question behind 55 such texts was cut off.
 */
final class QuestionSearchIdLookup98Test extends TestCase {
	private const OWNER = 'qsid98-owner';
	private const MARK = 'QSearchId98';

	private IDBConnection $db;
	private int $pool = 0;
	private int $target = 0;

	protected function setUp(): void {
		$this->db = Server::get(IDBConnection::class);
		$this->cleanUp();
		$this->pool = $this->insert('learning_pools', [
			'user_id' => self::OWNER, 'name' => self::MARK . ' Netze', 'review_status' => 'published',
			'created_at' => time(), 'updated_at' => time(),
		]);
		$this->target = $this->question('The oldest question', time() - 3600);
		for ($i = 0; $i < 55; $i++) {
			$this->question('Port ' . $this->target . ' case ' . $i, time());
		}
	}

	protected function tearDown(): void {
		$this->cleanUp();
	}

	public function testTheQuestionWithThatIdIsFoundAndPlaced(): void {
		$hits = Server::get(QuestionService::class)->search((string)$this->target, self::OWNER, 50, $this->pool);

		$this->assertCount(50, $hits);
		$this->assertSame($this->target, (int)$hits[0]['id']);
		$this->assertIsInt($hits[0]['position']);
		$this->assertSame(self::MARK . ' Netze', $hits[0]['pool_name'] ?? null);
	}

	private function question(string $text, int $created): int {
		return $this->insert('learning_questions', [
			'pool_id' => $this->pool, 'user_id' => self::OWNER, 'text' => self::MARK . ' ' . $text,
			'question_type' => 'single', 'review_status' => 'published', 'created_at' => $created, 'updated_at' => $created,
		]);
	}

	/** @param array<string, int|string|bool> $values */
	private function insert(string $table, array $values): int {
		$qb = $this->db->getQueryBuilder();
		$params = [];
		foreach ($values as $column => $value) {
			$type = is_bool($value) ? IQueryBuilder::PARAM_BOOL : (is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR);
			$params[$column] = $qb->createNamedParameter($value, $type);
		}
		$qb->insert($table)->values($params)->executeStatement();
		return $qb->getLastInsertId();
	}

	private function cleanUp(): void {
		$pools = $this->db->getQueryBuilder();
		$pools->select('id')->from('learning_pools')
			->where($pools->expr()->like('name', $pools->createNamedParameter(self::MARK . ' %')));
		$poolIds = array_map('intval', $pools->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
		if ($poolIds === []) {
			return;
		}
		foreach (['learning_questions' => 'pool_id', 'learning_pools' => 'id'] as $table => $column) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->in($column, $qb->createNamedParameter($poolIds, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
	}
}
