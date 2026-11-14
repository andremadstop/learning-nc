<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMember;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CoursePoolMapper;
use OCA\Learning\Db\CurriculumScopeMapper;
use OCA\Learning\Db\Pool;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\PoolShareMapper;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\CourseModeClosedException;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\FeedService;
use OCA\Learning\Service\FsrsService;
use OCA\Learning\Service\LeitnerService;
use OCA\Learning\Service\LernprofilService;
use OCA\Learning\Service\RoleService;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\TranslationService;
use OCA\Learning\Service\XpService;
use OCA\Learning\Tests\Support\FakeCacheFactory;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #39 (B1): the course switches for Training, Leitner and the exam only hid the tabs. The
 * API still served the modes, so in a compliance course with Training and Leitner off the pool of
 * the certificate exam stayed a source for its answers.
 *
 * Rule: a mode is closed on a pool when every course the user reaches it through is one they learn
 * in with the mode switched off. A course with the mode on, a course they lead, owning the pool or
 * a share keeps it open; a pool in none of their courses is untouched.
 */
#[AllowMockObjectsWithoutExpectations]
final class CourseModeSwitch39Test extends TestCase {

    // ─── CourseService: which pools the switches close ──────────────────────

    public function testPoolOnlyInCoursesWithTheModeOffIsClosed(): void {
        $service = $this->courseService([
            ['pool_id' => 42, 'instructor_id' => 'teacher', 'mode_config' => '{"training":false,"leitner":false}', 'role' => 'student'],
        ]);
        $this->assertSame([42], $service->getPoolIdsClosedForMode('alice', 'leitner'));
    }

    public function testACourseWithTheModeOnKeepsTheSharedPoolOpen(): void {
        $service = $this->courseService([
            ['pool_id' => 42, 'instructor_id' => 'teacher', 'mode_config' => '{"leitner":false}', 'role' => 'student'],
            ['pool_id' => 42, 'instructor_id' => 'teacher', 'mode_config' => '{"leitner":true}', 'role' => 'student'],
        ]);
        $this->assertSame([], $service->getPoolIdsClosedForMode('alice', 'leitner'));
    }

    public function testAMissingKeyOrConfigCountsAsOn(): void {
        $service = $this->courseService([
            ['pool_id' => 42, 'instructor_id' => 'teacher', 'mode_config' => '{"training":false}', 'role' => 'student'],
            ['pool_id' => 43, 'instructor_id' => 'teacher', 'mode_config' => null, 'role' => 'student'],
        ]);
        $this->assertSame([], $service->getPoolIdsClosedForMode('alice', 'leitner'));
    }

    public function testTheInstructorPreviewStaysOpen(): void {
        $service = $this->courseService([
            ['pool_id' => 42, 'instructor_id' => 'alice', 'mode_config' => '{"exam":false}', 'role' => null],
            ['pool_id' => 43, 'instructor_id' => 'teacher', 'mode_config' => '{"exam":false}', 'role' => 'instructor'],
            ['pool_id' => 44, 'instructor_id' => 'teacher', 'mode_config' => '{"exam":false}', 'role' => 'student'],
        ]);
        $this->assertSame([44], $service->getPoolIdsClosedForMode('alice', 'exam'));
    }

    public function testTheGivenCourseDecidesInItsContext(): void {
        $course = new Course();
        $course->setId(7);
        $course->setInstructorId('teacher');
        $course->setModeConfig('{"training":false}');
        $courseMapper = $this->createMock(CourseMapper::class);
        $courseMapper->method('findById')->with(7)->willReturn($course);
        $member = new CourseMember();
        $member->setRole('student');
        $memberMapper = $this->createMock(CourseMemberMapper::class);
        $memberMapper->method('findByCourseAndUser')->willReturn($member);

        $service = $this->courseService([], $courseMapper, $memberMapper);
        $this->assertTrue($service->isPoolModeClosedByCourses(42, 'alice', 'training', 7));
        $this->assertFalse($service->isPoolModeClosedByCourses(42, 'alice', 'exam', 7));
    }

    // ─── Leitner endpoints ───────────────────────────────────────────────────

    public function testDueQuestionsAreRefusedWhenTheCoursesSwitchedLeitnerOff(): void {
        $courseService = $this->createMock(CourseService::class);
        $courseService->method('isPoolModeClosedByCourses')->with(42, 'alice', 'leitner', null)->willReturn(true);
        $db = new FakeDbConnection([new FakeQueryBuilder(FakeResult::fromFetch(['id' => 1]))]);

        $this->expectException(CourseModeClosedException::class);
        $this->leitner($db, $courseService, owner: false)->getDueQuestions(42, 'alice');
    }

    public function testInitializeIsRefusedWhenTheCoursesSwitchedLeitnerOff(): void {
        $courseService = $this->createMock(CourseService::class);
        $courseService->method('isPoolModeClosedByCourses')->willReturn(true);
        $db = new FakeDbConnection([new FakeQueryBuilder(FakeResult::fromFetch(['id' => 1]))]);

        try {
            $this->leitner($db, $courseService, owner: false)->initializePool(42, 'alice', 7);
            $this->fail('a closed Leitner mode must not create cards');
        } catch (CourseModeClosedException $e) {
        }
        foreach ($db->issuedBuilders as $builder) {
            $this->assertNotSame('insert', $builder->operation);
        }
    }

