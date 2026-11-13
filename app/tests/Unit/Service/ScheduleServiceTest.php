<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMember;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CourseSchedule;
use OCA\Learning\Db\CourseScheduleMapper;
use OCA\Learning\Db\CurriculumScopeMapper;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\ScheduleService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #35: the learners' timeline read the schedule through an instructor-only check, so
 * every student got a 403 and the timeline showed "no schedule". Reading is for every member of
 * the course; changing it stays with the instructors.
 */
#[AllowMockObjectsWithoutExpectations]
class ScheduleServiceTest extends TestCase {
    private const COURSE_ID = 7;

    private CourseScheduleMapper $scheduleMapper;
    private int $deleteCalls = 0;
    private int $insertCalls = 0;

    /**
     * @param array<string, string> $memberRoles uid => role of the course members
     */
    private function makeService(array $memberRoles, ?FakeDbConnection $db = null): ScheduleService {
        $course = new Course();
        $course->setId(self::COURSE_ID);
        $course->setInstructorId('owner');
        $courseMapper = $this->createMock(CourseMapper::class);
        $courseMapper->method('findById')->willReturnCallback(function (int $id) use ($course): Course {
            if ($id !== self::COURSE_ID) {
                throw new DoesNotExistException('no course');
            }
            return $course;
        });

        $members = $this->createMock(CourseMemberMapper::class);
        $members->method('findByCourseAndUser')->willReturnCallback(function (int $courseId, string $uid) use ($memberRoles): CourseMember {
            if ($courseId !== self::COURSE_ID || !isset($memberRoles[$uid])) {
                throw new DoesNotExistException('not a member');
            }
            $member = new CourseMember();
            $member->setCourseId($courseId);
            $member->setUserId($uid);
            $member->setRole($memberRoles[$uid]);
            return $member;
        });

        $entry = new CourseSchedule();
        $entry->setId(1);
        $entry->setCourseId(self::COURSE_ID);
        $entry->setChapterRef('chapter-01');
        $entry->setChapterTitle('Basics');
        $entry->setTargetDate('2026-12-01');
        $entry->setSortOrder(1);

        $this->scheduleMapper = $this->createMock(CourseScheduleMapper::class);
        $this->scheduleMapper->method('findByCourse')->willReturn([$entry]);
        $this->scheduleMapper->method('deleteByCourse')->willReturnCallback(function (): void {
            $this->deleteCalls++;
        });
        $this->scheduleMapper->method('insert')->willReturnCallback(function (CourseSchedule $e): CourseSchedule {
            $this->insertCalls++;
            return $e;
        });

        $scopes = $this->createMock(CurriculumScopeMapper::class);
        $scopes->method('findByCourse')->willReturn(null);

        return new ScheduleService($this->scheduleMapper, $courseMapper, $members, $scopes, $db ?? new FakeDbConnection());
    }

    public function testStudentMemberReadsTheSchedule(): void {
        $result = $this->makeService(['learner' => 'student'])->getSchedule(self::COURSE_ID, 'learner');

        $this->assertCount(1, $result['schedule']);
        $this->assertSame('chapter-01', $result['schedule'][0]['chapter_ref']);
    }

    public function testOwnerAndCoInstructorReadTheSchedule(): void {
        $service = $this->makeService(['co' => 'instructor']);

        $this->assertCount(1, $service->getSchedule(self::COURSE_ID, 'owner')['schedule']);
        $this->assertCount(1, $service->getSchedule(self::COURSE_ID, 'co')['schedule']);
    }

    public function testNonMemberCannotReadTheSchedule(): void {
        $this->expectException(ForbiddenException::class);
        $this->makeService(['learner' => 'student'])->getSchedule(self::COURSE_ID, 'stranger');
    }

    public function testStudentCannotReplaceTheSchedule(): void {
        $service = $this->makeService(['learner' => 'student']);
        try {
            $service->setSchedule(self::COURSE_ID, [['chapter_ref' => 'x']], 'learner');
            $this->fail('a student replaced the schedule');
        } catch (ForbiddenException $e) {
            $this->assertSame(0, $this->deleteCalls, 'nothing may be deleted before the permission check');
            $this->assertSame(0, $this->insertCalls);
        }
    }

    public function testStudentCannotDeleteTheSchedule(): void {
        $service = $this->makeService(['learner' => 'student']);
        try {
            $service->deleteSchedule(self::COURSE_ID, 'learner');
            $this->fail('a student deleted the schedule');
        } catch (ForbiddenException $e) {
            $this->assertSame(0, $this->deleteCalls);
        }
    }

    public function testCoInstructorStillReplacesTheSchedule(): void {
        $this->makeService(['co' => 'instructor'])->setSchedule(self::COURSE_ID, [['chapter_ref' => 'chapter-02']], 'co');

        $this->assertSame(1, $this->deleteCalls);
        $this->assertSame(1, $this->insertCalls);
    }

    /** The editor's suggestions are the chapter keys of the course's pools. */
    public function testScheduleOffersThePoolChapterKeysAsSuggestions(): void {
        // getActiveScopeChapterRefs (no scope -> all pool keys) and known_chapter_refs both query.
        $db = new FakeDbConnection([
            new FakeQueryBuilder(new FakeResult([['chapter_key' => 'chapter-01'], ['chapter_key' => 'chapter-02']])),
            new FakeQueryBuilder(new FakeResult([['chapter_key' => 'chapter-01'], ['chapter_key' => 'chapter-02']])),
        ]);

        $result = $this->makeService(['learner' => 'student'], $db)->getSchedule(self::COURSE_ID, 'learner');

        $this->assertSame(['chapter-01', 'chapter-02'], $result['known_chapter_refs']);
        $knownQuery = $db->issuedBuilders[1];
        $this->assertSame(['learning_pools', 'p'], [$knownQuery->joins[0]['join'] ?? null, $knownQuery->joins[0]['alias'] ?? null]);
    }

    /**
     * GitHub #35: the editor's maxlength counts characters, the column holds 255 characters on
     * both engines, but the check counted bytes. 200 umlauts (400 bytes) were refused with
     * "chapter_ref too long" although they fit.
     */
    public function testChapterRefLimitCountsCharactersNotBytes(): void {
        $service = $this->makeService(['co' => 'instructor']);
        $ref = str_repeat('ü', 200);

        $result = $service->setSchedule(self::COURSE_ID, [['chapter_ref' => $ref]], 'co');

        $this->assertSame($ref, $result['schedule'][0]['chapter_ref']);
    }

    public function testChapterRefOver255CharactersIsStillRefused(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->makeService(['co' => 'instructor'])->setSchedule(self::COURSE_ID, [['chapter_ref' => str_repeat('ü', 256)]], 'co');
    }
}
