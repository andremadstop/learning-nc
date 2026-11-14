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
 * Audit #155: deleting a course removed only the pool links and the members. Announcements,
 * team-lead scopes, feed, kudos, videos and their progress stayed behind with the old course_id.
 * Assignments stay as pseudonymous evidence with a closed period, a student's summary snapshot
 * stays readable. Another course's rows are untouched. Runs on PostgreSQL and MariaDB
 * (scripts/nc-integration.sh).
 */
final class CourseDeleteTest extends TestCase {
    private const OWNER = 'c155-owner';

    private IDBConnection $db;
    /** @var list<int> */
    private array $courseIds = [];

    protected function setUp(): void {
        $this->db = Server::get(IDBConnection::class);
    }

    protected function tearDown(): void {
        foreach ($this->courseIds as $id) {
            foreach (['learning_courses' => 'id', 'learning_course_members' => 'course_id', 'learning_course_announcements' => 'course_id',
                'learning_course_videos' => 'course_id', 'learning_kudos' => 'course_id', 'learning_oversight' => 'course_id',
                'learning_feed_items' => 'course_id', 'learning_assignments' => 'course_id', 'learning_course_snapshots' => 'course_id'] as $table => $col) {
                $qb = $this->db->getQueryBuilder();
                $qb->delete($table)->where($qb->expr()->eq($col, $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))->executeStatement();
            }
        }
        $qb = $this->db->getQueryBuilder();
        $qb->delete('learning_video_progress')->where($qb->expr()->eq('user_id', $qb->createNamedParameter('c155-student')))->executeStatement();
    }

    public function testDeletingACourseTakesItsCourseScopedRowsAlong(): void {
        $gone = $this->seedCourse();
        $kept = $this->seedCourse();

        Server::get(CourseService::class)->delete($gone['course'], self::OWNER);

        $this->assertSame(0, $this->countRows('learning_courses', 'id', $gone['course']));
        foreach (['learning_course_members', 'learning_course_announcements', 'learning_course_videos',
            'learning_kudos', 'learning_oversight', 'learning_feed_items'] as $table) {
            $this->assertSame(0, $this->countRows($table, 'course_id', $gone['course']), "$table of the deleted course");
            $this->assertSame(1, $this->countRows($table, 'course_id', $kept['course']), "$table of the other course");
        }
        $this->assertSame(0, $this->countRows('learning_video_progress', 'content_id', $gone['video']), 'progress on its videos');
        $this->assertSame(1, $this->countRows('learning_video_progress', 'content_id', $kept['video']));

        $this->assertSame(1, $this->countRows('learning_assignments', 'course_id', $gone['course']), 'the assignment stays as evidence');
        $this->assertSame(0, $this->countRows('learning_assignments', 'active_period_key', $gone['course'] . ':user:c155-student'), '... with its period closed');
        $this->assertSame(1, $this->countRows('learning_assignments', 'active_period_key', $kept['course'] . ':user:c155-student'));
        $this->assertSame(1, $this->countRows('learning_course_snapshots', 'course_id', $gone['course']), 'the student keeps the summary');
    }

    /** @return array{course: int, video: int} */
    private function seedCourse(): array {
        $course = new Course();
        $course->setTitle('Audit 155');
        $course->setInstructorId(self::OWNER);
        $course->setStatus('active');
        $course->setCreatedAt(1);
        $course->setUpdatedAt(1);
        $id = Server::get(CourseMapper::class)->insert($course)->getId();
        $this->courseIds[] = $id;

        $this->insert('learning_course_members', ['course_id' => $id, 'user_id' => 'c155-student', 'role' => 'student', 'enrolled_at' => 1]);
        $this->insert('learning_course_announcements', ['course_id' => $id, 'instructor_id' => self::OWNER, 'title' => 't', 'body' => 'b', 'created_at' => 1]);
        $this->insert('learning_course_videos', ['course_id' => $id, 'source_type' => 'vimeo', 'video_ref' => '1', 'title' => 'v', 'duration_seconds' => 60]);
        $videoId = $this->lastId('learning_course_videos', $id);
        $this->insert('learning_video_progress', ['user_id' => 'c155-student', 'content_id' => $videoId, 'completed_at' => 1]);
        $this->insert('learning_kudos', ['course_id' => $id, 'from_user' => 'c155-student', 'to_user' => self::OWNER, 'message' => 'm', 'created_at' => 1]);
        $this->insert('learning_oversight', ['course_id' => $id, 'lead_user_id' => 'c155-lead', 'scope_group_id' => 'c155-team', 'created_at' => 1]);
        $this->insert('learning_feed_items', ['course_id' => $id, 'type' => 'c155', 'title' => 'x', 'created_at' => 1]);
        $this->insert('learning_assignments', ['course_id' => $id, 'subject_type' => 'user', 'subject_id' => 'c155-student', 'assigned_at' => 1, 'status' => 'assigned', 'active_period_key' => $id . ':user:c155-student']);
        $this->insert('learning_course_snapshots', ['course_id' => $id, 'user_id' => 'c155-student', 'snapshot_data' => '{}', 'created_at' => 1]);
        return ['course' => $id, 'video' => $videoId];
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

    private function lastId(string $table, int $courseId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from($table)
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $id = (int)$result->fetchOne();
        $result->closeCursor();
        return $id;
    }

    private function countRows(string $table, string $column, string|int $value): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'n'))->from($table)
            ->where($qb->expr()->eq($column, $qb->createNamedParameter($value, is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR)));
        $result = $qb->executeQuery();
        $n = (int)$result->fetchOne();
        $result->closeCursor();
        return $n;
    }
}
