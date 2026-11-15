<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Service\CourseService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #68 on a real database and the real DI container (CourseService now takes
 * PassCriteriaService and AssignmentService): in a certifying course without Leitner a learner
 * who passed the exam of the required pool shows that exam, not 0 %, is not at risk, and the
 * pool's statistics carry the learners' answers. Runs against pgsql and mysql.
 */
final class LeitnerOff68Test extends TestCase {
	private const TEACHER = 'lo68-teacher';
	private const PASSED = 'lo68-passed';
	private const IDLE = 'lo68-idle';
	private const MARK = 'LeitnerOff 68';

	private IDBConnection $db;
	private int $courseId = 0;
	private int $poolId = 0;

	protected function setUp(): void {
		$this->db = Server::get(IDBConnection::class);
		$this->cleanUp();
		$this->poolId = $this->insert('learning_pools', [
			'user_id' => self::TEACHER, 'name' => self::MARK . ' Brandschutz', 'review_status' => 'published',
			'created_at' => time(), 'updated_at' => time(),
		]);
		$this->courseId = $this->insert('learning_courses', [
			'title' => self::MARK . ' course',
			'instructor_id' => self::TEACHER,
			'created_at' => time(),
			'updated_at' => time(),
			'mode_config' => (string)json_encode(['leitner' => false]),
			'cert_enabled' => true,
			'cert_pass_percent' => 80,
			'cert_required_pool_ids' => (string)json_encode([$this->poolId]),
		]);
		$this->insert('learning_course_pools', ['course_id' => $this->courseId, 'pool_id' => $this->poolId]);
		foreach ([self::PASSED, self::IDLE] as $uid) {
			$this->insert('learning_course_members', ['course_id' => $this->courseId, 'user_id' => $uid, 'role' => 'student', 'enrolled_at' => time()]);
		}

		$questions = [];
		for ($i = 0; $i < 2; $i++) {
			$qid = $this->insert('learning_questions', [
				'pool_id' => $this->poolId, 'user_id' => self::TEACHER, 'text' => self::MARK . ' question ' . $i,
				'review_status' => 'published', 'created_at' => time(), 'updated_at' => time(),
			]);
			$questions[$qid] = [
				'right' => $this->insert('learning_answers', ['question_id' => $qid, 'text' => 'right', 'is_correct' => true]),
				'wrong' => $this->insert('learning_answers', ['question_id' => $qid, 'text' => 'wrong', 'is_correct' => false]),
			];
		}
		// The counting exam: 9 of 10 on the required pool, completed an hour ago.
		$now = time();
		$sessionId = $this->insert('learning_sessions', [
			'pool_id' => $this->poolId, 'course_id' => $this->courseId, 'user_id' => self::PASSED, 'mode' => 'exam',
			'started_at' => $now - 3600, 'completed_at' => $now - 3000,
			'total_questions' => 10, 'correct_answers' => 9,
		]);
		// One answer per session and question (learn_ua_sq_uniq): the exam and a practice round
		// each answer the first question wrong and the second right.
		$practiceId = $this->insert('learning_sessions', [
			'pool_id' => $this->poolId, 'user_id' => self::PASSED, 'mode' => 'training',
			'started_at' => $now - 2000, 'completed_at' => $now - 1900,
			'total_questions' => 2, 'correct_answers' => 1,
		]);
		$first = array_key_first($questions);
		foreach ([$sessionId, $practiceId] as $sid) {
			foreach ($questions as $qid => $answers) {
				$correct = $qid !== $first;
				$this->insert('learning_user_answers', [
					'session_id' => $sid, 'question_id' => $qid, 'answer_id' => $answers[$correct ? 'right' : 'wrong'],
					'is_correct' => $correct, 'answered_at' => $now - 1950,
				]);
			}
		}
	}

	protected function tearDown(): void {
		$this->cleanUp();
	}

	public function testThePassedLearnerShowsTheExamAndIsNotAtRisk(): void {
		$service = Server::get(CourseService::class);
		$progress = $service->getCourseProgress($this->courseId, self::TEACHER);
		$this->assertSame('exams', $progress['meta']['progress_basis']);
		$rows = array_column($progress['students'], null, 'user_id');

		$pool = $rows[self::PASSED]['pools'][0];
		$this->assertSame('exam', $pool['metric']);
		$this->assertSame(90, $pool['value']);
		$this->assertTrue($pool['exam_passed']);
		$this->assertTrue($rows[self::PASSED]['overall_passed']);
		$this->assertSame(90, $rows[self::PASSED]['overall_mastery']);
		$this->assertFalse($rows[self::IDLE]['overall_passed']);
		$this->assertNull($rows[self::IDLE]['pools'][0]['value']);

		$atRisk = $service->getAtRiskStudents($this->courseId, self::TEACHER);
		$this->assertSame('exams', $atRisk['basis']);
		$this->assertSame([self::IDLE], array_column($atRisk['at_risk'], 'user_id'), 'never active and not passed; the passed learner is not listed');
		$this->assertSame(['not_passed', 'never_active'], array_column($atRisk['at_risk'][0]['risk_reason_codes'], 'code'));
	}

	public function testThePoolStatisticsCarryTheAnswersOfTheLearners(): void {
		$stats = Server::get(CourseService::class)->getPoolAnswerStats($this->poolId, self::TEACHER);
		$this->assertFalse($stats['leitner_in_use']);
		$this->assertSame(2, $stats['question_count']);
		$this->assertSame(1, $stats['learners']);
		$this->assertSame(2, $stats['attempts']);
		$this->assertSame(4, $stats['answers']);
		$this->assertSame(50, $stats['accuracy']);
		$this->assertCount(1, $stats['hardest']);
		$this->assertSame(100.0, (float)$stats['hardest'][0]['wrong_rate']);
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
		$sessions = $this->db->getQueryBuilder();
		$sessions->select('id')->from('learning_sessions')
			->where($sessions->expr()->in('user_id', $sessions->createNamedParameter([self::PASSED, self::IDLE], IQueryBuilder::PARAM_STR_ARRAY)));
		$ids = array_map('intval', $sessions->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
		if ($ids !== []) {
			foreach (['learning_user_answers' => 'session_id', 'learning_sessions' => 'id'] as $table => $column) {
				$qb = $this->db->getQueryBuilder();
				$qb->delete($table)
					->where($qb->expr()->in($column, $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
					->executeStatement();
			}
		}
		$courses = $this->db->getQueryBuilder();
		$courses->select('id')->from('learning_courses')
			->where($courses->expr()->eq('title', $courses->createNamedParameter(self::MARK . ' course')));
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
