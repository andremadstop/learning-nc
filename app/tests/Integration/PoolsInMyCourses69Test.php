<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\PoolService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #69 on a real database: "Pools meiner Kurse" lists the pools of the courses the user
 * leads (owner or co-instructor member) and nothing else — no pool of a foreign course, nothing
 * for a learner of the course. A co-instructor without an edit right also reads the pool's answer
 * statistics; a learner of the course and an instructor of an unrelated course do not.
 */
final class PoolsInMyCourses69Test extends TestCase {
	private const OWNER = 'pm69-owner';
	private const CO = 'pm69-co';
	private const LEARNER = 'pm69-learner';
	private const FOREIGN = 'pm69-foreign';
	private const MARK = 'PoolsMine 69';

	private IDBConnection $db;
	private int $fire = 0;
	private int $privacy = 0;
	private int $foreignPool = 0;
	private int $shared = 0;

	protected function setUp(): void {
		$this->db = Server::get(IDBConnection::class);
		$this->cleanUp();
		$this->fire = $this->pool(self::OWNER, 'Fire safety');
		$this->privacy = $this->pool(self::OWNER, 'Data protection');
		$this->shared = $this->pool(self::OWNER, 'Shared');
		$this->foreignPool = $this->pool(self::FOREIGN, 'Foreign');

		$course = $this->course(self::OWNER, 'Annual safety training');
		$refresher = $this->course(self::OWNER, 'Refresher');
		$foreign = $this->course(self::FOREIGN, 'Other course');
		foreach ([$this->fire, $this->privacy, $this->shared] as $poolId) {
			$this->insert('learning_course_pools', ['course_id' => $course, 'pool_id' => $poolId]);
		}
		$this->insert('learning_course_pools', ['course_id' => $refresher, 'pool_id' => $this->fire]);
		$this->insert('learning_course_pools', ['course_id' => $foreign, 'pool_id' => $this->foreignPool]);

		$this->insert('learning_course_members', ['course_id' => $course, 'user_id' => self::CO, 'role' => 'instructor', 'enrolled_at' => time()]);
		$this->insert('learning_course_members', ['course_id' => $refresher, 'user_id' => self::CO, 'role' => 'instructor', 'enrolled_at' => time()]);
		$this->insert('learning_course_members', ['course_id' => $course, 'user_id' => self::LEARNER, 'role' => 'student', 'enrolled_at' => time()]);
		$this->insert('learning_course_members', ['course_id' => $foreign, 'user_id' => self::CO, 'role' => 'student', 'enrolled_at' => time()]);

		$this->insert('learning_pool_shares', ['pool_id' => $this->shared, 'shared_with' => self::CO, 'share_type' => 'user', 'permission' => 'edit']);
	}

	protected function tearDown(): void {
		$this->cleanUp();
	}

	public function testACoInstructorSeesThePoolsOfTheirCoursesOnly(): void {
		$pools = Server::get(PoolService::class)->findInMyCourses(self::CO);
		$byId = array_column($pools, null, 'id');

		$this->assertSame($this->expectedIds(), $this->sortedIds($pools));
		$this->assertArrayNotHasKey($this->foreignPool, $byId, 'a learner seat in a foreign course lists none of its pools');
		$this->assertSame('read', $byId[$this->fire]['permission']);
		$this->assertTrue($byId[$this->fire]['is_shared']);
		$this->assertSame('edit', $byId[$this->shared]['permission']);
		$this->assertSame([self::MARK . ' Annual safety training', self::MARK . ' Refresher'], array_column($byId[$this->fire]['courses'], 'title'));
	}

	public function testTheOwnerSeesTheirOwnPoolsAsOwner(): void {
		$pools = Server::get(PoolService::class)->findInMyCourses(self::OWNER);
		$this->assertSame($this->expectedIds(), $this->sortedIds($pools));
		$this->assertSame(['owner'], array_values(array_unique(array_column($pools, 'permission'))));
		$this->assertSame([false], array_values(array_unique(array_column($pools, 'is_shared'))));
	}

	public function testALearnerOfTheCourseGetsNothing(): void {
		$this->assertSame([], Server::get(PoolService::class)->findInMyCourses(self::LEARNER));
	}

	public function testACoInstructorReadsTheStatisticsALearnerDoesNot(): void {
		$stats = Server::get(CourseService::class)->getPoolAnswerStats($this->fire, self::CO);
		$this->assertSame(2, $stats['led_courses']);

		try {
			Server::get(CourseService::class)->getPoolAnswerStats($this->fire, self::LEARNER);
			$this->fail('a learner of the course must not read the pool statistics');
		} catch (ForbiddenException $e) {
			$this->addToAssertionCount(1);
		}
		$this->expectException(ForbiddenException::class);
		Server::get(CourseService::class)->getPoolAnswerStats($this->fire, self::FOREIGN);
	}

	/**
	 * @param list<array<string, mixed>> $pools
	 * @return list<int>
	 */
	private function sortedIds(array $pools): array {
		$ids = array_map(static fn (array $p): int => (int)$p['id'], $pools);
		sort($ids);
		return $ids;
	}

	/** @return list<int> */
	private function expectedIds(): array {
		$ids = [$this->fire, $this->privacy, $this->shared];
		sort($ids);
		return $ids;
	}

	private function pool(string $owner, string $name): int {
		return $this->insert('learning_pools', [
			'user_id' => $owner, 'name' => self::MARK . ' ' . $name, 'review_status' => 'published',
			'created_at' => time(), 'updated_at' => time(),
		]);
	}

	private function course(string $owner, string $title): int {
		return $this->insert('learning_courses', [
			'title' => self::MARK . ' ' . $title, 'instructor_id' => $owner,
			'created_at' => time(), 'updated_at' => time(),
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
		$courses = $this->db->getQueryBuilder();
		$courses->select('id')->from('learning_courses')
			->where($courses->expr()->like('title', $courses->createNamedParameter(self::MARK . ' %')));
		$courseIds = array_map('intval', $courses->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
		if ($courseIds !== []) {
			foreach (['learning_course_pools' => 'course_id', 'learning_course_members' => 'course_id', 'learning_courses' => 'id'] as $table => $column) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete($table)
					->where($qb->expr()->in($column, $qb->createNamedParameter($courseIds, IQueryBuilder::PARAM_INT_ARRAY)))
					->executeStatement();
			}
		}
		$pools = $this->db->getQueryBuilder();
		$pools->select('id')->from('learning_pools')
			->where($pools->expr()->like('name', $pools->createNamedParameter(self::MARK . ' %')));
		$poolIds = array_map('intval', $pools->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
		if ($poolIds !== []) {
			foreach (['learning_pool_shares' => 'pool_id', 'learning_pools' => 'id'] as $table => $column) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete($table)
					->where($qb->expr()->in($column, $qb->createNamedParameter($poolIds, IQueryBuilder::PARAM_INT_ARRAY)))
					->executeStatement();
			}
		}
	}
}
