<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Controller\CourseController;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CoursePoolMapper;
use OCA\Learning\Db\CurriculumScopeMapper;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\CourseArchiveService;
use OCA\Learning\Service\CourseEnrollmentService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\ExamAttemptPolicy;
use OCA\Learning\Service\FeedService;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\PassCriteriaService;
use OCA\Learning\Service\RoleService;
use OCA\Learning\Service\ScheduleService;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\XpService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #68: the statistics tab of a pool in a course without Leitner showed the viewer's own,
 * empty Leitner boxes ("0 questions" next to a pool of eight). It now reads answer statistics of
 * the learners of the courses holding the pool — aggregates only, for owner and edit shares.
 */
#[AllowMockObjectsWithoutExpectations]
class PoolAnswerStats68Test extends TestCase {
    private const POOL = 11;

    private function service(FakeDbConnection $db): CourseService {
        return new CourseService(
            $this->createMock(CourseMapper::class),
            $this->createMock(CoursePoolMapper::class),
            $this->createMock(CourseMemberMapper::class),
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

    private static function one(mixed $value): FakeQueryBuilder {
        return new FakeQueryBuilder(FakeResult::fromFetchOne($value));
    }

    private static function rows(array $rows): FakeQueryBuilder {
        return new FakeQueryBuilder(new FakeResult($rows));
    }

    /**
     * Queries in order: owner check, courses holding the pool, question count, learners,
     * totals, hardest questions.
     *
     * @param list<string|null> $modeConfigs one per course holding the pool
     */
    private function db(array $modeConfigs, ?FakeQueryBuilder $hardest = null): FakeDbConnection {
        $courses = [];
        foreach ($modeConfigs as $i => $config) {
            $courses[] = ['id' => 7 + $i, 'mode_config' => $config];
        }
        return new FakeDbConnection([
            self::one(1),
            self::rows($courses),
            self::one(8),
            self::rows([['user_id' => 'lena'], ['user_id' => 'max']]),
            self::rows([['total_answers' => 20, 'correct_answers' => 15, 'learners' => 2, 'attempts' => 4]]),
            $hardest ?? self::rows([
                ['question_id' => 3, 'question_text' => 'Welcher Feuerlöscher?', 'total_answers' => 4, 'correct_answers' => 1],
                ['question_id' => 4, 'question_text' => 'Immer richtig', 'total_answers' => 4, 'correct_answers' => 4],
            ]),
        ]);
    }

    public function testAPoolOfCoursesWithoutLeitnerGetsItsAnswerStatistics(): void {
        $hardest = self::rows([
            ['question_id' => 3, 'question_text' => 'Welcher Feuerlöscher?', 'total_answers' => 4, 'correct_answers' => 1],
            ['question_id' => 4, 'question_text' => 'Immer richtig', 'total_answers' => 4, 'correct_answers' => 4],
        ]);
        $stats = $this->service($this->db([json_encode(['leitner' => false])], $hardest))->getPoolAnswerStats(self::POOL, 'teacher');

        $this->assertFalse($stats['leitner_in_use']);
        $this->assertSame(8, $stats['question_count'], 'the questions of the pool, not the own Leitner items');
        $this->assertSame(2, $stats['learners']);
        $this->assertSame(4, $stats['attempts']);
        $this->assertSame(20, $stats['answers']);
        $this->assertSame(75, $stats['accuracy']);
        $this->assertSame([['question_id' => 3, 'question_text' => 'Welcher Feuerlöscher?', 'total_answers' => 4, 'wrong_rate' => 75.0]], $stats['hardest'], 'a question never answered wrong is not hard');
        $this->assertSame(['COUNT(ua.id) >= ' . CourseService::MIN_ANSWERS_PER_QUESTION], $hardest->havings);
        $this->assertSame(5, $hardest->maxResults);
    }

    public function testOneCourseWithLeitnerKeepsTheBoxView(): void {
        $stats = $this->service($this->db([json_encode(['leitner' => false]), null]))->getPoolAnswerStats(self::POOL, 'teacher');
        $this->assertTrue($stats['leitner_in_use']);
    }

    public function testAPoolInNoCourseKeepsLeitnerAndReadsNoAnswers(): void {
        $db = new FakeDbConnection([self::one(1), self::rows([]), self::one(8)]);
        $stats = $this->service($db)->getPoolAnswerStats(self::POOL, 'owner');
        $this->assertTrue($stats['leitner_in_use']);
        $this->assertSame(0, $stats['answers']);
        $this->assertCount(3, $db->issuedBuilders, 'no answer query without a course');
    }

    public function testOnlyTheOwnerOrAnEditShareReadsTheStatistics(): void {
        // not the owner (0), no edit share (0)
        $db = new FakeDbConnection([self::one(0), self::one(0)]);
        $this->expectException(ForbiddenException::class);
        $this->service($db)->getPoolAnswerStats(self::POOL, 'reader');
    }

    public function testTheEndpointAnswers403ForAReader(): void {
        $courseService = $this->createMock(CourseService::class);
        $courseService->method('getPoolAnswerStats')->willThrowException(new ForbiddenException('No permission'));
        $controller = new CourseController(
            'learning',
            $this->createMock(IRequest::class),
            $this->createMock(CourseMapper::class),
            $this->createMock(CourseMemberMapper::class),
            $this->createMock(CourseArchiveService::class),
            $courseService,
            $this->createMock(RoleService::class),
            $this->createMock(ScheduleService::class),
            $this->createMock(PassCriteriaService::class),
            $this->createMock(ExamAttemptPolicy::class),
            $this->createMock(CourseEnrollmentService::class),
            $this->createMock(LoggerInterface::class),
            'reader'
        );
        $this->assertSame(403, $controller->poolAnswerStats(self::POOL)->getStatus());
    }
}
