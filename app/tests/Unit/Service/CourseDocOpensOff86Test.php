<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
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
 * GitHub #86 (review): an instructor switched "confirm only after opening" off, the stored opens of
 * the course stayed until the retention period, and the privacy notice said again that only the
 * completion is stored. The opens have no use and no evidence value without the option: they go
 * with the switch, in the same transaction as the course update.
 */
#[AllowMockObjectsWithoutExpectations]
class CourseDocOpensOff86Test extends TestCase {
    private FakeDbConnection $db;
    private Course $course;

    private function service(bool $updateFails = false): CourseService {
        $this->course = new Course();
        $this->course->setId(7);
        $this->course->setInstructorId('teacher');
        $this->course->setOpenBeforeConfirm(true);
        $courses = $this->createMock(CourseMapper::class);
        $courses->method('findById')->willReturn($this->course);
        if ($updateFails) {
            $courses->method('update')->willThrowException(new \RuntimeException('db down'));
        } else {
            $courses->method('update')->willReturnArgument(0);
        }
        $this->db = new FakeDbConnection();
        return new CourseService(
            $courses,
            $this->createMock(CoursePoolMapper::class),
            $this->createMock(CourseMemberMapper::class),
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

    public function testSwitchingTheOptionOffDeletesTheOpensOfThisCourse(): void {
        $course = $this->service()->setRequiredContentOptions(7, 'teacher', null, null, false);
        $this->assertFalse($course->getOpenBeforeConfirm());
        $deletes = $this->opensDeletes();
        $this->assertCount(1, $deletes, 'the opens of the course are deleted');
        $this->assertSame([7], array_column($deletes[0]->namedParameters, 'value'), 'only those of this course');
        $this->assertSame(1, $this->db->beginTransactionCalls);
        $this->assertSame(1, $this->db->commitCalls);
    }

    public function testSwitchingItOnOrLeavingItAloneKeepsThem(): void {
        foreach ([true, null] as $value) {
            $this->service()->setRequiredContentOptions(7, 'teacher', true, true, $value);
            $this->assertSame([], $this->opensDeletes(), 'open_before_confirm=' . var_export($value, true));
        }
    }

    public function testAFailedCourseUpdateDeletesNothing(): void {
        $service = $this->service(true);
        try {
            $service->setRequiredContentOptions(7, 'teacher', null, null, false);
            $this->fail('the failure is not swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('db down', $e->getMessage());
        }
        $this->assertSame([], $this->opensDeletes());
        $this->assertSame(1, $this->db->rollBackCalls);
        $this->assertSame(0, $this->db->commitCalls);
    }
}
