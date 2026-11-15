<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMember;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CoursePool;
use OCA\Learning\Db\CoursePoolMapper;
use OCA\Learning\Db\CurriculumScopeMapper;
use OCA\Learning\Service\AssignmentService;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\FeedService;
use OCA\Learning\Service\PassCriteriaService;
use OCA\Learning\Service\RoleService;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\XpService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #68: in a course without Leitner the progress tab showed every learner at 0 % (the
 * Leitner boxes such a course never fills) and put learners who had passed both exams on the
 * at-risk list for their "critical cards". The figures now come from the exams (the pass rule of
 * the completion tab) and the sessions' hit rate; with Leitner on nothing changes.
 */
#[AllowMockObjectsWithoutExpectations]
class LeitnerOffProgress68Test extends TestCase {
    private const REQUIRED = 11;
    private const OTHER = 12;

    private function service(FakeDbConnection $db, ?string $modeConfig, ?PassCriteriaService $pass, ?AssignmentService $assignments = null): CourseService {
        $course = new Course();
        $course->setId(7);
        $course->setInstructorId('teacher');
        $course->setModeConfig($modeConfig);
        $courses = $this->createMock(CourseMapper::class);
        $courses->method('findById')->willReturn($course);

        $member = new CourseMember();
        $member->setCourseId(7);
        $member->setUserId('learner');
        $member->setRole('student');
        $member->setEnrolledAt(1);
        $members = $this->createMock(CourseMemberMapper::class);
        $members->method('findByCourse')->willReturn([$member]);

        $linked = [];
        foreach ([self::REQUIRED, self::OTHER] as $poolId) {
            $cp = new CoursePool();
            $cp->setCourseId(7);
            $cp->setPoolId($poolId);
            $linked[] = $cp;
        }
        $pools = $this->createMock(CoursePoolMapper::class);
        $pools->method('findByCourse')->willReturn($linked);

        $xp = $this->createMock(XpService::class);
        $xp->method('isGamificationEnabled')->willReturn(false);

        return new CourseService(
            $courses,
            $pools,
            $members,
            $this->createMock(RoleService::class),
            $db,
            $this->createMock(IGroupManager::class),
            $this->createMock(IUserManager::class),
            $xp,
            $this->createMock(BadgeService::class),
            $this->createMock(StreakService::class),
            $this->createMock(CurriculumScopeMapper::class),
            $this->createMock(FeedService::class),
            $pass,
            $assignments
        );
    }

    /** A learner who passed the exam of the required pool with 90 %, has no Leitner item and many critical cards. */
    private function passedStanding(array $overrides = []): PassCriteriaService {
        $pass = $this->createMock(PassCriteriaService::class);
        $pass->method('examStandings')->willReturn([
            'applicable' => true,
            'threshold' => 80,
            'requirement' => 'required_pools',
            'required_pool_ids' => [self::REQUIRED],
            'users' => ['learner' => array_merge([
                'passed' => true,
                'score' => 90,
                'pools' => [self::REQUIRED => ['best_score' => 90, 'passed' => true]],
                'certificate_valid' => true,
                'certificate_expired' => false,
                'renewal_open' => false,
            ], $overrides)],
        ]);
        return $pass;
    }

    /** Queries of getCourseProgress() in order: names, question counts, mastery, sessions, critical cards, stats. */
    private function progressDb(): FakeDbConnection {
        return new FakeDbConnection([
            new FakeQueryBuilder(new FakeResult([['id' => self::REQUIRED, 'name' => 'Brandschutz'], ['id' => self::OTHER, 'name' => 'Erste Hilfe']])),
            new FakeQueryBuilder(new FakeResult([['pool_id' => self::REQUIRED, 'cnt' => 8], ['pool_id' => self::OTHER, 'cnt' => 5]])),
            new FakeQueryBuilder(new FakeResult([])),
            new FakeQueryBuilder(new FakeResult([
                ['user_id' => 'learner', 'pool_id' => self::REQUIRED, 'total_q' => 20, 'correct' => 18, 'last_active' => time()],
                ['user_id' => 'learner', 'pool_id' => self::OTHER, 'total_q' => 10, 'correct' => 6, 'last_active' => time()],
            ])),
            new FakeQueryBuilder(new FakeResult([
                ['user_id' => 'learner', 'stability' => 0.1, 'last_reviewed' => time()],
            ])),
            new FakeQueryBuilder(new FakeResult([[
                'user_id' => 'learner', 'total_xp' => 0, 'current_level' => 1, 'current_streak' => 0, 'longest_streak' => 0,
                'total_sessions' => 3, 'total_mastered' => 0, 'last_activity_date' => gmdate('Y-m-d'),
            ]])),
        ]);
    }

    /** @return array<int, array<string, mixed>> pool id => pool cell */
    private function poolsOf(array $row): array {
        $out = [];
        foreach ($row['pools'] as $pool) {
            $out[(int)$pool['pool_id']] = $pool;
        }
        return $out;
    }

