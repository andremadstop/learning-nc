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
 * GitHub #40: announcements could only be deleted. The edit has the delete's rights (instructor
 * of the course only), is scoped by announcement id AND course id, and changes the feed copy in
 * the same transaction, so the course feed and the dashboard feed show the new text.
 */
#[AllowMockObjectsWithoutExpectations]
class CourseAnnouncementEditTest extends TestCase {
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

    private static function storedRow(): FakeResult {
        return FakeResult::fromFetch([
            'id' => 77,
            'instructor_id' => 'teacher',
            'title' => 'Exam moved',
            'created_at' => 1700000000,
            'expires_at' => null,
        ]);
    }

    public function testEditUpdatesTheRowScopedToTheCourseAndTheFeedCopyInOneTransaction(): void {
        $find = new FakeQueryBuilder(self::storedRow());
        $update = new FakeQueryBuilder(null, 1);
        $db = new FakeDbConnection([$find, $update]);
        $service = $this->makeService($db);

        $this->feedService->expects($this->once())
            ->method('updateAnnouncementItems')
            ->with(self::COURSE_ID, 77, 'Exam moved', 1700000000, 'Exam on Friday', "Room 4\nBring ID", $this->isInt())
            ->willReturn(1);

        $result = $service->updateAnnouncement(self::COURSE_ID, 77, 'teacher', 'Exam on Friday', "Room 4\nBring ID");

        $this->assertSame('update', $update->operation);
        $this->assertSame('learning_course_announcements', $update->table);
        $this->assertSame('Exam on Friday', $update->setCalls['title']['value']);
        $this->assertSame("Room 4\nBring ID", $update->setCalls['body']['value']);
        $this->assertArrayNotHasKey('created_at', $update->setCalls, 'the feed order must not move');
        // id AND course id, so an instructor of one course cannot edit another course's announcement
        $this->assertSame(['type' => 'eq', 'left' => 'id', 'right' => ['value' => 77, 'type' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT]], $update->whereCalls[0]);
        $this->assertSame(['type' => 'eq', 'left' => 'course_id', 'right' => ['value' => self::COURSE_ID, 'type' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT]], $update->andWhereCalls[0]);
        $this->assertSame(['type' => 'eq', 'left' => 'course_id', 'right' => ['value' => self::COURSE_ID, 'type' => \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT]], $find->andWhereCalls[0]);
        $this->assertSame(1, $db->beginTransactionCalls);
        $this->assertSame(1, $db->commitCalls);
        $this->assertSame(0, $db->rollBackCalls);

        $this->assertSame(77, $result['id']);
        $this->assertSame('Exam on Friday', $result['title']);
        $this->assertSame(1700000000, $result['created_at']);
        $this->assertIsInt($result['edited_at']);
    }

    public function testAMissingAnnouncementIsNotFoundAndNothingIsWritten(): void {
        $find = new FakeQueryBuilder(FakeResult::fromFetch(false));
        $db = new FakeDbConnection([$find]);
        $service = $this->makeService($db);

        $this->feedService->expects($this->never())->method('updateAnnouncementItems');

        try {
            $service->updateAnnouncement(self::COURSE_ID, 77, 'teacher', 'New', 'Text');
            $this->fail('A missing announcement must be reported.');
        } catch (DoesNotExistException $e) {
            $this->assertCount(1, $db->issuedBuilders, 'no UPDATE after the failed lookup');
            $this->assertSame(1, $db->rollBackCalls);
            $this->assertSame(0, $db->commitCalls);
        }
    }

    public function testAFailedFeedUpdateRollsBackAndIsNotReportedAsSuccess(): void {
        $db = new FakeDbConnection([new FakeQueryBuilder(self::storedRow()), new FakeQueryBuilder(null, 1)]);
        $service = $this->makeService($db);
        $this->feedService->method('updateAnnouncementItems')->willThrowException(new \RuntimeException('db gone'));

        try {
            $service->updateAnnouncement(self::COURSE_ID, 77, 'teacher', 'New', 'Text');
            $this->fail('The feed update failure must reach the controller.');
        } catch (\RuntimeException $e) {
            $this->assertSame('db gone', $e->getMessage());
        }
        $this->assertSame(1, $db->rollBackCalls);
        $this->assertSame(0, $db->commitCalls);
    }

    public function testAnEmptyTitleIsRefusedBeforeAnyWrite(): void {
        $db = new FakeDbConnection();
        $service = $this->makeService($db);
        $this->feedService->expects($this->never())->method('updateAnnouncementItems');

        $this->expectException(\InvalidArgumentException::class);
        try {
            $service->updateAnnouncement(self::COURSE_ID, 77, 'teacher', '   ', 'Text');
        } finally {
            $this->assertSame([], $db->issuedBuilders);
        }
    }

