<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Controller\SettingsController;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMember;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CoursePool;
use OCA\Learning\Db\CoursePoolMapper;
use OCA\Learning\Db\CurriculumScopeMapper;
use OCA\Learning\Db\KudosMapper;
use OCA\Learning\Db\RagChunkMapper;
use OCA\Learning\Db\UserTelosMapper;
use OCA\Learning\Service\AuditCheckpointService;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\ClassbookService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\CourseSummaryService;
use OCA\Learning\Service\FeedService;
use OCA\Learning\Service\GeminiService;
use OCA\Learning\Service\KeyService;
use OCA\Learning\Service\Llm\ApiKeyStore;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\LlmPurpose;
use OCA\Learning\Service\Llm\LlmSession;
use OCA\Learning\Service\RoleService;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\XpService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #36: with the admin's gamification switch off, the views still showed XP, level,
 * streak and badges — old frozen values from learning_user_stats or a grid of locked badges.
 * The server now hands out the switch once (personal settings) and stops serving the frozen
 * values where it reads them directly.
 */
#[AllowMockObjectsWithoutExpectations]
class GamificationOffViewsTest extends TestCase {
    private function xp(bool $enabled): XpService {
        $xp = $this->createMock(XpService::class);
        $xp->method('isGamificationEnabled')->willReturn($enabled);
        return $xp;
    }

    private function courseService(FakeDbConnection $db, bool $gamification, CourseMemberMapper $members, CoursePoolMapper $pools, ?string $modeConfig = null, ?BadgeService $badges = null): CourseService {
        $course = new Course();
        $course->setId(7);
        $course->setInstructorId('teacher');
        $course->setModeConfig($modeConfig);
        $courses = $this->createMock(CourseMapper::class);
        $courses->method('findById')->willReturn($course);

        return new CourseService(
            $courses,
            $pools,
            $members,
            $this->createMock(RoleService::class),
            $db,
            $this->createMock(IGroupManager::class),
            $this->createMock(IUserManager::class),
            $this->xp($gamification),
            $badges ?? $this->createMock(BadgeService::class),
            $this->createMock(StreakService::class),
            $this->createMock(CurriculumScopeMapper::class),
            $this->createMock(FeedService::class)
        );
    }

    private function student(string $uid = 'learner'): CourseMember {
        $member = new CourseMember();
        $member->setCourseId(7);
        $member->setUserId($uid);
        $member->setRole('student');
        $member->setEnrolledAt(1);
        return $member;
    }

    private function membersWith(CourseMember $student): CourseMemberMapper {
        $members = $this->createMock(CourseMemberMapper::class);
        $members->method('findByCourse')->willReturn([$student]);
        $members->method('findByCourseAndUser')->willReturn($student);
        return $members;
    }

    private function noPools(): CoursePoolMapper {
        $pools = $this->createMock(CoursePoolMapper::class);
        $pools->method('findByCourse')->willReturn([]);
        return $pools;
    }

    private function statsRow(array $overrides = []): FakeQueryBuilder {
        return new FakeQueryBuilder(new FakeResult([array_merge([
            'user_id' => 'learner', 'total_xp' => 900, 'current_level' => 6, 'current_streak' => 0, 'longest_streak' => 12,
            'total_sessions' => 0, 'total_mastered' => 0, 'last_activity_date' => gmdate('Y-m-d'),
        ], $overrides)]));
    }

    // ── Personal settings: the one source for the frontend ──────────────────────

    private function settings(string $appValue): SettingsController {
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturnArgument(3);
        $config->method('getAppValue')->willReturnCallback(
            static fn (string $app, string $key, $default = '') => $key === 'gamification_enabled' ? $appValue : $default
        );
        return new SettingsController(
            'learning',
            $this->createMock(IRequest::class),
            $config,
            $this->createMock(IDBConnection::class),
            $this->createMock(KeyService::class),
            $this->createMock(AuditCheckpointService::class),
            $this->createMock(ConnectionStore::class),
            $this->createMock(ApiKeyStore::class),
            $this->createMock(\OCA\Learning\Service\RoleService::class),
            'alice'
        );
    }