    public function testAPassedLearnerShowsTheExamScoreInsteadOfZeroWithoutLeitner(): void {
        $progress = $this->service($this->progressDb(), json_encode(['leitner' => false]), $this->passedStanding())
            ->getCourseProgress(7, 'teacher');
        $row = $progress['students'][0];
        $pools = $this->poolsOf($row);

        $this->assertSame('exams', $progress['meta']['progress_basis']);
        $this->assertTrue($progress['meta']['cert_applicable']);
        $this->assertSame('exam', $pools[self::REQUIRED]['metric']);
        $this->assertSame(90, $pools[self::REQUIRED]['value'], 'the passed exam, not 0 %');
        $this->assertTrue($pools[self::REQUIRED]['exam_passed']);
        $this->assertSame('accuracy', $pools[self::OTHER]['metric']);
        $this->assertSame(60, $pools[self::OTHER]['value'], 'the hit rate of the sessions');
        $this->assertSame('exam', $row['overall_metric']);
        $this->assertTrue($row['overall_passed']);
        $this->assertSame(90, $row['overall_mastery']);
    }

    public function testAValidCertificateInItsRenewalWindowTravelsWithTheRow(): void {
        $standing = $this->passedStanding([
            'passed' => false, 'score' => null, 'pools' => [self::REQUIRED => ['best_score' => null, 'passed' => false]],
            'certificate_valid' => true, 'renewal_open' => true,
        ]);
        $row = $this->service($this->progressDb(), json_encode(['leitner' => false]), $standing)
            ->getCourseProgress(7, 'teacher')['students'][0];
        $this->assertFalse($row['overall_passed']);
        $this->assertTrue($row['certificate_valid'], 'the table shows "valid, renewal open", not "not passed"');
        $this->assertTrue($row['renewal_open']);
        $this->assertNull($this->poolsOf($row)[self::REQUIRED]['value']);
    }

    public function testWithoutCertificatesTheOverallIsTheHitRate(): void {
        $pass = $this->createMock(PassCriteriaService::class);
        $pass->method('examStandings')->willReturn(['applicable' => false, 'threshold' => 80, 'requirement' => 'any_exam', 'required_pool_ids' => [], 'users' => []]);
        $progress = $this->service($this->progressDb(), json_encode(['leitner' => false]), $pass)
            ->getCourseProgress(7, 'teacher');
        $row = $progress['students'][0];

        $this->assertFalse($progress['meta']['cert_applicable']);
        $this->assertSame('accuracy', $row['overall_metric']);
        $this->assertNull($row['overall_passed']);
        $this->assertSame(80, $row['overall_mastery'], '24 of 30 answers right');
        $this->assertSame(90, $this->poolsOf($row)[self::REQUIRED]['value']);
    }

    public function testCriticalCardsAreNoSortOrderWithoutLeitner(): void {
        $meta = $this->service($this->progressDb(), json_encode(['leitner' => false]), $this->passedStanding())
            ->getCourseProgress(7, 'teacher', 25, 0, 'critical_cards_count', 'desc')['meta'];
        $this->assertSame('user_id', $meta['sort_key']);
        $this->assertSame('asc', $meta['sort_dir']);
    }

    public function testWithLeitnerTheMasteryTableStaysAndNoExamIsRead(): void {
        $pass = $this->createMock(PassCriteriaService::class);
        $pass->expects($this->never())->method('examStandings');
        $progress = $this->service($this->progressDb(), null, $pass)
            ->getCourseProgress(7, 'teacher', 25, 0, 'critical_cards_count', 'desc');
        $row = $progress['students'][0];

        $this->assertArrayNotHasKey('progress_basis', $progress['meta']);
        $this->assertSame('critical_cards_count', $progress['meta']['sort_key']);
        $this->assertSame(0, $row['overall_mastery'], 'Leitner mastery as before');
        $this->assertArrayNotHasKey('metric', $this->poolsOf($row)[self::REQUIRED]);
    }

    // ── At-risk ──────────────────────────────────────────────────────────────────

    /** Queries of the at-risk list without Leitner: stats, sessions. */
    private function atRiskDb(?int $lastActive): FakeDbConnection {
        return new FakeDbConnection([
            new FakeQueryBuilder(new FakeResult([[
                'user_id' => 'learner', 'total_xp' => 0, 'current_level' => 1, 'current_streak' => 0, 'longest_streak' => 0,
                'total_sessions' => 0, 'total_mastered' => 0, 'last_activity_date' => null,
            ]])),
            new FakeQueryBuilder(new FakeResult($lastActive === null ? [] : [
                ['user_id' => 'learner', 'pool_id' => self::REQUIRED, 'total_q' => 10, 'correct' => 2, 'last_active' => $lastActive],
            ])),
        ]);
    }

    private function assignments(?int $dueDate): AssignmentService {
        $assignments = $this->createMock(AssignmentService::class);
        $assignments->method('getStatesForCourseAndUsers')->willReturn(
            $dueDate === null ? [] : ['learner' => ['status' => 'assigned', 'due_date' => $dueDate, 'assigned_at' => 1]]
        );
        return $assignments;
    }