    public function testAStudentCannotEditAndNothingIsTouched(): void {
        $member = new CourseMember();
        $member->setCourseId(self::COURSE_ID);
        $member->setUserId('learner');
        $member->setRole('student');
        $this->courseMemberMapper->method('findByCourseAndUser')->willReturn($member);
        $db = new FakeDbConnection();
        $service = $this->makeService($db);

        $this->feedService->expects($this->never())->method('updateAnnouncementItems');

        $this->expectException(ForbiddenException::class);
        try {
            $service->updateAnnouncement(self::COURSE_ID, 77, 'learner', 'Hacked', 'Text');
        } finally {
            $this->assertSame([], $db->issuedBuilders);
            $this->assertSame(0, $db->beginTransactionCalls);
        }
    }

    public function testAnOutsiderCannotEdit(): void {
        $this->courseMemberMapper->method('findByCourseAndUser')->willThrowException(new DoesNotExistException('none'));
        $db = new FakeDbConnection();
        $service = $this->makeService($db);

        $this->feedService->expects($this->never())->method('updateAnnouncementItems');

        $this->expectException(ForbiddenException::class);
        $service->updateAnnouncement(self::COURSE_ID, 77, 'stranger', 'Hacked', 'Text');
    }

    public function testACourseInstructorMemberMayEdit(): void {
        $member = new CourseMember();
        $member->setCourseId(self::COURSE_ID);
        $member->setUserId('coteacher');
        $member->setRole('instructor');
        $this->courseMemberMapper->method('findByCourseAndUser')->willReturn($member);
        $service = $this->makeService(new FakeDbConnection([new FakeQueryBuilder(self::storedRow()), new FakeQueryBuilder(null, 1)]));

        $this->feedService->expects($this->once())->method('updateAnnouncementItems');

        $this->assertSame('New', $service->updateAnnouncement(self::COURSE_ID, 77, 'coteacher', 'New', 'Text')['title']);
    }

    private static function feedRow(int $id, string $title, int $createdAt, ?array $meta): FeedItem {
        $item = new FeedItem();
        $item->setId($id);
        $item->setCourseId(self::COURSE_ID);
        $item->setType('announcement');
        $item->setTitle($title);
        $item->setBody('old');
        $item->setCreatedAt($createdAt);
        $item->setMeta($meta !== null ? json_encode($meta) : null);
        return $item;
    }

    public function testFeedServiceUpdatesTheLinkedAndTheLegacyCopyAndLinksTheLegacyOne(): void {
        $linked = self::feedRow(1, 'Exam moved', 1700000000, ['announcement_id' => 77, 'other' => 'kept']);
        $other = self::feedRow(2, 'Exam moved', 1700000000, ['announcement_id' => 78]);
        $legacy = self::feedRow(3, 'Exam moved', 1700000001, null);
        $foreignTitle = self::feedRow(4, 'Other news', 1700000000, null);

        $mapper = $this->createMock(FeedItemMapper::class);
        $mapper->method('findByCourseAndType')->with(self::COURSE_ID, 'announcement')
            ->willReturn([$linked, $other, $legacy, $foreignTitle]);
        $saved = [];
        $mapper->expects($this->exactly(2))->method('update')->willReturnCallback(function (FeedItem $item) use (&$saved): FeedItem {
            $saved[] = $item->getId();
            return $item;
        });

        $service = new FeedService($mapper, $this->createMock(CourseMemberMapper::class));
        $count = $service->updateAnnouncementItems(self::COURSE_ID, 77, 'Exam moved', 1700000000, 'Exam on Friday', 'Room 4', 1700005000);

        $this->assertSame(2, $count);
        $this->assertSame([1, 3], $saved);
        foreach ([$linked, $legacy] as $row) {
            $this->assertSame('Exam on Friday', $row->getTitle());
            $this->assertSame('Room 4', $row->getBody());
            $meta = json_decode((string)$row->getMeta(), true);
            $this->assertSame(77, $meta['announcement_id']);
            $this->assertSame(1700005000, $meta['edited_at']);
        }
        $this->assertSame('kept', json_decode((string)$linked->getMeta(), true)['other']);
        $this->assertSame('Exam moved', $other->getTitle(), 'a row linked to another announcement is never touched');
        $this->assertSame('Other news', $foreignTitle->getTitle());
    }
}
