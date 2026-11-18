<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMember;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CoursePoolMapper;
use OCA\Learning\Db\CurriculumScopeMapper;
use OCA\Learning\Db\FeedItem;
use OCA\Learning\Db\FeedItemMapper;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\FeedService;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\RoleService;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\XpService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #40: deleting an announcement removed only the instructor's list entry, while its feed
 * copy stayed visible to every course member (course feed and dashboard feed). The feed row now
 * carries meta.announcement_id, and the delete removes both rows in one transaction.
 */
#[AllowMockObjectsWithoutExpectations]
class CourseAnnouncementFeedTest extends TestCase {
    private const COURSE_ID = 12;

    private CourseMapper $courseMapper;
    private CourseMemberMapper $courseMemberMapper;
    private FeedService $feedService;

    protected function setUp(): void {
        $this->courseMapper = $this->createMock(CourseMapper::class);
        $this->courseMemberMapper = $this->createMock(CourseMemberMapper::class);
        $this->feedService = $this->createMock(FeedService::class);

        $course = new Course();
        $course->setId(self::COURSE_ID);
        $course->setInstructorId('teacher');
        $this->courseMapper->method('findById')->willReturn($course);
    }

    private function makeService(FakeDbConnection $db): CourseService {
        return new CourseService(
            $this->courseMapper,
            $this->createMock(CoursePoolMapper::class),
            $this->courseMemberMapper,
            $this->createMock(RoleService::class),
            $db,
            $this->createMock(IGroupManager::class),
            $this->createMock(IUserManager::class),
            $this->createMock(XpService::class),
            $this->createMock(BadgeService::class),
            $this->createMock(StreakService::class),
            $this->createMock(CurriculumScopeMapper::class),
            $this->feedService
        );
    }

    public function testCreateLinksTheFeedRowToTheAnnouncement(): void {
        $insert = new FakeQueryBuilder(null, 1, 77);
        $service = $this->makeService(new FakeDbConnection([$insert]));

        $this->feedService->expects($this->once())
            ->method('createAutoItem')
            ->with(self::COURSE_ID, 'announcement', 'Exam moved', "Line one\nLine two", 'teacher', ['announcement_id' => 77]);

        $created = $service->createAnnouncement(self::COURSE_ID, 'teacher', 'Exam moved', "Line one\nLine two");
        $this->assertSame(77, $created['id']);
    }

    public function testDeleteRemovesTheFeedCopyInTheSameTransaction(): void {
        $find = new FakeQueryBuilder(FakeResult::fromFetch(['title' => 'Exam moved', 'created_at' => 1700000000]));
        $delete = new FakeQueryBuilder(null, 1);
        $db = new FakeDbConnection([$find, $delete]);
        $service = $this->makeService($db);

        $this->feedService->expects($this->once())
            ->method('deleteAnnouncementItems')
            ->with(self::COURSE_ID, 77, 'Exam moved', 1700000000)
            ->willReturn(1);

        $service->deleteAnnouncement(self::COURSE_ID, 77, 'teacher');

        $this->assertSame('delete', $delete->operation);
        $this->assertSame(1, $db->beginTransactionCalls);
        $this->assertSame(1, $db->commitCalls);
        $this->assertSame(0, $db->rollBackCalls);
    }

    public function testDeleteOfAMissingAnnouncementStillClearsALinkedFeedRow(): void {
        $find = new FakeQueryBuilder(FakeResult::fromFetch(false));
        $service = $this->makeService(new FakeDbConnection([$find, new FakeQueryBuilder()]));

        $this->feedService->expects($this->once())
            ->method('deleteAnnouncementItems')
            ->with(self::COURSE_ID, 77, null, null);

        $service->deleteAnnouncement(self::COURSE_ID, 77, 'teacher');
    }