    public function testPersonalSettingsCarryTheGamificationSwitch(): void {
        $this->assertTrue($this->settings('yes')->getPersonal()->getData()['gamification_enabled']);
        $this->assertFalse($this->settings('no')->getPersonal()->getData()['gamification_enabled']);
    }

    // ── At-risk: no "streak lost" without streaks ───────────────────────────────

    /** @return list<string> */
    private function riskCodes(bool $gamification): array {
        // never active (+2) and few sessions (+1) keep the learner on the list either way
        $db = new FakeDbConnection([$this->statsRow(['last_activity_date' => null])]);
        $result = $this->courseService($db, $gamification, $this->membersWith($this->student()), $this->noPools())
            ->getAtRiskStudents(7, 'teacher');
        return array_map(static fn (array $c): string => $c['code'], $result['at_risk'][0]['risk_reason_codes'] ?? []);
    }

    public function testStreakLostIsAReasonOnlyWithGamification(): void {
        $this->assertContains('streak_lost', $this->riskCodes(true));
        $this->assertNotContains('streak_lost', $this->riskCodes(false));
    }

    // ── Progress table: the default sort leaves XP ──────────────────────────────

    public function testProgressSortByXpFallsBackToTheNameWithGamificationOff(): void {
        $off = $this->courseService(new FakeDbConnection([$this->statsRow()]), false, $this->membersWith($this->student()), $this->noPools())
            ->getCourseProgress(7, 'teacher', 25, 0, 'total_xp', 'desc');
        $this->assertSame('user_id', $off['meta']['sort_key']);
        $this->assertSame('asc', $off['meta']['sort_dir']);

        $on = $this->courseService(new FakeDbConnection([$this->statsRow()]), true, $this->membersWith($this->student()), $this->noPools())
            ->getCourseProgress(7, 'teacher', 25, 0, 'total_xp', 'desc');
        $this->assertSame('total_xp', $on['meta']['sort_key']);
        $this->assertSame('desc', $on['meta']['sort_dir']);
    }

    // ── Leaderboard (instructors keep it): no ranking by frozen XP ──────────────

    /** @return array{0: string, 1: string} sort key and direction the leaderboard used */
    private function leaderboardSort(bool $gamification, ?string $modeConfig): array {
        $meta = $this->courseService(new FakeDbConnection(), $gamification, $this->membersWith($this->student()), $this->noPools(), $modeConfig)
            ->getLeaderboard(7, 'teacher', 25, 0, 'total_xp', 'desc')['meta'];
        return [$meta['sort_key'], $meta['sort_dir']];
    }

    public function testLeaderboardRanksByMasteredOrSessionsWithGamificationOff(): void {
        $this->assertSame(['total_mastered', 'desc'], $this->leaderboardSort(false, null));
        $this->assertSame(['total_sessions', 'desc'], $this->leaderboardSort(false, json_encode(['leitner' => false])));
        $this->assertSame(['total_xp', 'desc'], $this->leaderboardSort(true, null));
    }

    /** @return array{0: array, 1: FakeQueryBuilder, 2: array} leaderboard rows, the row query, the whole answer */
    private function leaderboardAs(string $viewer, bool $gamification): array {
        $rows = new FakeQueryBuilder(new FakeResult([
            ['user_id' => 'learner', 'total_xp' => 900, 'current_level' => 6, 'total_mastered' => 4, 'current_streak' => 2, 'total_sessions' => 9, 'last_activity_date' => '2026-03-04'],
        ]));
        $db = new FakeDbConnection([new FakeQueryBuilder(FakeResult::fromFetchOne(1)), $rows]);
        $board = $this->courseService($db, $gamification, $this->membersWith($this->student()), $this->noPools())
            ->getLeaderboard(7, $viewer, 25, 0, 'total_mastered', 'desc');
        return [$board['leaderboard'], $rows, $board];
    }

