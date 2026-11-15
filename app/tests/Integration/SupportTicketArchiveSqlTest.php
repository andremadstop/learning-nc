<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Db\SupportTicket;
use OCA\Learning\Db\SupportTicketMapper;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #59: the inbox leaves archived questions out, the archive lists only those, the badge
 * counts unanswered questions, and a learner's withdrawal deletes only their own question while
 * it has no answer. Runs against the real database, because all of it lives in the SQL.
 */
final class SupportTicketArchiveSqlTest extends TestCase {
	private const COURSE_ID = 990591;
	private const OTHER_COURSE_ID = 990592;

	private SupportTicketMapper $mapper;

	protected function setUp(): void {
		$this->mapper = Server::get(SupportTicketMapper::class);
		$this->deleteRows();
	}

	protected function tearDown(): void {
		$this->deleteRows();
	}

	public function testInboxArchiveAndCountAreKeptApart(): void {
		$open = $this->insert('learner', 'open');
		$answered = $this->insert('learner', 'answered', 'Bis Freitag.');
		$archived = $this->insert('learner', 'archived');
		$this->insert('learner', 'open', null, self::OTHER_COURSE_ID);

		$inbox = array_map(static fn (SupportTicket $t): int => (int)$t->getId(), $this->mapper->findByInstructorCourse(self::COURSE_ID, 50));
		sort($inbox);
		$this->assertSame([$open, $answered], $inbox);

		$archive = array_map(static fn (SupportTicket $t): int => (int)$t->getId(), $this->mapper->findByInstructorCourse(self::COURSE_ID, 50, true));
		$this->assertSame([$archived], $archive);

		$this->assertSame(1, $this->mapper->countOpenByInstructorCourse(self::COURSE_ID));
	}

	public function testWithdrawDeletesOnlyTheOwnUnansweredQuestion(): void {
		$own = $this->insert('learner', 'open');
		$ownArchived = $this->insert('learner', 'archived');
		$answered = $this->insert('learner', 'answered', 'Bis Freitag.');
		$foreign = $this->insert('other', 'open');

		$this->assertFalse($this->mapper->deleteOpenOwnQuestion($answered, 'learner', self::COURSE_ID));
		$this->assertFalse($this->mapper->deleteOpenOwnQuestion($foreign, 'learner', self::COURSE_ID));
		$this->assertFalse($this->mapper->deleteOpenOwnQuestion($own, 'learner', self::OTHER_COURSE_ID));
		$this->assertTrue($this->mapper->deleteOpenOwnQuestion($own, 'learner', self::COURSE_ID));
		$this->assertTrue($this->mapper->deleteOpenOwnQuestion($ownArchived, 'learner', self::COURSE_ID));

		$left = array_map(static fn (SupportTicket $t): int => (int)$t->getId(), $this->mapper->findByInstructorCourse(self::COURSE_ID, 50));
		sort($left);
		$this->assertSame([$answered, $foreign], $left);
	}

	private function insert(string $userId, string $status, ?string $answer = null, int $courseId = self::COURSE_ID): int {
		$ticket = new SupportTicket();
		$ticket->setUserId($userId);
		$ticket->setStatus($status);
		$ticket->setSubject('Frist');
		$ticket->setMessage('Bis wann?');
		$ticket->setContextJson('{}');
		$ticket->setCourseId($courseId);
		$ticket->setCreatedAt(time());
		$ticket->setUpdatedAt(time());
		$ticket->setAnswerText($answer);
		$ticket->setCategory('course_content');
		$ticket->setRoutingTargetType('course_instructor');
		$ticket->setRoutingCourseId($courseId);
		$ticket->setNeedsReview(false);
		return (int)$this->mapper->insert($ticket)->getId();
	}

	private function deleteRows(): void {
		$db = Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->delete('learning_support_tickets')
			->where($qb->expr()->in('routing_course_id', $qb->createNamedParameter([self::COURSE_ID, self::OTHER_COURSE_ID], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
		$qb->executeStatement();
	}
}