    public function testAFailedFeedDeleteRollsBackAndIsNotReportedAsSuccess(): void {
        $find = new FakeQueryBuilder(FakeResult::fromFetch(['title' => 'Exam moved', 'created_at' => 1700000000]));
        $db = new FakeDbConnection([$find, new FakeQueryBuilder()]);
        $service = $this->makeService($db);

        $this->feedService->method('deleteAnnouncementItems')->willThrowException(new \RuntimeException('db gone'));

        try {
            $service->deleteAnnouncement(self::COURSE_ID, 77, 'teacher');
            $this->fail('The feed delete failure must reach the controller.');
        } catch (\RuntimeException $e) {
            $this->assertSame('db gone', $e->getMessage());
        }
        $this->assertSame(1, $db->rollBackCalls);
        $this->assertSame(0, $db->commitCalls);
    }

    public function testAStudentCannotDeleteAndNothingIsTouched(): void {
        $member = new CourseMember();
        $member->setCourseId(self::COURSE_ID);
        $member->setUserId('learner');
        $member->setRole('student');
        $this->courseMemberMapper->method('findByCourseAndUser')->willReturn($member);
        $db = new FakeDbConnection();
        $service = $this->makeService($db);

        $this->feedService->expects($this->never())->method('deleteAnnouncementItems');

        $this->expectException(ForbiddenException::class);
        try {
            $service->deleteAnnouncement(self::COURSE_ID, 77, 'learner');
        } finally {
            $this->assertSame([], $db->issuedBuilders);
            $this->assertSame(0, $db->beginTransactionCalls);
        }
    }

    public function testAnOutsiderCannotDelete(): void {
        $this->courseMemberMapper->method('findByCourseAndUser')->willThrowException(new DoesNotExistException('none'));
        $service = $this->makeService(new FakeDbConnection());

        $this->feedService->expects($this->never())->method('deleteAnnouncementItems');

        $this->expectException(ForbiddenException::class);
        $service->deleteAnnouncement(self::COURSE_ID, 77, 'stranger');
    }

    private static function feedRow(int $id, string $title, int $createdAt, ?array $meta): FeedItem {
        $item = new FeedItem();
        $item->setId($id);
        $item->setCourseId(self::COURSE_ID);
        $item->setType('announcement');
        $item->setTitle($title);
        $item->setCreatedAt($createdAt);
        $item->setMeta($meta !== null ? json_encode($meta) : null);
        return $item;
    }

    public function testFeedServiceDeletesTheLinkedRowAndTheMatchingLegacyRowOnly(): void {
        $mapper = $this->createMock(FeedItemMapper::class);
        $mapper->method('findByCourseAndType')->with(self::COURSE_ID, 'announcement')->willReturn([
            self::feedRow(1, 'Exam moved', 1700000000, ['announcement_id' => 77]),
            // another announcement with the same title and second: linked, so never matched by title
            self::feedRow(2, 'Exam moved', 1700000000, ['announcement_id' => 78]),
            // legacy rows without meta: same title within two seconds, other title, too late
            self::feedRow(3, 'Exam moved', 1700000001, null),
            self::feedRow(4, 'Other news', 1700000000, null),
            self::feedRow(5, 'Exam moved', 1700000010, null),
        ]);
        $mapper->expects($this->once())->method('deleteByIds')->with(self::COURSE_ID, [1, 3])->willReturn(2);

        $service = new FeedService($mapper, $this->createMock(CourseMemberMapper::class), $this->createMock(\OCA\Learning\Activity\ActivityPublisher::class));
        $this->assertSame(2, $service->deleteAnnouncementItems(self::COURSE_ID, 77, 'Exam moved', 1700000000));
    }

    public function testWithoutTheAnnouncementRowOnlyALinkedFeedRowMatches(): void {
        $legacy = self::feedRow(3, 'Exam moved', 1700000000, null);
        $linked = self::feedRow(1, 'Exam moved', 1700000000, ['announcement_id' => 77]);

        $this->assertFalse(FeedService::belongsToAnnouncement($legacy, 77, null, null));
        $this->assertTrue(FeedService::belongsToAnnouncement($linked, 77, null, null));
    }
}
