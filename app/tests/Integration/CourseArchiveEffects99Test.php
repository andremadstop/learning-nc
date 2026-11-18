<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\IcsService;
use OCA\Learning\Service\ReminderService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #99: what archiving a course does, on a real database. The archive dialog and the note
 * on an archived course (CourseArchiveExplanation.vue) say exactly this:
 *   - no exam date reminder and no exam countdown on the Dashboard widget any more,
 *   - learners keep the course in their list, enrolment still works, due Leitner reviews stay in
 *     the calendar feed, the instructor can still edit the course and activate it again.
 * Training, exams and certificates ignore the status altogether; the source guard
 * tests/Unit/Service/CourseArchiveReadersInventoryTest.php holds that.
 */
final class CourseArchiveEffects99Test extends TestCase {
    private const TITLE = 'archive-effects-99-it';
    private const OWNER = 'c99-owner';
    private const CO = 'c99-co';
    private const STUDENT = 'c99-student';
    private const LATECOMER = 'c99-latecomer';

    private IDBConnection $db;
    private CourseService $courses;
    private int $courseId;
    private int $poolId;
    private \DateTimeImmutable $today;

    protected function setUp(): void {
        $this->db = Server::get(IDBConnection::class);
        $this->courses = Server::get(CourseService::class);
        $this->cleanUp();
        $this->today = new \DateTimeImmutable('today');
        $this->courseId = $this->seedCourse($this->today->modify('+3 days')->format('Y-m-d') . 'T09:00');
    }

    protected function tearDown(): void {
        $this->cleanUp();
    }

    public function testArchivingStopsExamDateRemindersAndTheDashboardCountdown(): void {
        $this->assertTrue($this->hasExamReminder(), 'precondition: an active course reminds of its exam date');
        $this->assertTrue($this->inExamCountdown(), 'precondition: an active course is in the widget countdown');

        $this->courses->update($this->courseId, null, null, 'archived', self::OWNER);

        $this->assertFalse($this->hasExamReminder(), 'an archived course sends no exam date reminder');
        $this->assertFalse($this->inExamCountdown(), 'an archived course leaves the Dashboard countdown');
    }

    public function testLearnersKeepTheCourseAndTheCalendarFeed(): void {
        $this->courses->update($this->courseId, null, null, 'archived', self::OWNER);

        $listed = array_values(array_filter(
            $this->courses->findAll(self::STUDENT)['enrolled'] ?? [],
            fn (array $c): bool => (int)$c['id'] === $this->courseId
        ));
        $this->assertCount(1, $listed, 'the learner still sees the archived course in the list');
        $this->assertSame('archived', $listed[0]['status']);

        $ics = Server::get(IcsService::class)->renderCalendarForUser(self::STUDENT);
        $this->assertStringContainsString(self::TITLE, $ics, 'due Leitner reviews of an archived course stay in the calendar feed');
    }

    public function testEnrolmentEditingAndReactivationStillWork(): void {
        $this->courses->update($this->courseId, null, null, 'archived', self::OWNER);

        $member = $this->courses->enroll($this->courseId, self::LATECOMER);
        $this->assertSame('student', $member->getRole(), 'enrolling into an archived course still works');

        $edited = $this->courses->update($this->courseId, self::TITLE, 'neu beschrieben', null, self::CO);
        $this->assertSame('archived', $edited->getStatus(), 'a co-instructor edits an archived course, it stays archived');
        $this->assertSame('neu beschrieben', $edited->getDescription());

        $active = $this->courses->update($this->courseId, null, null, 'active', self::OWNER);
        $this->assertSame('active', $active->getStatus(), 'the course can be activated again');
        $this->assertTrue($this->hasExamReminder(), 'reactivated, it reminds of the exam date again');
    }

    private function hasExamReminder(): bool {
        $method = new \ReflectionMethod(ReminderService::class, 'getExamReminderTargets');
        foreach ($method->invoke(Server::get(ReminderService::class), $this->today) as $target) {
            if ($target['course_id'] === $this->courseId && $target['user_id'] === self::STUDENT) {
                return true;
            }
        }
        return false;
    }

    private function inExamCountdown(): bool {
        foreach (Server::get(CourseMapper::class)->findStudentExamCourses(self::STUDENT) as $row) {
            if ((int)$row['id'] === $this->courseId) {
                return true;
            }
        }
        return false;
    }

