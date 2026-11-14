<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\StreakService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Audit #50: one pool in two courses. The dashboard, the heatmap's top questions and the weak
 * questions of course A counted the answers of course B's learners, and recent_activity handed
 * their user IDs to A's instructor.
 *
 * Audit #156: an instructor of the course (role 'instructor', co-instructors included) answering
 * wrong must not show up in the heatmap's top questions either, the same population as the rows.
 *
 * Audit #30: streak days came from SQL date functions that render in the connection's time zone
 * and were then compared with UTC days.
 *
 * Runs against the real database (pgsql and mysql): both are SQL behaviour.
 */
final class CourseFiguresScopeTest extends TestCase {
	private const TEACHER = 'scope-teacher';
	private const STUDENT_A = 'scope-student-a';
	private const STUDENT_B = 'scope-student-b';
	private const CO_INSTRUCTOR = 'scope-co-instructor';
	private const MARK = 'Scope audit 50';

	private IDBConnection $db;
	private int $courseA = 0;
	private int $questionId = 0;
	private int $poolId = 0;

	protected function setUp(): void {
		$this->db = Server::get(IDBConnection::class);
		$this->cleanUp();

		$poolId = $this->poolId = $this->insert('learning_pools', [
			'user_id' => self::TEACHER,
			'name' => self::MARK . ' pool',
			'review_status' => 'published',
			'created_at' => time(),
			'updated_at' => time(),
		]);
		$this->questionId = $this->insert('learning_questions', [
			'pool_id' => $poolId,
			'user_id' => self::TEACHER,
			'text' => self::MARK . ' question',
			'chapter_key' => 'scope-ch',
			'chapter_title' => 'Scope chapter',
			'review_status' => 'published',
			'created_at' => time(),
			'updated_at' => time(),
		]);
		$right = $this->insert('learning_answers', ['question_id' => $this->questionId, 'text' => 'right', 'is_correct' => true]);
		$wrong = $this->insert('learning_answers', ['question_id' => $this->questionId, 'text' => 'wrong', 'is_correct' => false]);

		$this->courseA = $this->course($poolId, self::STUDENT_A);
		$this->course($poolId, self::STUDENT_B);

		// A's student answers right five times, B's student wrong five times
		$this->sessionWithAnswers($poolId, self::STUDENT_A, $right, true);
		$this->sessionWithAnswers($poolId, self::STUDENT_B, $wrong, false);

		// A co-instructor of course A answers wrong five times: never counted (audit #156)
		$this->insert('learning_course_members', [
			'course_id' => $this->courseA,
			'user_id' => self::CO_INSTRUCTOR,
			'role' => 'instructor',
			'enrolled_at' => time(),
		]);
		$this->sessionWithAnswers($poolId, self::CO_INSTRUCTOR, $wrong, false);
	}

	protected function tearDown(): void {
		$this->resetTimeZone();
		$this->cleanUp();
	}

	public function testDashboardCountsOnlyTheCoursesStudents(): void {
		$dashboard = Server::get(CourseService::class)->getCourseDashboard($this->courseA, self::TEACHER);

		$this->assertSame(1, $dashboard['active_learners_7d']);
		$this->assertSame(5, $dashboard['new_answers_7d']);
		$this->assertEquals(100.0, $dashboard['avg_success_rate_7d']);
		$this->assertSame([self::STUDENT_A], array_values(array_unique(array_column($dashboard['recent_activity'], 'user_id'))));
	}

	public function testWeakQuestionsAndHeatmapCountOnlyTheCoursesStudents(): void {
		$service = Server::get(CourseService::class);

		$weak = array_values(array_filter(
			$service->getWeakQuestions($this->courseA, self::TEACHER, 100),
			fn (array $row) => $row['question_id'] === $this->questionId
		));
		$this->assertCount(1, $weak);
		$this->assertSame(5, $weak[0]['total_answers']);
		$this->assertEquals(0.0, $weak[0]['wrong_rate']);

		$chapters = array_values(array_filter(
			$service->getChapterHeatmap($this->courseA, self::TEACHER),
			fn (array $row) => $row['chapter_key'] === 'scope-ch'
		));
		$this->assertCount(1, $chapters);
		$this->assertSame(5, $chapters[0]['total_answers']);
		$this->assertCount(1, $chapters[0]['top_wrong_questions']);
		$this->assertEquals(0.0, $chapters[0]['top_wrong_questions'][0]['wrong_rate']);
	}

