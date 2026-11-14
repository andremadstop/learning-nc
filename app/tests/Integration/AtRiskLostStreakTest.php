<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Service\CourseService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Audit #208: the "lost streak" signal of the at-risk list now reads longest_streak from the
 * batch stats query instead of one query per student. This pins the signal on a real database:
 * a student whose streak was 9 and is 0 gets "Streak verloren (war 9)", one whose best was 5
 * does not.
 */
final class AtRiskLostStreakTest extends TestCase {
    private IDBConnection $db;
    private int $courseId = 0;

    protected function setUp(): void {
        $this->db = Server::get(IDBConnection::class);
    }

    protected function tearDown(): void {
        foreach (['learning_courses' => 'id', 'learning_course_members' => 'course_id'] as $table => $col) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($table)->where($qb->expr()->eq($col, $qb->createNamedParameter($this->courseId, IQueryBuilder::PARAM_INT)))->executeStatement();
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete('learning_user_stats')
            ->where($qb->expr()->in('user_id', $qb->createNamedParameter(['r208-lost', 'r208-short'], IQueryBuilder::PARAM_STR_ARRAY)))
            ->executeStatement();
    }

    public function testLostStreakComesFromTheBatchStats(): void {
        $course = new Course();
        $course->setTitle('Audit 208');
        $course->setInstructorId('r208-teacher');
        $course->setStatus('active');
        $course->setCreatedAt(1);
        $course->setUpdatedAt(1);
        $this->courseId = Server::get(CourseMapper::class)->insert($course)->getId();

        foreach (['r208-lost' => 9, 'r208-short' => 5] as $uid => $longest) {
            $this->insert('learning_course_members', ['course_id' => $this->courseId, 'user_id' => $uid, 'role' => 'student', 'enrolled_at' => 1]);
            $this->insert('learning_user_stats', ['user_id' => $uid, 'current_streak' => 0, 'longest_streak' => $longest, 'updated_at' => 1]);
        }

        $reasons = [];
        foreach (Server::get(CourseService::class)->getAtRiskStudents($this->courseId, 'r208-teacher')['at_risk'] as $row) {
            $reasons[$row['user_id']] = $row['risk_reasons'];
        }

        $this->assertContains('Streak verloren (war 9)', $reasons['r208-lost'] ?? []);
        $this->assertSame([], preg_grep('/^Streak verloren/', $reasons['r208-short'] ?? []));
    }

    /** @param array<string, string|int> $values */
    private function insert(string $table, array $values): void {
        $qb = $this->db->getQueryBuilder();
        $qb->insert($table);
        foreach ($values as $column => $value) {
            $qb->setValue($column, $qb->createNamedParameter($value, is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR));
        }
        $qb->executeStatement();
    }
}
