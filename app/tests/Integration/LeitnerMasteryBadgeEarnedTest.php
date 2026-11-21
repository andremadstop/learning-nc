<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Service\LeitnerService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * (Pre-existing, 5.18.0) LeitnerService::answerQuestion() checks the mastery badge inside its
 * write transaction when a card reaches box 5. BadgeService found "already earned" by a failing
 * INSERT on UNIQUE(user_id, badge_id); on PostgreSQL that aborts the transaction (25P02): the
 * stats UPDATE after it failed, the box change and the XP were rolled back and the learner saw an
 * error — on every further card that reached box 5 once 'mastermind' was earned.
 *
 * Seeds 49 cards in box 5, one in box 4 and the earned badge; the answer brings the 50th card to
 * box 5 (the mastermind threshold), so the badge check runs and meets the existing row. Runs
 * against pgsql and mysql.
 */
final class LeitnerMasteryBadgeEarnedTest extends TestCase {
	private const LEARNER = 'lmb-learner';
	private const MARK = 'LeitnerMasteryBadge';
	private const XP_BEFORE = 1000;

	private IDBConnection $db;
	private int $poolId = 0;
	private int $itemId = 0;
	private int $rightAnswerId = 0;

	protected function setUp(): void {
		$this->db = Server::get(IDBConnection::class);
		$this->cleanUp();
		$now = time();
		// The learner owns the pool: Leitner is open for the owner (no course switch applies).
		$this->poolId = $this->insert('learning_pools', [
			'user_id' => self::LEARNER, 'name' => self::MARK . ' pool', 'review_status' => 'published',
			'created_at' => $now, 'updated_at' => $now,
		]);
		for ($i = 0; $i < 50; $i++) {
			$qid = $this->insert('learning_questions', [
				'pool_id' => $this->poolId, 'user_id' => self::LEARNER, 'text' => self::MARK . ' question ' . $i,
				'review_status' => 'published', 'created_at' => $now, 'updated_at' => $now,
			]);
			$right = $this->insert('learning_answers', ['question_id' => $qid, 'text' => 'right', 'is_correct' => true]);
			$this->insert('learning_answers', ['question_id' => $qid, 'text' => 'wrong', 'is_correct' => false]);
			$item = $this->insert('learning_leitner_items', [
				'user_id' => self::LEARNER, 'pool_id' => $this->poolId, 'question_id' => $qid,
				'box' => $i === 0 ? 4 : 5, 'next_review' => $now - 60,
				'correct_count' => 4, 'incorrect_count' => 0,
			]);
			if ($i === 0) {
				$this->itemId = $item;
				$this->rightAnswerId = $right;
			}
		}
		$this->insert('learning_user_badges', ['user_id' => self::LEARNER, 'badge_id' => 'mastermind', 'earned_at' => $now - 86400]);
		$this->insert('learning_user_stats', [
			'user_id' => self::LEARNER, 'total_xp' => self::XP_BEFORE, 'current_level' => 1,
			'current_streak' => 0, 'longest_streak' => 0, 'last_activity_date' => gmdate('Y-m-d'),
			'total_sessions' => 0, 'total_mastered' => 49, 'updated_at' => $now,
		]);
	}

	protected function tearDown(): void {
		$this->cleanUp();
	}

	public function testThePromotionToBoxFiveIsSavedWhenMastermindWasEarnedBefore(): void {
		$response = Server::get(LeitnerService::class)
			->answerQuestion($this->itemId, $this->rightAnswerId, self::LEARNER, null, null, null, 3);

		$this->assertTrue($response['correct']);
		$this->assertSame(4, $response['old_box']);
		$this->assertSame(5, $response['new_box']);
		$this->assertSame([], $response['newly_earned_badges'], 'mastermind was earned before');

		$this->assertSame(5, (int)$this->value('learning_leitner_items', 'box', 'id', $this->itemId), 'the box change was rolled back');
		$this->assertSame(5, (int)$this->value('learning_leitner_items', 'correct_count', 'id', $this->itemId));
		$this->assertGreaterThan(self::XP_BEFORE, (int)$this->value('learning_user_stats', 'total_xp', 'user_id', self::LEARNER), 'the XP was rolled back');
		$this->assertSame(50, (int)$this->value('learning_user_stats', 'total_mastered', 'user_id', self::LEARNER));
		$this->assertSame(1, $this->badgeRows(), 'the badge stays earned once');
	}

	private function value(string $table, string $column, string $key, int|string $id): mixed {
		$qb = $this->db->getQueryBuilder();
		$qb->select($column)->from($table)
			->where($qb->expr()->eq($key, $qb->createNamedParameter($id, is_int($id) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR)));
		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();
		return $value;
	}

	private function badgeRows(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))->from('learning_user_badges')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::LEARNER)))
			->andWhere($qb->expr()->eq('badge_id', $qb->createNamedParameter('mastermind')));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
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
		return $table === 'learning_user_badges' || $table === 'learning_user_stats' ? 0 : $qb->getLastInsertId();
	}

	private function cleanUp(): void {
		foreach (['learning_leitner_items', 'learning_user_badges', 'learning_user_stats', 'learning_xp_log'] as $table) {
			if (!$this->db->tableExists($table)) {
				continue;
			}
			$qb = $this->db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::LEARNER)))->executeStatement();
		}
		$pools = $this->db->getQueryBuilder();
		$pools->select('id')->from('learning_pools')
			->where($pools->expr()->eq('user_id', $pools->createNamedParameter(self::LEARNER)));
		$poolIds = array_map('intval', $pools->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
		if ($poolIds === []) {
			return;
		}
		$questions = $this->db->getQueryBuilder();
		$questions->select('id')->from('learning_questions')
			->where($questions->expr()->in('pool_id', $questions->createNamedParameter($poolIds, IQueryBuilder::PARAM_INT_ARRAY)));
		$questionIds = array_map('intval', $questions->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
		if ($questionIds !== []) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('learning_answers')
				->where($qb->expr()->in('question_id', $qb->createNamedParameter($questionIds, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
		foreach (['learning_questions' => 'pool_id', 'learning_pools' => 'id'] as $table => $column) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->in($column, $qb->createNamedParameter($poolIds, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
	}
}
