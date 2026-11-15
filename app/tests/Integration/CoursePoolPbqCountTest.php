<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Service\CourseService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #46: the course detail names how many interactive simulations (question_type 'pbq')
 * each pool holds, so Training offers "Simulators only" only where there are any.
 */
final class CoursePoolPbqCountTest extends TestCase {
	private const TEACHER = 'pbq46-teacher';
	private const MARK = 'PBQ count 46';

	private IDBConnection $db;
	private int $courseId = 0;
	private int $withPbq = 0;
	private int $withoutPbq = 0;

	protected function setUp(): void {
		$this->db = Server::get(IDBConnection::class);
		$this->cleanUp();
		$this->withPbq = $this->pool(['single', 'pbq', 'pbq']);
		$this->withoutPbq = $this->pool(['single', 'multi']);
		$this->courseId = $this->insert('learning_courses', [
			'title' => self::MARK . ' course',
			'instructor_id' => self::TEACHER,
			'created_at' => time(),
			'updated_at' => time(),
		]);
		foreach ([$this->withPbq, $this->withoutPbq] as $poolId) {
			$this->insert('learning_course_pools', ['course_id' => $this->courseId, 'pool_id' => $poolId]);
		}
	}

	protected function tearDown(): void {
		$this->cleanUp();
	}

	public function testEachPoolNamesItsSimulations(): void {
		$course = Server::get(CourseService::class)->findById($this->courseId, self::TEACHER);
		$byPool = array_column($course['pools'], 'pbq_count', 'pool_id');

		$this->assertSame(2, $byPool[$this->withPbq]);
		$this->assertSame(0, $byPool[$this->withoutPbq]);
	}

	/**
	 * The count follows the set Training draws from: questions paused in the course are not
	 * offered, so a pool whose simulations are all paused offers no "Simulators only".
	 */
	public function testSimulationsPausedInTheCourseAreNotCounted(): void {
		$pbqIds = $this->db->getQueryBuilder();
		$pbqIds->select('id')->from('learning_questions')
			->where($pbqIds->expr()->eq('pool_id', $pbqIds->createNamedParameter($this->withPbq, IQueryBuilder::PARAM_INT)))
			->andWhere($pbqIds->expr()->eq('question_type', $pbqIds->createNamedParameter('pbq')));
		foreach (array_map('intval', $pbqIds->executeQuery()->fetchAll(\PDO::FETCH_COLUMN)) as $questionId) {
			$this->insert('learning_course_question_overrides', [
				'course_id' => $this->courseId,
				'question_id' => $questionId,
				'paused' => true,
				'created_at' => time(),
				'updated_at' => time(),
			]);
		}

		$course = Server::get(CourseService::class)->findById($this->courseId, self::TEACHER);
		$byPool = array_column($course['pools'], 'pbq_count', 'pool_id');

		$this->assertSame(0, $byPool[$this->withPbq]);

		// Review of #47: the exam draws from the same set, so the exam card's count leaves the
		// paused questions out too, while question_count still names the whole filtered pool.
		$examCount = array_column($course['pools'], 'exam_question_count', 'pool_id');
		$this->assertSame(1, $examCount[$this->withPbq]);
		$this->assertSame(3, array_column($course['pools'], 'question_count', 'pool_id')[$this->withPbq]);
		$this->assertSame(2, $examCount[$this->withoutPbq]);
	}

	/** @param string[] $types */
	private function pool(array $types): int {
		$poolId = $this->insert('learning_pools', [
			'user_id' => self::TEACHER,
			'name' => self::MARK . ' pool',
			'review_status' => 'published',
			'created_at' => time(),
			'updated_at' => time(),
		]);
		foreach ($types as $i => $type) {
			$this->insert('learning_questions', [
				'pool_id' => $poolId,
				'user_id' => self::TEACHER,
				'text' => self::MARK . ' question ' . $i,
				'question_type' => $type,
				'review_status' => 'published',
				'created_at' => time(),
				'updated_at' => time(),
			]);
		}
		return $poolId;
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
			->where($courses->expr()->eq('title', $courses->createNamedParameter(self::MARK . ' course')));
		$courseIds = array_map('intval', $courses->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
		if ($courseIds !== []) {
			foreach (['learning_course_question_overrides' => 'course_id', 'learning_course_pools' => 'course_id', 'learning_courses' => 'id'] as $table => $column) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete($table)
					->where($qb->expr()->in($column, $qb->createNamedParameter($courseIds, IQueryBuilder::PARAM_INT_ARRAY)))
					->executeStatement();
			}
		}
		$pools = $this->db->getQueryBuilder();
		$pools->select('id')->from('learning_pools')
			->where($pools->expr()->eq('name', $pools->createNamedParameter(self::MARK . ' pool')));
		$poolIds = array_map('intval', $pools->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
		if ($poolIds !== []) {
			foreach (['learning_questions' => 'pool_id', 'learning_pools' => 'id'] as $table => $column) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete($table)
					->where($qb->expr()->in($column, $qb->createNamedParameter($poolIds, IQueryBuilder::PARAM_INT_ARRAY)))
					->executeStatement();
			}
		}
	}
}