    /** @return list<string> */
    private function codes(array $result): array {
        return array_map(static fn (array $c): string => $c['code'], $result['at_risk'][0]['risk_reason_codes'] ?? []);
    }

    public function testAPassedLearnerIsNeverAtRiskWithoutLeitner(): void {
        // inactive for a month, 20 % hit rate — but passed
        $result = $this->service($this->atRiskDb(time() - 30 * 86400), json_encode(['leitner' => false]), $this->passedStanding(), $this->assignments(time() - 86400))
            ->getAtRiskStudents(7, 'teacher');
        $this->assertSame([], $result['at_risk']);
        $this->assertSame('exams', $result['basis']);
    }

    public function testNotPassedWithANearDeadlineIsAtRisk(): void {
        $standing = $this->passedStanding(['passed' => false, 'score' => null, 'pools' => [self::REQUIRED => ['best_score' => null, 'passed' => false]], 'certificate_valid' => false]);
        $result = $this->service($this->atRiskDb(time()), json_encode(['leitner' => false]), $standing, $this->assignments(time() + 5 * 86400 - 60))
            ->getAtRiskStudents(7, 'teacher');

        $this->assertCount(1, $result['at_risk']);
        $this->assertSame(['not_passed', 'deadline_days'], $this->codes($result));
        $this->assertSame(5, $result['at_risk'][0]['risk_reason_codes'][1]['n']);
        $this->assertSame('medium', $result['at_risk'][0]['risk_level']);
        $this->assertSame(0, $result['at_risk'][0]['critical_cards_count']);
    }

    public function testNotPassedAloneIsNotAtRisk(): void {
        $standing = $this->passedStanding(['passed' => false, 'score' => 40]);
        $result = $this->service($this->atRiskDb(time()), json_encode(['leitner' => false]), $standing, $this->assignments(null))
            ->getAtRiskStudents(7, 'teacher');
        $this->assertSame([], $result['at_risk'], 'active, no deadline, no renewal: nothing urgent');
    }

    public function testOverdueRenewalAndInactivityAddUpToHighRisk(): void {
        $standing = $this->passedStanding(['passed' => false, 'renewal_open' => true]);
        $result = $this->service($this->atRiskDb(null), json_encode(['leitner' => false]), $standing, $this->assignments(time() - 86400))
            ->getAtRiskStudents(7, 'teacher');
        $this->assertSame(['deadline_overdue', 'renewal_open', 'never_active'], $this->codes($result), 'a valid certificate is no "not passed"');
        $this->assertSame('high', $result['at_risk'][0]['risk_level']);
    }

    public function testAValidCertificateInItsRenewalWindowIsNotCalledNotPassed(): void {
        $standing = $this->passedStanding(['passed' => false, 'certificate_valid' => true, 'renewal_open' => true]);
        $result = $this->service($this->atRiskDb(time()), json_encode(['leitner' => false]), $standing, $this->assignments(null))
            ->getAtRiskStudents(7, 'teacher');
        $this->assertSame(['renewal_open'], $this->codes($result), 'the table says "valid, renewal open", the card must not say "not passed"');
        $this->assertSame(['Erneuerung offen'], $result['at_risk'][0]['risk_reasons'] ?? null);
    }

    public function testAnExpiredCertificateStillReadsAsNotPassed(): void {
        $standing = $this->passedStanding(['passed' => false, 'certificate_valid' => false, 'certificate_expired' => true]);
        $result = $this->service($this->atRiskDb(time()), json_encode(['leitner' => false]), $standing, $this->assignments(null))
            ->getAtRiskStudents(7, 'teacher');
        $this->assertSame(['not_passed', 'certificate_expired'], $this->codes($result));
    }

    public function testNoLeitnerSignalIsReadWithoutLeitner(): void {
        $db = $this->atRiskDb(time());
        $standing = $this->passedStanding(['passed' => false, 'renewal_open' => true]);
        $result = $this->service($db, json_encode(['leitner' => false]), $standing, $this->assignments(null))
            ->getAtRiskStudents(7, 'teacher');
        $tables = array_map(static fn (FakeQueryBuilder $qb): ?string => $qb->from['table'] ?? null, $db->issuedBuilders);
        $this->assertNotContains('learning_leitner_items', $tables);
        $this->assertNotContains('critical_cards', $this->codes($result));
        $this->assertNotContains('box_one_share', $this->codes($result));
    }

    public function testWithoutCertificatesInactivityAndHitRateDecide(): void {
        $pass = $this->createMock(PassCriteriaService::class);
        $pass->method('examStandings')->willReturn(['applicable' => false, 'threshold' => 80, 'requirement' => 'any_exam', 'required_pool_ids' => [], 'users' => []]);
        $result = $this->service($this->atRiskDb(time()), json_encode(['leitner' => false]), $pass)
            ->getAtRiskStudents(7, 'teacher');
        $this->assertSame('practice', $result['basis']);
        $this->assertSame(['low_accuracy'], $this->codes($result));
    }
}
