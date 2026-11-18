<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Service\QuestionService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #98 on a real database: the Pools search said "in {pool}" under every question. The
 * search asked for selectDistinct("q.*", "p.name as pool_name"), but selectDistinct() takes one
 * argument and silently dropped the second, so no row had pool_name. Each hit now carries the name
 * of its pool — for the owner, for a "View only" share, and in the in-pool search.
 */
final class QuestionSearchPoolName98Test extends TestCase {
	private const OWNER = 'qs98-owner';
	private const READER = 'qs98-reader';
	private const MARK = 'QSearch98';

	private IDBConnection $db;
	private int $pool = 0;
	private int $question = 0;

	protected function setUp(): void {
		$this->db = Server::get(IDBConnection::class);
		$this->cleanUp();
		$this->pool = $this->insert('learning_pools', [
			'user_id' => self::OWNER, 'name' => self::MARK . ' Fire safety', 'review_status' => 'published',
			'created_at' => time(), 'updated_at' => time(),
		]);
		$this->question = $this->insert('learning_questions', [
			'pool_id' => $this->pool, 'user_id' => self::OWNER, 'text' => self::MARK . ' Where is the extinguisher?',
			'question_type' => 'single', 'review_status' => 'published', 'created_at' => time(), 'updated_at' => time(),
		]);
		$this->insert('learning_pool_shares', [
			'pool_id' => $this->pool, 'shared_with' => self::READER, 'share_type' => 'user', 'permission' => 'read',
		]);
	}

	protected function tearDown(): void {
		$this->cleanUp();
	}

	public function testEveryHitNamesItsPool(): void {
		foreach ([self::OWNER, self::READER] as $user) {
			$hits = Server::get(QuestionService::class)->search(self::MARK . ' Where', $user);
			$this->assertCount(1, $hits, $user);
			$this->assertSame($this->question, (int)$hits[0]['id']);
			$this->assertSame(self::MARK . ' Fire safety', $hits[0]['pool_name'] ?? null, $user);
		}
	}

	public function testTheInPoolSearchByIdNamesThePoolAndThePosition(): void {
		$hits = Server::get(QuestionService::class)->search((string)$this->question, self::OWNER, 50, $this->pool);
		$byId = array_column($hits, null, 'id');
		$this->assertArrayHasKey($this->question, $byId);
		$this->assertSame(self::MARK . ' Fire safety', $byId[$this->question]['pool_name'] ?? null);
		$this->assertSame(0, $byId[$this->question]['position']);
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
		foreach (['learning_questions' => 'pool_id', 'learning_pool_shares' => 'pool_id', 'learning_pools' => 'id'] as $table => $column) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->in($column, $qb->createNamedParameter($poolIds, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
	}
}
