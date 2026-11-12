<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Service\ReminderService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * 2026-11 audit S03: the course tab saves the exam date from <input type=datetime-local>
 * ('YYYY-MM-DDTHH:MM'), the reminder job selected courses with exam_date IN (plain dates) and
 * never found one. Runs against the real database, because the bug lives in the WHERE clause.
 */
final class ExamReminderTargetsTest extends TestCase {
	private const TITLE = 'exam-reminder-targets-it';

	private IDBConnection $db;
	/** @var list<int> */
	private array $courseIds = [];

	protected function setUp(): void {
		$this->db = Server::get(IDBConnection::class);
		$this->cleanUp();
	}

	protected function tearDown(): void {
		$this->cleanUp();
	}

	public function testDateTimeAndLegacyDateExamDatesAreFound(): void {
		$today = new \DateTimeImmutable('today');
		$withTime = $this->insertCourse($today->modify('+3 days')->format('Y-m-d') . 'T09:00');
		$legacy = $this->insertCourse($today->modify('+7 days')->format('Y-m-d'));
		$notDue = $this->insertCourse($today->modify('+5 days')->format('Y-m-d') . 'T09:00');

		$method = new \ReflectionMethod(ReminderService::class, 'getExamReminderTargets');
		$targets = $method->invoke(Server::get(ReminderService::class), $today);

		$byCourse = [];
		foreach ($targets as $target) {
			if (in_array($target['course_id'], $this->courseIds, true)) {
				$byCourse[$target['course_id']] = $target;
			}
		}

		$this->assertArrayHasKey($withTime, $byCourse, 'an exam date with a time must be found');
		$this->assertSame(3, $byCourse[$withTime]['days_until_exam']);
		$this->assertSame($today->modify('+3 days')->format('Y-m-d'), $byCourse[$withTime]['exam_date']);
		$this->assertArrayHasKey($legacy, $byCourse, 'a legacy date-only exam date must still be found');
		$this->assertSame(7, $byCourse[$legacy]['days_until_exam']);
		$this->assertArrayNotHasKey($notDue, $byCourse, 'five days ahead is no reminder mark');
	}

	private function insertCourse(string $examDate): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('learning_courses')->values([
			'title' => $qb->createNamedParameter(self::TITLE),
			'instructor_id' => $qb->createNamedParameter('exam-reminder-teacher'),
			'status' => $qb->createNamedParameter('active'),
			'exam_date' => $qb->createNamedParameter($examDate),
			'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
			'updated_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
		])->executeStatement();
		$courseId = $qb->getLastInsertId();

		$member = $this->db->getQueryBuilder();
		$member->insert('learning_course_members')->values([
			'course_id' => $member->createNamedParameter($courseId, IQueryBuilder::PARAM_INT),
			'user_id' => $member->createNamedParameter('exam-reminder-student'),
			'role' => $member->createNamedParameter('student'),
			'enrolled_at' => $member->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
		])->executeStatement();

		$this->courseIds[] = $courseId;
		return $courseId;
	}

	private function cleanUp(): void {
		$ids = $this->db->getQueryBuilder();
		$ids->select('id')->from('learning_courses')
			->where($ids->expr()->eq('title', $ids->createNamedParameter(self::TITLE)));
		$result = $ids->executeQuery();
		$courseIds = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();
		if ($courseIds === []) {
			return;
		}
		$members = $this->db->getQueryBuilder();
		$members->delete('learning_course_members')
			->where($members->expr()->in('course_id', $members->createNamedParameter($courseIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeStatement();
		$courses = $this->db->getQueryBuilder();
		$courses->delete('learning_courses')
			->where($courses->expr()->in('id', $courses->createNamedParameter($courseIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeStatement();
	}
}