    /**
     * With gamification off the competition tab is gone for learners (wettbewerbTabIds), and the
     * API hands them nothing either: before, a direct call still returned every fellow learner's
     * rank, name and mastered cards. Instructors keep the ranking.
     */
    public function testLeaderboardGivesLearnersNothingWithGamificationOff(): void {
        [$off, $rows, $board] = $this->leaderboardAs('learner', false);
        $this->assertSame([], $off);
        $this->assertNull($board['my_rank']);
        $this->assertSame(0, $board['meta']['total']);
        $this->assertSame([], $rows->orderBys, 'no ranking query is run');

        [$on] = $this->leaderboardAs('learner', true);
        $this->assertCount(1, $on, 'with gamification on learners keep the leaderboard');
        $this->assertSame(900, $on[0]['total_xp']);
        $this->assertSame(6, $on[0]['current_level']);

        [$teacher] = $this->leaderboardAs('teacher', false);
        $this->assertCount(1, $teacher, 'instructors keep the ranking with gamification off');
        $this->assertSame(4, $teacher[0]['total_mastered']);
    }

    public function testLeaderboardTieBreakIgnoresFrozenXpWithGamificationOff(): void {
        [, $off] = $this->leaderboardAs('teacher', false);
        $columns = array_map(static fn (array $order): string => $order['field'], $off->orderBys);
        $this->assertSame(['total_mastered', 'cm.user_id'], $columns);

        [, $on] = $this->leaderboardAs('teacher', true);
        $columns = array_map(static fn (array $order): string => $order['field'], $on->orderBys);
        $this->assertSame(['total_mastered', 'total_xp', 'cm.user_id'], $columns, 'unchanged with gamification on');
    }

    // ── Student detail (My progress / instructor drill-down) ────────────────────

    public function testStudentDetailLastActiveComesFromTheSessionsAndBadgesAreSkipped(): void {
        $badges = $this->createMock(BadgeService::class);
        $badges->expects($this->never())->method('getUserBadges');
        $badges->expects($this->never())->method('getBadgeProgress');
        // stats row without a last activity (ConsistencyCheckJob nulls it with gamification off)
        $db = new FakeDbConnection([
            $this->statsRow(['last_activity_date' => null]),
            new FakeQueryBuilder(FakeResult::fromFetchOne(gmmktime(10, 0, 0, 3, 4, 2026))),
        ]);
        $detail = $this->courseService($db, false, $this->membersWith($this->student()), $this->noPools(), null, $badges)
            ->getStudentDetail(7, 'learner', 'teacher');

        $this->assertSame('2026-03-04', $detail['stats']['last_activity_date']);
        $this->assertSame([], $detail['badges']);
        $this->assertSame([], $detail['badge_progress']);
        $this->assertTrue($detail['leitner_enabled']);
    }

    public function testStudentDetailWithoutLeitnerReadsNoBoxes(): void {
        $pool = new CoursePool();
        $pool->setCourseId(7);
        $pool->setPoolId(3);
        $pools = $this->createMock(CoursePoolMapper::class);
        $pools->method('findByCourse')->willReturn([$pool]);
        $db = new FakeDbConnection();

        $detail = $this->courseService($db, true, $this->membersWith($this->student()), $pools, json_encode(['leitner' => false]))
            ->getStudentDetail(7, 'learner', 'teacher');

        $this->assertFalse($detail['leitner_enabled']);
        $tables = array_map(static fn (FakeQueryBuilder $qb): ?string => $qb->from['table'] ?? null, $db->issuedBuilders);
        $this->assertNotContains('learning_leitner_items', $tables);
        $this->assertCount(1, $detail['pools'], 'the pool stays listed for its accuracy');
    }

    // ── Classbook: no level, no XP ──────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function classbookProfile(?XpService $xp): array {
        $courseService = $this->createMock(CourseService::class);
        $courseService->method('findById')->willReturn(['id' => 7, 'is_instructor' => true]);
        $db = new FakeDbConnection([$this->statsRow(['user_id' => 'teacher'])]);
        $teacher = new CourseMember();
        $teacher->setUserId('teacher');
        $teacher->setRole('instructor');
        $members = $this->createMock(CourseMemberMapper::class);
        $members->method('findByCourse')->willReturn([$teacher]);
        $telos = $this->createMock(UserTelosMapper::class);
        $telos->method('findByUserIds')->willReturn([]);
        $kudos = $this->createMock(KudosMapper::class);
        $kudos->method('countReceivedByCourseAndUsers')->willReturn([]);