	public function testStreakDaysAreUtcWhateverTheConnectionTimeZone(): void {
		// 20:30 UTC yesterday is already today at UTC+5
		$todayStart = (int)strtotime(gmdate('Y-m-d') . ' 00:00:00 UTC');
		$completedAt = $todayStart - 3 * 3600 - 1800;
		$this->sessionAt(self::STUDENT_A . '-streak', $completedAt);

		$this->setTimeZone();
		$streak = Server::get(StreakService::class)->getStreak(self::STUDENT_A . '-streak');

		$this->assertSame(gmdate('Y-m-d', $completedAt), $streak['last_activity_date']);
		$this->assertFalse($streak['is_active_today']);
		$this->assertSame(1, $streak['current_streak']);
	}

	// ─── helpers ─────────────────────────────────────────────────────────────

	private function setTimeZone(): void {
		if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_POSTGRES) {
			$this->db->executeStatement("SET TIME ZONE 'Asia/Karachi'");
		} else {
			$this->db->executeStatement("SET time_zone = '+05:00'");
		}
	}

	private function resetTimeZone(): void {
		if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_POSTGRES) {
			$this->db->executeStatement('SET TIME ZONE DEFAULT');
		} else {
			$this->db->executeStatement('SET time_zone = @@global.time_zone');
		}
	}

	private function course(int $poolId, string $student): int {
		$courseId = $this->insert('learning_courses', [
			'title' => self::MARK . ' course ' . $student,
			'instructor_id' => self::TEACHER,
			'created_at' => time(),
			'updated_at' => time(),
		]);
		$this->insert('learning_course_pools', ['course_id' => $courseId, 'pool_id' => $poolId]);
		$this->insert('learning_course_members', [
			'course_id' => $courseId,
			'user_id' => $student,
			'role' => 'student',
			'enrolled_at' => time(),
		]);
		return $courseId;
	}

	/** Five sessions with one answer each: (session_id, question_id) is unique */
	private function sessionWithAnswers(int $poolId, string $user, int $answerId, bool $correct): void {
		$now = time();
		for ($i = 0; $i < 5; $i++) {
			$sessionId = $this->insert('learning_sessions', [
				'pool_id' => $poolId,
				'user_id' => $user,
				'mode' => 'training',
				'started_at' => $now - 600 + $i,
				'completed_at' => $now - 60 + $i,
				'total_questions' => 1,
				'correct_answers' => $correct ? 1 : 0,
			]);
			$this->insert('learning_user_answers', [
				'session_id' => $sessionId,
				'question_id' => $this->questionId,
				'answer_id' => $answerId,
				'is_correct' => $correct,
				'answered_at' => $now - 300 + $i,
			]);
		}
	}

	private function sessionAt(string $user, int $completedAt): void {
		$this->insert('learning_sessions', [
			'pool_id' => $this->poolId,
			'user_id' => $user,
			'mode' => 'training',
			'started_at' => $completedAt - 60,
			'completed_at' => $completedAt,
			'total_questions' => 1,
			'correct_answers' => 1,
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
		$users = [self::STUDENT_A, self::STUDENT_B, self::CO_INSTRUCTOR, self::STUDENT_A . '-streak'];

		$sessions = $this->db->getQueryBuilder();
		$sessions->select('id')->from('learning_sessions')
			->where($sessions->expr()->in('user_id', $sessions->createNamedParameter($users, IQueryBuilder::PARAM_STR_ARRAY)));
		$ids = array_map('intval', $sessions->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
		if ($ids !== []) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('learning_user_answers')
				->where($qb->expr()->in('session_id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete('learning_sessions')
			->where($qb->expr()->in('user_id', $qb->createNamedParameter($users, IQueryBuilder::PARAM_STR_ARRAY)))
			->executeStatement();

		$courses = $this->db->getQueryBuilder();
		$courses->select('id')->from('learning_courses')
			->where($courses->expr()->like('title', $courses->createNamedParameter(self::MARK . '%')));
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

		$pools = $this->db->getQueryBuilder();
		$pools->select('id')->from('learning_pools')
			->where($pools->expr()->eq('name', $pools->createNamedParameter(self::MARK . ' pool')));
		$poolIds = array_map('intval', $pools->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
		if ($poolIds !== []) {
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
}
