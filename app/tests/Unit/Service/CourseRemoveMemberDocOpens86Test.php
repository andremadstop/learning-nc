<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMember;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CoursePoolMapper;
use OCA\Learning\Db\CurriculumScopeMapper;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\FeedService;
use OCA\Learning\Service\RoleService;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\XpService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #86 (review): a learner removed from a course kept the record of when they last opened
 * each required document of that course — personal data without evidence value, only a
 * precondition for a new confirmation — until the retention period ended, or forever with
 * retention switched off. It now goes with the membership, in the same transaction.
 */
#[AllowMockObjectsWithoutExpectations]
class CourseRemoveMemberDocOpens86Test extends TestCase {
    private FakeDbConnection $db;

    private function service(bool $deleteFails = false): CourseService {
        $course = new Course();
        $course->setId(7);
        $course->setInstructorId('teacher');
        $courses = $this->createMock(CourseMapper::class);
        $courses->method('findById')->willReturn($course);

        $member = new CourseMember();
        $member->setId(31);
        $member->setCourseId(7);
        $member->setUserId('anna');
        $member->setRole('student');
        $members = $this->createMock(CourseMemberMapper::class);
        $members->method('findById')->willReturn($member);
        $members->method('findByCourseAndUser')->willReturn($member);
        if ($deleteFails) {
            $members->method('delete')->willThrowException(new \RuntimeException('db down'));
        } else {
            $members->method('delete')->willReturnArgument(0);
        }

        $this->db = new FakeDbConnection();
        return new CourseService(
            $courses,
            $this->createMock(CoursePoolMapper::class),
            $members,
            $this->createMock(RoleService::class),
            $this->db,
            $this->createMock(IGroupManager::class),
            $this->createMock(IUserManager::class),
            $this->createMock(XpService::class),
            $this->createMock(BadgeService::class),
            $this->createMock(StreakService::class),
            $this->createMock(CurriculumScopeMapper::class),
            $this->createMock(FeedService::class)
        );
    }

    /** @return list<\OCA\Learning\Tests\Support\FakeQueryBuilder> */
    private function opensDeletes(): array {
        return array_values(array_filter(
            $this->db->issuedBuilders,
            fn($qb) => $qb->operation === 'delete' && $qb->table === 'learning_doc_opens'
        ));
    }

    public function testRemovingAMemberDeletesTheirOpensOfThisCourse(): void {
        foreach (['31', 'anna'] as $memberRef) {
            $this->service()->removeMember(7, $memberRef, 'teacher');
            $deletes = $this->opensDeletes();
            $this->assertCount(1, $deletes, 'the opens go with the membership (' . $memberRef . ')');
            $this->assertSame([7, 'anna'], array_column($deletes[0]->namedParameters, 'value'), 'only this learner in this course');
            $this->assertSame(1, $this->db->beginTransactionCalls);
            $this->assertSame(1, $this->db->commitCalls);
        }
    }

    public function testAFailedMemberDeleteDeletesNoOpens(): void {
        $service = $this->service(true);
        try {
            $service->removeMember(7, '31', 'teacher');
            $this->fail('the failure is not swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('db down', $e->getMessage());
        }
        $this->assertSame([], $this->opensDeletes());
        $this->assertSame(1, $this->db->rollBackCalls);
        $this->assertSame(0, $this->db->commitCalls);
    }
}