        $service = new ClassbookService($courseService, $members, $this->noPools(), $telos, $kudos, $db, $this->createMock(IUserManager::class), $xp);
        return $service->getClassbook(7, 'teacher')['profiles'][0];
    }

    public function testClassbookHandsOutNoLevelWithGamificationOff(): void {
        $off = $this->classbookProfile($this->xp(false));
        $this->assertArrayNotHasKey('level', $off);
        $this->assertArrayNotHasKey('xp', $off);

        $on = $this->classbookProfile($this->xp(true));
        $this->assertSame(6, $on['level']);
        $this->assertSame(900, $on['xp']);
    }

    // ── Classbook: superpower fallback only with Leitner ───────────────────────

    /** @return list<?string> the tables the classbook read */
    private function classbookTables(?array $modeConfig): array {
        $courseService = $this->createMock(CourseService::class);
        $courseService->method('findById')->willReturn(['id' => 7, 'is_instructor' => true, 'mode_config' => $modeConfig]);
        $db = new FakeDbConnection([$this->statsRow(['user_id' => 'teacher'])]);
        $teacher = new CourseMember();
        $teacher->setUserId('teacher');
        $teacher->setRole('instructor');
        $members = $this->createMock(CourseMemberMapper::class);
        $members->method('findByCourse')->willReturn([$teacher]);
        $telos = $this->createMock(UserTelosMapper::class);
        $telos->method('findByUserIds')->willReturn([]);
        $kudos = $this->createMock(KudosMapper::class);
        $kudos->method('countReceivedByCourseAndUsers')->willReturn([]);
        $pool = new CoursePool();
        $pool->setCourseId(7);
        $pool->setPoolId(3);
        $pools = $this->createMock(CoursePoolMapper::class);
        $pools->method('findByCourse')->willReturn([$pool]);

        $service = new ClassbookService($courseService, $members, $pools, $telos, $kudos, $db, $this->createMock(IUserManager::class), $this->xp(true));
        $profile = $service->getClassbook(7, 'teacher')['profiles'][0];
        $this->assertArrayHasKey('superpower', $profile);
        return array_map(static fn (FakeQueryBuilder $qb): ?string => $qb->from['table'] ?? null, $db->issuedBuilders);
    }

    public function testClassbookSuperpowerFallbackReadsNoLeitnerBoxesWithoutLeitner(): void {
        $this->assertNotContains('learning_leitner_items', $this->classbookTables(['leitner' => false]));
        $this->assertContains('learning_leitner_items', $this->classbookTables(['leitner' => true]), 'unchanged with Leitner on');
        $this->assertContains('learning_leitner_items', $this->classbookTables(null), 'a missing mode config counts as Leitner on');
    }

    // ── Mode config: the classbook switch is stored ─────────────────────────────

    public function testModeConfigStoresTheClassbookSwitchDefaultOn(): void {
        $members = $this->createMock(CourseMemberMapper::class);
        $service = $this->courseService(new FakeDbConnection(), true, $members, $this->noPools());

        $off = $service->updateModeConfig(7, 'teacher', ['classbook' => false]);
        $this->assertFalse($off['classbook'], 'a switched-off classbook is not dropped on save');

        $default = $service->updateModeConfig(7, 'teacher', []);
        $this->assertTrue($default['classbook'], 'a course saved without the key keeps the classbook');
    }

    // ── New course: no competition modes on an instance without gamification ───

    private function createdModeConfig(bool $gamification): ?string {
        $courses = $this->createMock(CourseMapper::class);
        $courses->method('insert')->willReturnArgument(0);
        $roles = $this->createMock(RoleService::class);
        $roles->method('isInstructor')->willReturn(true);
        $service = new CourseService(
            $courses,
            $this->noPools(),
            $this->createMock(CourseMemberMapper::class),
            $roles,
            new FakeDbConnection(),
            $this->createMock(IGroupManager::class),
            $this->createMock(IUserManager::class),
            $this->xp($gamification),
            $this->createMock(BadgeService::class),
            $this->createMock(StreakService::class),
            $this->createMock(CurriculumScopeMapper::class),
            $this->createMock(FeedService::class)
        );
        return $service->create('Brandschutz', null, null, 'teacher')->getModeConfig();
    }

    public function testNewCourseStartsWithoutDuelGameshowAndLeagueWithGamificationOff(): void {
        $config = json_decode((string)$this->createdModeConfig(false), true);
        $this->assertIsArray($config);
        $this->assertFalse($config['duel']);
        $this->assertFalse($config['gameshow']);
        $this->assertFalse($config['league']);
        $this->assertFalse($config['abenteuer'], 'the adventure keeps its default (off)');
        $this->assertTrue($config['training']);
        $this->assertTrue($config['leitner']);
        $this->assertTrue($config['exam']);
        $this->assertTrue($config['classbook']);
    }

    public function testNewCourseKeepsTheEmptyModeConfigWithGamificationOn(): void {
        $this->assertNull($this->createdModeConfig(true));
    }

    // ── Course summary + AI reflection ──────────────────────────────────────────

    private function summaryService(bool $gamification, GeminiService $gemini, ?StreakService $streak = null): CourseSummaryService {
        return $this->getMockBuilder(CourseSummaryService::class)
            ->setConstructorArgs([
                new FakeDbConnection([]),
                $this->createMock(CourseMemberMapper::class),
                $this->createMock(BadgeService::class),
                $streak ?? $this->createMock(StreakService::class),
                $this->createMock(RagChunkMapper::class),
                $gemini,
                $this->xp($gamification),
            ])
            ->onlyMethods(['getSnapshot', 'getCoursePoolIds', 'getMasteryStats'])
            ->getMock();
    }

    public function testSummaryCarriesNoXpStreakBadgesOrDuelsWithGamificationOff(): void {
        $streak = $this->createMock(StreakService::class);
        $streak->expects($this->never())->method('getStreak');
        $service = $this->summaryService(false, $this->createMock(GeminiService::class), $streak);
        $service->method('getCoursePoolIds')->willReturn([]);
        $service->method('getMasteryStats')->willReturn(['total_mastered' => 0]);

        $summary = $service->getStudentSummary(7, 'alice');

        $this->assertSame([], $summary['xp']);
        $this->assertSame([], $summary['badges']);
        $this->assertSame([], $summary['streak']);
        $this->assertSame([], $summary['duels']);
    }

    private function narrativePrompt(bool $gamification): string {
        $gemini = $this->createMock(GeminiService::class);
        $gemini->method('begin')->willReturn(new LlmSession(ConnectionStore::legacyGemini(true), 'alice', LlmPurpose::CourseNarrative, 0));
        $prompt = '';
        $gemini->method('generateNote')->willReturnCallback(function (string $sys, string $user) use (&$prompt): string {
            $prompt = $user;
            return 'Narrative';
        });
        $service = $this->summaryService($gamification, $gemini);
        $service->method('getSnapshot')->willReturn(null);
        $service->method('getCoursePoolIds')->willReturn([]);
        $service->method('getMasteryStats')->willReturn(['total_mastered' => 0]);
        $service->generateAndCacheNarrative(7, 'alice');
        return $prompt;
    }

    public function testNarrativePromptLeavesOutXpStreakAndBadgesWithGamificationOff(): void {
        $off = $this->narrativePrompt(false);
        $this->assertStringNotContainsString('XP:', $off);
        $this->assertStringNotContainsString('Streak', $off);
        $this->assertStringNotContainsString('BADGES', $off);
        $this->assertStringContainsString('Gesamtgenauigkeit', $off);

        $on = $this->narrativePrompt(true);
        $this->assertStringContainsString('XP:', $on);
        $this->assertStringContainsString('Streak (max)', $on);
    }
}