    private function seedCourse(string $examDate): int {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_courses')->values([
            'title' => $qb->createNamedParameter(self::TITLE),
            'instructor_id' => $qb->createNamedParameter(self::OWNER),
            'status' => $qb->createNamedParameter('active'),
            'exam_date' => $qb->createNamedParameter($examDate),
            'created_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
            'updated_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
        ])->executeStatement();
        $courseId = $qb->getLastInsertId();

        foreach ([self::STUDENT => 'student', self::CO => 'instructor'] as $uid => $role) {
            $m = $this->db->getQueryBuilder();
            $m->insert('learning_course_members')->values([
                'course_id' => $m->createNamedParameter($courseId, IQueryBuilder::PARAM_INT),
                'user_id' => $m->createNamedParameter($uid),
                'role' => $m->createNamedParameter($role),
                'enrolled_at' => $m->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
            ])->executeStatement();
        }

        $pool = $this->db->getQueryBuilder();
        $pool->insert('learning_pools')->values([
            'user_id' => $pool->createNamedParameter(self::OWNER),
            'name' => $pool->createNamedParameter(self::TITLE),
            'created_at' => $pool->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
            'updated_at' => $pool->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
        ])->executeStatement();
        $this->poolId = $pool->getLastInsertId();

        $cp = $this->db->getQueryBuilder();
        $cp->insert('learning_course_pools')->values([
            'course_id' => $cp->createNamedParameter($courseId, IQueryBuilder::PARAM_INT),
            'pool_id' => $cp->createNamedParameter($this->poolId, IQueryBuilder::PARAM_INT),
        ])->executeStatement();

        $q = $this->db->getQueryBuilder();
        $q->insert('learning_questions')->values([
            'pool_id' => $q->createNamedParameter($this->poolId, IQueryBuilder::PARAM_INT),
            'user_id' => $q->createNamedParameter(self::OWNER),
            'text' => $q->createNamedParameter('archive effects question'),
            'created_at' => $q->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
            'updated_at' => $q->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
        ])->executeStatement();
        $questionId = $q->getLastInsertId();

        $li = $this->db->getQueryBuilder();
        $li->insert('learning_leitner_items')->values([
            'user_id' => $li->createNamedParameter(self::STUDENT),
            'pool_id' => $li->createNamedParameter($this->poolId, IQueryBuilder::PARAM_INT),
            'question_id' => $li->createNamedParameter($questionId, IQueryBuilder::PARAM_INT),
            'box' => $li->createNamedParameter(2, IQueryBuilder::PARAM_INT),
            'next_review' => $li->createNamedParameter(time() - 60, IQueryBuilder::PARAM_INT),
        ])->executeStatement();

        return $courseId;
    }

    private function cleanUp(): void {
        $ids = $this->db->getQueryBuilder();
        $ids->select('id')->from('learning_courses')
            ->where($ids->expr()->eq('title', $ids->createNamedParameter(self::TITLE)));
        $result = $ids->executeQuery();
        $courseIds = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
        $result->closeCursor();
        foreach (['learning_course_members' => 'course_id', 'learning_course_pools' => 'course_id', 'learning_courses' => 'id'] as $table => $col) {
            if ($courseIds === []) {
                break;
            }
            $qb = $this->db->getQueryBuilder();
            $qb->delete($table)
                ->where($qb->expr()->in($col, $qb->createNamedParameter($courseIds, IQueryBuilder::PARAM_INT_ARRAY)))
                ->executeStatement();
        }
        $li = $this->db->getQueryBuilder();
        $li->delete('learning_leitner_items')
            ->where($li->expr()->eq('user_id', $li->createNamedParameter(self::STUDENT)))
            ->executeStatement();
        $questions = $this->db->getQueryBuilder();
        $questions->delete('learning_questions')
            ->where($questions->expr()->eq('user_id', $questions->createNamedParameter(self::OWNER)))
            ->executeStatement();
        $pools = $this->db->getQueryBuilder();
        $pools->delete('learning_pools')
            ->where($pools->expr()->eq('name', $pools->createNamedParameter(self::TITLE)))
            ->andWhere($pools->expr()->eq('user_id', $pools->createNamedParameter(self::OWNER)))
            ->executeStatement();
    }
}