    public function testAnAnswerIsRefusedBeforeTheSolutionIsRead(): void {
        $courseService = $this->createMock(CourseService::class);
        $courseService->method('isPoolModeClosedByCourses')->with(42, 'alice', 'leitner', null)->willReturn(true);
        $item = new FakeQueryBuilder(FakeResult::fromFetch(['id' => 5, 'pool_id' => 42, 'question_id' => 10, 'user_id' => 'alice', 'box' => 1]));
        $db = new FakeDbConnection([$item]);

        try {
            $this->leitner($db, $courseService, owner: false)->answerQuestion(5, 100, 'alice');
            $this->fail('a closed Leitner mode must not judge an answer');
        } catch (CourseModeClosedException $e) {
        }
        $this->assertCount(1, $db->issuedBuilders, 'no question, answer or update query after the refusal');
    }

    public function testTheOwnerOfThePoolKeepsLeitner(): void {
        $courseService = $this->createMock(CourseService::class);
        $courseService->expects($this->never())->method('isPoolModeClosedByCourses');
        $db = new FakeDbConnection([new FakeQueryBuilder(FakeResult::fromFetchAll([]))]);

        $this->assertSame([], $this->leitner($db, $courseService, owner: true)->getDueQuestions(42, 'alice'));
    }

    public function testQueueAndItsCountLeaveOutTheClosedPools(): void {
        $courseService = $this->createMock(CourseService::class);
        $courseService->method('getPoolIdsClosedForMode')->with('alice', 'leitner')->willReturn([42]);
        $count = new FakeQueryBuilder(FakeResult::fromFetch(['cnt' => 3]));
        $queue = new FakeQueryBuilder(FakeResult::fromFetchAll([]));
        $remediationCount = new FakeQueryBuilder(new FakeResult());
        $remediation = new FakeQueryBuilder(FakeResult::fromFetchAll([]));
        $db = new FakeDbConnection([$count, $queue, $remediationCount, $remediation]);
        $service = $this->leitner($db, $courseService, owner: false);

        $service->getQueueCount('alice');
        $service->getSmartQueue('alice');
        $service->getRemediationCount('alice');
        $service->getRemediationQueue('alice');

        foreach ([[$count, 'pool_id'], [$queue, 'l.pool_id'], [$remediationCount, 'pool_id'], [$remediation, 'l.pool_id']] as [$builder, $column]) {
            $excluded = array_values(array_filter(
                $builder->andWhereCalls,
                static fn ($c): bool => is_array($c) && ($c['type'] ?? null) === 'notIn' && $c['left'] === $column
            ));
            $this->assertCount(1, $excluded, $column);
            $this->assertSame([42], $excluded[0]['right']['value']);
        }
    }

    public function testAnOwnedPoolStaysInTheQueue(): void {
        $courseService = $this->createMock(CourseService::class);
        $courseService->method('getPoolIdsClosedForMode')->willReturn([42]);
        $count = new FakeQueryBuilder(FakeResult::fromFetch(['cnt' => 3]));
        $db = new FakeDbConnection([$count]);

        $this->leitner($db, $courseService, owner: true)->getQueueCount('alice');

        foreach ($count->andWhereCalls as $condition) {
            $this->assertNotSame('notIn', $condition['type'] ?? null);
        }
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    /** @param list<array<string, mixed>> $rows rows of the pool/course/membership query */
    private function courseService(array $rows, ?CourseMapper $courseMapper = null, ?CourseMemberMapper $memberMapper = null): CourseService {
        $db = new FakeDbConnection([new FakeQueryBuilder(new FakeResult($rows))]);
        return new CourseService(
            $courseMapper ?? $this->createMock(CourseMapper::class),
            $this->createMock(CoursePoolMapper::class),
            $memberMapper ?? $this->createMock(CourseMemberMapper::class),
            $this->createMock(RoleService::class),
            $db,
            $this->createMock(IGroupManager::class),
            $this->createMock(IUserManager::class),
            $this->createMock(XpService::class),
            $this->createMock(BadgeService::class),
            $this->createMock(StreakService::class),
            $this->createMock(CurriculumScopeMapper::class),
            $this->createMock(FeedService::class)
        );
    }

    private function leitner(FakeDbConnection $db, CourseService $courseService, bool $owner): LeitnerService {
        $poolMapper = $this->createMock(PoolMapper::class);
        if ($owner) {
            $poolMapper->method('find')->willReturn(new Pool());
        } else {
            $poolMapper->method('find')->willThrowException(new DoesNotExistException('not the owner'));
        }
        $streakService = $this->createMock(StreakService::class);
        $streakService->method('getStreak')->willReturn(['current_streak' => 0]);
        $translationService = $this->createMock(TranslationService::class);
        $translationService->method('normalizeLang')->willReturn(null);
        $translationService->method('translateQuestions')->willReturnCallback(static fn(array $items): array => $items);
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturn('');

        return new LeitnerService(
            $db,
            $poolMapper,
            $this->createMock(PoolShareMapper::class),
            $this->createMock(BadgeService::class),
            $streakService,
            $this->createMock(XpService::class),
            new FakeCacheFactory(),
            $translationService,
            $config,
            $courseService,
            $this->createMock(LernprofilService::class),
            new FsrsService()
        );
    }
}
