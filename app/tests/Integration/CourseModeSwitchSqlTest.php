<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Service\CourseService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #39 (B1): CourseService::getPoolIdsClosedForMode against the real database (pgsql and
 * mysql). Pool A sits only in a course with Leitner off, pool B also in a course with Leitner on,
 * pool C in a course the user leads with Leitner off, pool D in no course of the user.
 */
final class CourseModeSwitchSqlTest extends TestCase {
	private const LEARNER = 'b1-learner';
	private const TEACHER = 'b1-teacher';
	private const MARK = 'B1 mode switch';

	private IDBConnection $db;
	/** @var array<string, int> */
	private array $pools = [];

	protected function setUp(): void {
		$this->db = Server::get(IDBConnection::class);
		$this->cleanUp();
		foreach (['A', 'B', 'C', 'D'] as $name) {
			$this->pools[$name] = $this->insert('learning_pools', [
				'user_id' => self::TEACHER,
				'name' => self::MARK . ' pool',
				'review_status' => 'published',
				'created_at' => time(),
				'updated_at' => time(),
			]);
		}
		$off = $this->course('{"training":false,"leitner":false,"exam":true}', self::TEACHER, [$this->pools['A'], $this->pools['B']]);
		$this->member($off, self::LEARNER, 'student');
		$on = $this->course('{"leitner":true}', self::TEACHER, [$this->pools['B']]);
		$this->member($on, self::LEARNER, 'student');
		$this->course('{"leitner":false}', self::LEARNER, [$this->pools['C']]);
		$this->course('{"leitner":false}', self::TEACHER, [$this->pools['D']]);
	}

	protected function tearDown(): void {
		$this->cleanUp();
	}

	public function testOnlyThePoolReachedSolelyThroughClosedCoursesIsClosed(): void {
		$service = Server::get(CourseService::class);

		$this->assertSame([$this->pools['A']], $service->getPoolIdsClosedForMode(self::LEARNER, 'leitner'));
		$this->assertSame([$this->pools['A']], $service->getPoolIdsClosedForMode(self::LEARNER, 'training'));
		$this->assertSame([], $service->getPoolIdsClosedForMode(self::LEARNER, 'exam'));
		$this->assertSame([], $service->getPoolIdsClosedForMode(self::LEARNER, 'leitner', [$this->pools['B'], $this->pools['C'], $this->pools['D']]));
		$this->assertTrue($service->isPoolModeClosedByCourses($this->pools['A'], self::LEARNER, 'leitner'));
		$this->assertFalse($service->isPoolModeClosedByCourses($this->pools['B'], self::LEARNER, 'leitner'));
	}

	// ─── helpers ─────────────────────────────────────────────────────────────

	/** @param int[] $poolIds */
	private function course(string $modeConfig, string $instructor, array $poolIds): int {
		$courseId = $this->insert('learning_courses', [
			'title' => self::MARK . ' course',
			'instructor_id' => $instructor,
			'mode_config' => $modeConfig,
			'created_at' => time(),
			'updated_at' => time(),
		]);
		foreach ($poolIds as $poolId) {
			$this->insert('learning_course_pools', ['course_id' => $courseId, 'pool_id' => $poolId]);
		}
		return $courseId;
	}

	private function member(int $courseId, string $user, string $role): void {
		$this->insert('learning_course_members', [
			'course_id' => $courseId,
			'user_id' => $user,
			'role' => $role,
			'enrolled_at' => time(),
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
			->where($courses->expr()->eq('title', $courses->createNamedParameter(self::MARK . ' course')));
		$courseIds = array_map('intval', $courses->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
		if ($courseIds !== []) {
			foreach (['learning_course_pools', 'learning_course_members'] as $table) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete($table)
					->where($qb->expr()->in('course_id', $qb->createNamedParameter($courseIds, IQueryBuilder::PARAM_INT_ARRAY)))
					->executeStatement();
			}
			$qb = $this->db->getQueryBuilder();
			$qb->delete('learning_courses')
				->where($qb->expr()->in('id', $qb->createNamedParameter($courseIds, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('learning_pools')
			->where($qb->expr()->eq('name', $qb->createNamedParameter(self::MARK . ' pool')))
			->executeStatement();
	}
}
