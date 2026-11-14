<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\AnswerMapper;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMember;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CoursePool;
use OCA\Learning\Db\CoursePoolMapper;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Service\DataMobilityService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Audit #158: the course stats CSV computed the course-scoped mastery and threw it away;
 * total_mastered came from the global learning_user_stats (all courses, private pools).
 */
#[AllowMockObjectsWithoutExpectations]
final class CourseStatsCsvMasteryTest extends TestCase {

    public function testTotalMasteredCountsOnlyTheCoursePools(): void {
        $courseMapper = $this->createMock(CourseMapper::class);
        $courseMapper->method('findById')->willReturn(new Course());

        $pool = new CoursePool();
        $pool->setPoolId(3);
        $poolB = new CoursePool();
        $poolB->setPoolId(4);
        $coursePoolMapper = $this->createMock(CoursePoolMapper::class);
        $coursePoolMapper->method('findByCourse')->willReturn([$pool, $poolB]);

        $member = new CourseMember();
        $member->setUserId('alice');
        $member->setRole('student');
        $memberMapper = $this->createMock(CourseMemberMapper::class);
        $memberMapper->method('findByCourse')->willReturn([$member]);

        $db = new FakeDbConnection([
            // learning_user_stats: 40 mastered across the whole instance
            new FakeQueryBuilder(FakeResult::fromFetch(['user_id' => 'alice', 'total_xp' => 10, 'current_level' => 1,
                'current_streak' => 0, 'total_sessions' => 2, 'total_mastered' => 40, 'last_activity_date' => ''])),
            new FakeQueryBuilder(new FakeResult([])), // sessions
            // leitner box 5 in the course pools: 7 + 2
            new FakeQueryBuilder(new FakeResult([
                ['user_id' => 'alice', 'pool_id' => 3, 'cnt' => 7],
                ['user_id' => 'alice', 'pool_id' => 4, 'cnt' => 2],
            ])),
        ]);

        $service = new DataMobilityService(
            $this->createMock(PoolMapper::class),
            $this->createMock(QuestionMapper::class),
            $this->createMock(AnswerMapper::class),
            $courseMapper,
            $coursePoolMapper,
            $memberMapper,
            $db,
            $this->createMock(IUserManager::class)
        );

        $lines = explode("\n", trim($service->exportCourseStatsCsv(5)));
        $header = str_getcsv($lines[0], ',', '"', '');
        $row = array_combine($header, str_getcsv($lines[1], ',', '"', ''));

        $this->assertSame('9', $row['total_mastered']);
    }
}
