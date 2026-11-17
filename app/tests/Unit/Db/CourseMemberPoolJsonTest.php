<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Db;

use OCA\Learning\Db\CourseMember;
use OCA\Learning\Db\CoursePool;
use OCP\AppFramework\Http\DataResponse;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #65 (C5 P11), same class of bug as #75: CoursePool and CourseMember had a jsonSerialize()
 * method but did not implement \JsonSerializable, and NC's Entity does not either. Four endpoints
 * put the entity straight into a DataResponse and answered {"id": N}:
 *   POST /api/courses/{id}/pools, PUT /api/courses/{id}/pools/{poolId} (CoursePool),
 *   POST /api/courses/{id}/members, POST /api/courses/{id}/enroll (CourseMember).
 */
class CourseMemberPoolJsonTest extends TestCase {
    /** @return array<string, mixed> what a DataResponse sends for $data */
    private static function sent(mixed $data): array {
        return json_decode((string)json_encode((new DataResponse($data))->getData()), true);
    }

    public function testCoursePoolIsSentWithItsRules(): void {
        $cp = new CoursePool();
        $cp->setId(11);
        $cp->setCourseId(3);
        $cp->setPoolId(42);
        $cp->setSortOrder(2);
        $cp->setRequired(true);
        $cp->setFilterQuestionIds('[5,6]');
        $cp->setExamQuestions(20);

        $sent = self::sent($cp);

        $this->assertSame(11, $sent['id'] ?? null);
        $this->assertSame(3, $sent['course_id'] ?? null);
        $this->assertSame(42, $sent['pool_id'] ?? null);
        $this->assertSame(2, $sent['sort_order'] ?? null);
        $this->assertSame([5, 6], $sent['filter_question_ids'] ?? null);
        $this->assertSame(20, $sent['exam_questions'] ?? null);
    }

    public function testCourseMemberIsSentWithUserAndRole(): void {
        $m = new CourseMember();
        $m->setId(9);
        $m->setCourseId(3);
        $m->setUserId('alice');
        $m->setRole('instructor');
        $m->setEnrolledAt(1700000000);

        $this->assertSame(
            ['id' => 9, 'course_id' => 3, 'user_id' => 'alice', 'role' => 'instructor', 'enrolled_at' => 1700000000],
            self::sent($m)
        );
    }
}
