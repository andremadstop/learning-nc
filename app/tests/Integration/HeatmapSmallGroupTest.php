<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Service\CourseService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #44: "Show worst questions" opened nothing for a small group. A question needed five
 * answers, and a global LIMIT 50 cut across all chapters. Questions without a chapter from
 * different pools also fell into one nameless row. Runs against pgsql and mysql (GROUP BY rules).
 */
final class HeatmapSmallGroupTest extends TestCase {
	private const TEACHER = 'hm44-teacher';
	private const STUDENT = 'hm44-student';
	private const MARK = 'Heatmap 44';

	private IDBConnection $db;
	private int $courseId = 0;
	/** @var array<string, int> */
	private array $questions = [];
	/** @var array<string, int> */
	private array $pools = [];
	/** @var array<int, array{pool: int, right: int, wrong: int}> */
	private array $meta = [];

	protected function setUp(): void {
		$this->db = Server::get(IDBConnection::class);
		$this->cleanUp();
		$this->courseId = $this->insert('learning_courses', [
			'title' => self::MARK . ' course',
			'instructor_id' => self::TEACHER,
			'created_at' => time(),
			'updated_at' => time(),
		]);
		$this->insert('learning_course_members', ['course_id' => $this->courseId, 'user_id' => self::STUDENT, 'role' => 'student', 'enrolled_at' => time()]);
		foreach (['Brandschutz', 'Erste Hilfe'] as $name) {
			$this->pools[$name] = $this->insert('learning_pools', [
				'user_id' => self::TEACHER, 'name' => self::MARK . ' ' . $name, 'review_status' => 'published',
				'created_at' => time(), 'updated_at' => time(),
			]);
			$this->insert('learning_course_pools', ['course_id' => $this->courseId, 'pool_id' => $this->pools[$name]]);
			$this->questions[$name] = $this->question($this->pools[$name], null);
		}
		$this->questions['chapter'] = $this->question($this->pools['Brandschutz'], 'ch-44');

		// One learner: two wrong answers and one right one per question.
		foreach ($this->questions as $questionId) {
			$this->answer($questionId, false);
			$this->answer($questionId, false);
			$this->answer($questionId, true);
		}
	}

	protected function tearDown(): void {
		$this->cleanUp();
	}

	public function testOneLearnerWithTwoWrongAnswersShowsTheQuestionPerPool(): void {
		$rows = Server::get(CourseService::class)->getChapterHeatmap($this->courseId, self::TEACHER);
		$byKey = array_column($rows, null, 'chapter_key');

		$this->assertCount(3, $rows, 'one row per pool for chapter-less questions, one for the chapter');
		foreach (['Brandschutz', 'Erste Hilfe'] as $name) {
			$row = $byKey['pool:' . $this->pools[$name]] ?? null;
			$this->assertNotNull($row, $name);
			$this->assertSame(self::MARK . ' ' . $name, $row['chapter_title']);
			$this->assertTrue($row['is_pool']);
			$this->assertSame([$this->questions[$name]], array_column($row['top_wrong_questions'], 'id'));
			$this->assertSame(2, $row['top_wrong_questions'][0]['wrong_answers']);
			$this->assertSame(3, $row['top_wrong_questions'][0]['total_answers']);
		}
		$this->assertFalse($byKey['ch-44']['is_pool']);
		$this->assertSame([$this->questions['chapter']], array_column($byKey['ch-44']['top_wrong_questions'], 'id'));

		$weak = Server::get(CourseService::class)->getWeakQuestions($this->courseId, self::TEACHER, 100);
		$this->assertEqualsCanonicalizing(array_values($this->questions), array_column($weak, 'question_id'));
	}

	// ─── helpers ─────────────────────────────────────────────────────────────

	private function question(int $poolId, ?string $chapter): int {
		$values = [
			'pool_id' => $poolId, 'user_id' => self::TEACHER, 'text' => self::MARK . ' question',
			'review_status' => 'published', 'created_at' => time(), 'updated_at' => time(),
		];
		if ($chapter !== null) {
			$values['chapter_key'] = $chapter;
			$values['chapter_title'] = 'Kapitel 44';
		}
		$id = $this->insert('learning_questions', $values);
		$this->meta[$id] = [
			'pool' => $poolId,
			'right' => $this->insert('learning_answers', ['question_id' => $id, 'text' => 'right', 'is_correct' => true]),
			'wrong' => $this->insert('learning_answers', ['question_id' => $id, 'text' => 'wrong', 'is_correct' => false]),
		];
		return $id;
	}

	private function answer(int $questionId, bool $correct): void {
		$now = time();
		$sessionId = $this->insert('learning_sessions', [
			'pool_id' => $this->meta[$questionId]['pool'], 'user_id' => self::STUDENT, 'mode' => 'training',
			'started_at' => $now - 600, 'completed_at' => $now - 60,
			'total_questions' => 1, 'correct_answers' => $correct ? 1 : 0,
		]);
		$this->insert('learning_user_answers', [
			'session_id' => $sessionId, 'question_id' => $questionId, 'answer_id' => $this->meta[$questionId][$correct ? 'right' : 'wrong'],
			'is_correct' => $correct, 'answered_at' => $now - 300,
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
		$sessions = $this->db->getQueryBuilder();
		$sessions->select('id')->from('learning_sessions')
			->where($sessions->expr()->eq('user_id', $sessions->createNamedParameter(self::STUDENT)));
		$ids = array_map('intval', $sessions->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
		if ($ids !== []) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('learning_user_answers')
				->where($qb->expr()->in('session_id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
			$qb = $this->db->getQueryBuilder();
			$qb->delete('learning_sessions')
				->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
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
