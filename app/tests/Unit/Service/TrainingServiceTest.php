<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Answer;
use OCA\Learning\Db\AnswerMapper;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\PoolShareMapper;
use OCA\Learning\Db\Question;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\TrainingService;
use OCA\Learning\Service\TranslationService;
use OCA\Learning\Service\VideoProgressService;
use OCA\Learning\Service\XpService;
use OCA\Learning\Service\LernprofilService;
use OCA\Learning\Service\NoteGeneratorService;
use OCA\Learning\Service\TelosService;
use OCA\Learning\Tests\Support\FakeCacheFactory;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TrainingServiceTest extends TestCase {
    public function testStartSessionUsesRequestedPoolAndPersistsQuestionOrder(): void {
        $activeExamBuilder = new FakeQueryBuilder(FakeResult::fromFetch(false));
        $insertBuilder = new FakeQueryBuilder(new FakeResult(), 0, 99);
        $db = new FakeDbConnection([$activeExamBuilder, $insertBuilder]);

        $questionMapper = $this->createMock(QuestionMapper::class);
        $answerMapper = $this->createMock(AnswerMapper::class);
        $poolMapper = $this->createMock(PoolMapper::class);

        $questions = [
            $this->makeQuestion(10, 42, 'First'),
            $this->makeQuestion(11, 42, 'Second'),
        ];

        $questionMapper->expects($this->exactly(2))
            ->method('findByPoolId')
            ->with(42)
            ->willReturn($questions);
        $answerMapper->method('findByQuestion')
            ->willReturnCallback(fn(int $questionId): array => [$this->makeAnswer($questionId * 10, $questionId, 'Answer')]);
        $poolMapper->method('find')->with(42, 'alice')->willReturn(new \OCA\Learning\Db\Pool());

        $service = $this->createService(
            db: $db,
            questionMapper: $questionMapper,
            answerMapper: $answerMapper,
            poolMapper: $poolMapper
        );

        $payload = $service->startSession(42, 'alice', null, 'training');

        $this->assertSame(99, $payload['session_id']);
        $this->assertSame(2, $payload['total_questions']);
        $this->assertFalse($payload['resumed']);
        $this->assertEqualsCanonicalizing([10, 11], array_column($payload['questions'], 'id'));
        $this->assertSame(42, $insertBuilder->insertValues['pool_id']['value']);
        $this->assertSame('training', $insertBuilder->insertValues['mode']['value']);
        $this->assertSame(2, $insertBuilder->insertValues['total_questions']['value']);
    }

    public function testExamStartPayloadStripsExplanationsAndPbqSolutionConfig(): void {
        $activeExamBuilder = new FakeQueryBuilder(FakeResult::fromFetch(false));
        $closeTrainingBuilder = new FakeQueryBuilder(new FakeResult(), 1);
        $attemptLimitBuilder = new FakeQueryBuilder(FakeResult::fromFetchOne(0));
        $insertBuilder = new FakeQueryBuilder(new FakeResult(), 0, 99);
        $db = new FakeDbConnection([$activeExamBuilder, $closeTrainingBuilder, $attemptLimitBuilder, $insertBuilder]);

        $question = $this->makeQuestion(10, 42, 'PBQ');
        $question->setQuestionType('pbq');
        $question->setPbqSubtype('placement');
        $question->setPbqConfig(json_encode([
            'instructions' => ['Place the devices'],
            'positions' => [
                ['id' => 'edge', 'label' => 'Edge', 'correct' => 'Firewall'],
            ],
            'device_options' => ['Firewall', 'Switch'],
        ]));
        $question->setExplanation('Never reveal during exam');
        $question->setInstructorNote('Instructor-only note');
        $question->setNoteVisible(true);
        $question->setExamKey('SY0-701');

        $questionMapper = $this->createMock(QuestionMapper::class);
        $questionMapper->expects($this->exactly(2))
            ->method('findByPoolId')
            ->with(42)
            ->willReturn([$question]);

        $answerMapper = $this->createMock(AnswerMapper::class);
        $answerMapper->method('findByQuestion')
            ->with(10)
            ->willReturn([$this->makeAnswer(100, 10, 'Answer')]);

        $poolMapper = $this->createMock(PoolMapper::class);
        $poolMapper->method('find')->with(42, 'alice')->willReturn(new \OCA\Learning\Db\Pool());

        $service = $this->createService(
            db: $db,
            questionMapper: $questionMapper,
            answerMapper: $answerMapper,
            poolMapper: $poolMapper
        );

        $payload = $service->startSession(42, 'alice', null, 'exam');
        $questionPayload = $payload['questions'][0];

        $this->assertArrayNotHasKey('explanation', $questionPayload);
        $this->assertArrayNotHasKey('instructor_note', $questionPayload);
        $this->assertArrayNotHasKey('note_visible', $questionPayload);
        $this->assertArrayNotHasKey('exam_key', $questionPayload);
        $this->assertArrayNotHasKey('is_correct', $questionPayload['answers'][0]);
        $this->assertSame(['Place the devices'], $questionPayload['pbq_config']['instructions']);
        $this->assertSame('Edge', $questionPayload['pbq_config']['positions'][0]['label']);
        $this->assertArrayNotHasKey('correct', $questionPayload['pbq_config']['positions'][0]);
    }

    public function testExamStartResumesActiveExamAcrossCourseContextMismatch(): void {
        $activeExamBuilder = new FakeQueryBuilder(FakeResult::fromFetch([
            'id' => 77,
            'pool_id' => 42,
            'course_id' => 7,
            'user_id' => 'alice',
            'started_at' => time(),
            'total_questions' => 1,
            'correct_answers' => 0,
            'mode' => 'exam',
            'completed_at' => null,
            'time_limit_seconds' => 600,
            'attempt_no' => 1,
            'question_order_json' => '[10]',
        ]));
        $db = new FakeDbConnection([$activeExamBuilder]);

        $questionMapper = $this->createMock(QuestionMapper::class);
        $questionMapper->expects($this->once())
            ->method('findByPoolId')
            ->with(42)
            ->willReturn([$this->makeQuestion(10, 42, 'Existing')]);

        $answerMapper = $this->createMock(AnswerMapper::class);
        $answerMapper->method('findByQuestion')
            ->with(10)
            ->willReturn([$this->makeAnswer(100, 10, 'Answer')]);

        $poolMapper = $this->createMock(PoolMapper::class);
        $poolMapper->method('find')->with(42, 'alice')->willReturn(new \OCA\Learning\Db\Pool());

        $service = $this->createService(
            db: $db,
            questionMapper: $questionMapper,
            answerMapper: $answerMapper,
            poolMapper: $poolMapper
        );

        $payload = $service->startSession(42, 'alice', null, 'exam', null, null, null);

        $this->assertSame(77, $payload['session_id']);
        $this->assertTrue($payload['resumed']);
        $this->assertCount(1, $payload['questions']);
        foreach ($db->issuedBuilders as $builder) {
            $this->assertFalse(
                $builder->table === 'learning_sessions' && in_array($builder->operation, ['insert', 'update'], true),
                'No broad auto-complete or new session insert should run when an active exam is resumed.'
            );
        }
    }

    public function testExamBatchPbqCorrectAnswerIncrementsScoreWhileSuppressingFeedback(): void {
        $sessionBuilder = new FakeQueryBuilder(FakeResult::fromFetch([
            'id' => 9,
            'pool_id' => 42,
            'user_id' => 'alice',
            'started_at' => time(),
            'total_questions' => 1,
            'correct_answers' => 0,
            'mode' => 'exam',
            'completed_at' => null,
            'question_order_json' => '[10]',
        ]));
        $questionInPoolBuilder = new FakeQueryBuilder(FakeResult::fromFetch(['id' => 10]));
        $duplicateBuilder = new FakeQueryBuilder(FakeResult::fromFetch(false));
        $questionTypeBuilder = new FakeQueryBuilder(FakeResult::fromFetch(['question_type' => 'pbq']));
        $pbqConfigBuilder = new FakeQueryBuilder(FakeResult::fromFetch([
            'pbq_subtype' => 'dropdown',
            'pbq_config' => json_encode([
                'questions' => [
                    ['id' => 'q1', 'label' => 'Port', 'correct' => '443'],
                ],
            ]),
        ]));
        $answerInsertBuilder = new FakeQueryBuilder(new FakeResult(), 1);
        $db = new FakeDbConnection([
            $sessionBuilder,
            $questionInPoolBuilder,
            $duplicateBuilder,
            $questionTypeBuilder,
            $pbqConfigBuilder,
            $answerInsertBuilder,
        ]);

        $service = $this->createService(db: $db);

        $result = $service->submitBatch(9, [[
            'questionId' => 10,
            'pbqAnswers' => ['q1' => '443'],
        ]], 'alice');

        $this->assertSame([[
            'questionId' => 10,
            'recorded' => true,
            'suppressed' => true,
        ]], $result);
        $this->assertCount(1, $db->executedStatements);
        $this->assertStringContainsString('correct_answers = correct_answers + 1', $db->executedStatements[0]['sql']);
        $this->assertSame(['id' => 9], $db->executedStatements[0]['params']);
    }

    /**
     * VIDEO-03: on a gated course whose required video is incomplete, startSession() must throw
     * ForbiddenException (→ 403) and NEVER reach resolveCoursePoolContext / the session insert.
     */
    public function testStartSessionThrowsWhenVideoGateEnabledAndIncomplete(): void {
        $activeExamBuilder = new FakeQueryBuilder(FakeResult::fromFetch(false));
        $db = new FakeDbConnection([$activeExamBuilder]);

        $poolMapper = $this->createMock(PoolMapper::class);
        $poolMapper->method('find')->with(42, 'alice')->willReturn(new \OCA\Learning\Db\Pool());

        $courseService = $this->createMock(CourseService::class);
        // Unified gate: derived from the pool→enrolled-gated-course lookup (course 7 gates pool 42).
        $courseService->method('getGatedCourseIdsForPool')->with(42, 'alice')->willReturn([7]);
        // The gate is BEFORE any session work — resolveCoursePoolContext must never be reached.
        $courseService->expects($this->never())->method('resolveCoursePoolContext');

        $videoProgressService = $this->createMock(VideoProgressService::class);
        $videoProgressService->expects($this->once())
            ->method('assertCourseVideosComplete')
            ->with(7, 'alice')
            ->willThrowException(new ForbiddenException('Required video/material not completed'));

        $service = $this->createService(
            db: $db,
            poolMapper: $poolMapper,
            courseService: $courseService,
            videoProgressService: $videoProgressService
        );

        $this->expectException(ForbiddenException::class);
        $service->startSession(42, 'alice', null, 'training', null, null, 7);
    }

    /**
     * CODEX BLOCKER (gate bypass): starting a gated course's pool with courseId OMITTED must STILL
     * enforce the gate. The pool (42) belongs to gated course 7 (getGatedCourseIdsForPool), so even
     * though the client passes courseId=null, assertCourseVideosComplete(7) runs and throws.
     */
    public function testStartSessionGateCannotBeBypassedByOmittingCourseId(): void {
        $activeExamBuilder = new FakeQueryBuilder(FakeResult::fromFetch(false));
        $db = new FakeDbConnection([$activeExamBuilder]);

        $poolMapper = $this->createMock(PoolMapper::class);
        $poolMapper->method('find')->with(42, 'alice')->willReturn(new \OCA\Learning\Db\Pool());

        $courseService = $this->createMock(CourseService::class);
        // courseId is null, so isVideoGateEnabled is never consulted; the pool→gated-course reverse
        // lookup is what closes the bypass.
        $courseService->expects($this->never())->method('isVideoGateEnabled');
        $courseService->method('getGatedCourseIdsForPool')->with(42, 'alice')->willReturn([7]);

        $videoProgressService = $this->createMock(VideoProgressService::class);
        $videoProgressService->expects($this->once())
            ->method('assertCourseVideosComplete')
            ->with(7, 'alice')
            ->willThrowException(new ForbiddenException('Required video/material not completed'));

        $service = $this->createService(
            db: $db,
            poolMapper: $poolMapper,
            courseService: $courseService,
            videoProgressService: $videoProgressService
        );

        $this->expectException(ForbiddenException::class);
        $service->startSession(42, 'alice', null, 'training'); // NOTE: courseId omitted → still gated
    }

    /**
     * VIDEO-03: when the course gate is turned OFF, the completion assertion must never be invoked —
     * the gate engages solely on the instructor-set video_gate_enabled flag — and startSession proceeds.
     */
    public function testStartSessionSkipsGateWhenDisabled(): void {
        $service = $this->buildGatedCourseService(
            gateEnabled: false,
            expectAssertion: false,
            insertBuilder: $insertBuilder
        );

        $payload = $service->startSession(42, 'alice', null, 'training', null, null, 7);

        $this->assertSame(99, $payload['session_id']);
        $this->assertSame(7, $insertBuilder->insertValues['course_id']['value']);
    }

    /**
     * VIDEO-03: when the gate is enabled AND completion is satisfied (assertCourseVideosComplete does
     * not throw), startSession proceeds and writes the session row.
     */
    public function testStartSessionProceedsWhenGateSatisfied(): void {
        $service = $this->buildGatedCourseService(
            gateEnabled: true,
            expectAssertion: true,
            insertBuilder: $insertBuilder
        );

        $payload = $service->startSession(42, 'alice', null, 'training', null, null, 7);

        $this->assertSame(99, $payload['session_id']);
        $this->assertSame(2, $payload['total_questions']);
    }

    /**
     * Shared harness for the gate-disabled and gate-satisfied course paths: a course (id 7) on pool 42
     * that resolves to two questions and inserts a session with generated id 99.
     */
    private function buildGatedCourseService(bool $gateEnabled, bool $expectAssertion, &$insertBuilder): TrainingService {
        $activeExamBuilder = new FakeQueryBuilder(FakeResult::fromFetch(false));
        $insertBuilder = new FakeQueryBuilder(new FakeResult(), 0, 99);
        $db = new FakeDbConnection([$activeExamBuilder, $insertBuilder]);

        $questions = [
            $this->makeQuestion(10, 42, 'First'),
            $this->makeQuestion(11, 42, 'Second'),
        ];

        $questionMapper = $this->createMock(QuestionMapper::class);
        $questionMapper->method('findByIds')->with([10, 11])->willReturn($questions);
        $questionMapper->method('findByPoolId')->with(42)->willReturn($questions);

        $answerMapper = $this->createMock(AnswerMapper::class);
        $answerMapper->method('findByQuestion')
            ->willReturnCallback(fn(int $questionId): array => [$this->makeAnswer($questionId * 10, $questionId, 'Answer')]);

        $poolMapper = $this->createMock(PoolMapper::class);
        $poolMapper->method('find')->with(42, 'alice')->willReturn(new \OCA\Learning\Db\Pool());

        $courseService = $this->createMock(CourseService::class);
        // The gate is derived purely from the pool→enrolled-gated-course lookup (unified after Codex
        // re-review): a gated pool returns [7], an ungated one returns [].
        $courseService->method('getGatedCourseIdsForPool')->with(42, 'alice')->willReturn($gateEnabled ? [7] : []);
        $courseService->method('resolveCoursePoolContext')
            ->with(7, 42, 'alice')
            ->willReturn(['question_ids' => [10, 11]]);

        $videoProgressService = $this->createMock(VideoProgressService::class);
        $videoProgressService->expects($expectAssertion ? $this->once() : $this->never())
            ->method('assertCourseVideosComplete');

        return $this->createService(
            db: $db,
            questionMapper: $questionMapper,
            answerMapper: $answerMapper,
            poolMapper: $poolMapper,
            courseService: $courseService,
            videoProgressService: $videoProgressService
        );
    }

    public function testCompleteSessionAwardsXpUpdatesCacheAndMergesBadges(): void {
        $db = new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetch([
                'id' => 9,
                'pool_id' => 42,
                'user_id' => 'alice',
                'started_at' => 100,
                'total_questions' => 4,
                'correct_answers' => 3,
                'mode' => 'training',
                'completed_at' => null,
            ])),
            new FakeQueryBuilder(new FakeResult(), 1),
            new FakeQueryBuilder(FakeResult::fromFetch([
                'id' => 9,
                'pool_id' => 42,
                'user_id' => 'alice',
                'started_at' => 100,
                'total_questions' => 4,
                'correct_answers' => 3,
                'mode' => 'training',
                'completed_at' => 200,
            ])),
            new FakeQueryBuilder(FakeResult::fromFetch(['avg_pct' => 50])),
        ]);

        $badgeService = $this->createMock(BadgeService::class);
        $streakService = $this->createMock(StreakService::class);
        $xpService = $this->createMock(XpService::class);
        $cacheFactory = new FakeCacheFactory();

        $badgeService->expects($this->exactly(2))
            ->method('checkAndAward')
            ->willReturnOnConsecutiveCalls(
                [['badge_id' => 'session']],
                [['badge_id' => 'streak']]
            );
        $streakService->expects($this->once())
            ->method('getStreak')
            ->with('alice', true)
            ->willReturn(['current_streak' => 3]);
        $xpService->expects($this->exactly(2))
            ->method('calculateXp')
            ->with('alice')
            ->willReturnOnConsecutiveCalls(['level' => 1], ['level' => 2]);
        $xpService->expects($this->once())
            ->method('calculateSessionXp')
            ->with(
                [
                    'mode' => 'training',
                    'total_questions' => 4,
                    'correct_answers' => 3,
                    'completed_at' => 200,
                    'started_at' => 100,
                    'time_limit_seconds' => 0,
                ],
                3
            )
            ->willReturn(35);
        $xpService->expects($this->once())
            ->method('incrementSessionXp')
            ->with('alice', 35, 3);

        $service = $this->createService(
            db: $db,
            badgeService: $badgeService,
            streakService: $streakService,
            xpService: $xpService,
            cacheFactory: $cacheFactory
        );

        $result = $service->completeSession(9, 'alice');

        $this->assertSame(75.0, $result['score_percentage']);
        $this->assertSame(35, $result['xp_earned']);
        $this->assertSame(1, $result['level_before']);
        $this->assertSame(2, $result['level_after']);
        $this->assertSame(50.0, $result['average_accuracy']);
        $this->assertTrue($result['is_personal_best']);
        $this->assertSame(25.0, $result['improvement']);
        $this->assertSame(
            [['badge_id' => 'session'], ['badge_id' => 'streak']],
            $result['newly_earned_badges']
        );
        $this->assertSame(['user_state_alice'], $cacheFactory->cache->removedKeys);
    }

    // ── Codeberg #9: per-course practice exams ────────────────────────────────────────────────

    private function practiceCourse(bool $enabled = true, int $questions = 3, int $minutes = 0, int $pass = 75): \OCA\Learning\Db\Course {
        $course = new \OCA\Learning\Db\Course();
        $course->setId(7);
        $course->setPracticeEnabled($enabled);
        $course->setPracticeQuestions($questions);
        $course->setPracticeMinutes($minutes);
        $course->setPracticePassPercent($pass);
        return $course;
    }

    /** @param int[] $questionIds @param int[] $poolIds */
    private function practiceCourseService(\OCA\Learning\Db\Course $course, array $questionIds, array $poolIds, bool $requiredOnly = false): CourseService {
        $courseService = $this->createMock(CourseService::class);
        $courseService->method('getGatedCourseIdsForPool')->willReturn([]);
        $courseService->method('resolveCoursePracticeContext')->willReturn([
            'course' => $course,
            'is_instructor' => false,
            'question_ids' => $questionIds,
            'pool_ids' => $poolIds,
            'required_only' => $requiredOnly,
        ]);
        return $courseService;
    }

    /**
     * The draw spans every pool of the course, takes exactly the configured count, and the
     * session is marked practice with the threshold stored at start. 0 minutes = no time limit.
     * The payload must carry questions from BOTH pools: loading by the session's pool_id alone
     * silently dropped every question from the other pools.
     */
    public function testPracticeExamDrawsAcrossPoolsAndMarksSession(): void {
        $pool42 = [$this->makeQuestion(10, 42, 'A1'), $this->makeQuestion(11, 42, 'A2')];
        $pool43 = [$this->makeQuestion(20, 43, 'B1'), $this->makeQuestion(21, 43, 'B2')];
        $all = array_merge($pool42, $pool43);

        $resume = new FakeQueryBuilder(FakeResult::fromFetch(false));
        $active42 = new FakeQueryBuilder(FakeResult::fromFetchAll([]));
        $active43 = new FakeQueryBuilder(FakeResult::fromFetchAll([]));
        $limit24h = new FakeQueryBuilder(FakeResult::fromFetchOne(0));
        $attemptNo = new FakeQueryBuilder(FakeResult::fromFetchOne(2));
        $insert = new FakeQueryBuilder(new FakeResult(), 0, 501);
        $db = new FakeDbConnection([$resume, $active42, $active43, $limit24h, $attemptNo, $insert]);

        $questionMapper = $this->createMock(QuestionMapper::class);
        $questionMapper->method('findByIds')->willReturnCallback(
            static fn(array $ids): array => array_values(array_filter($all, static fn($q) => in_array($q->getId(), $ids, true)))
        );
        $questionMapper->method('findByPoolId')->willReturnCallback(
            static fn(int $poolId): array => $poolId === 42 ? $pool42 : $pool43
        );
        $answerMapper = $this->createMock(AnswerMapper::class);
        $answerMapper->method('findByQuestion')
            ->willReturnCallback(fn(int $qid): array => [$this->makeAnswer($qid * 10, $qid, 'Answer')]);

        $service = $this->createService(
            db: $db,
            questionMapper: $questionMapper,
            answerMapper: $answerMapper,
            courseService: $this->practiceCourseService($this->practiceCourse(questions: 4, minutes: 0, pass: 70), [10, 11, 20, 21], [42, 43]),
        );

        $payload = $service->startPracticeExam(7, 'alice');

        $this->assertSame('practice', $insert->insertValues['exam_kind']['value']);
        $this->assertSame('exam', $insert->insertValues['mode']['value']);
        $this->assertSame(70, $insert->insertValues['pass_percent']['value']);
        $this->assertNull($insert->insertValues['time_limit_seconds']['value'], '0 minutes = untimed');
        $this->assertSame(42, $insert->insertValues['pool_id']['value'], 'anchor = smallest involved pool');
        $this->assertSame(3, $insert->insertValues['attempt_no']['value']);
        $this->assertSame(4, $insert->insertValues['total_questions']['value']);
        $this->assertEqualsCanonicalizing([10, 11, 20, 21], array_column($payload['questions'], 'id'));
        $this->assertSame('practice', $payload['exam_kind']);
        $this->assertNull($payload['exam_deadline_at']);
    }

    /** The configured count is exact — also when PBQs are in the pool (the exam blueprint forces them all in). */
    public function testPracticeExamTakesExactlyTheConfiguredCount(): void {
        $all = [];
        for ($i = 1; $i <= 10; $i++) {
            $q = $this->makeQuestion($i, 42, 'Q' . $i);
            if ($i <= 4) {
                $q->setQuestionType('pbq');
            }
            $all[] = $q;
        }
        $insert = new FakeQueryBuilder(new FakeResult(), 0, 502);
        $db = new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetch(false)),
            new FakeQueryBuilder(FakeResult::fromFetchAll([])),
            new FakeQueryBuilder(FakeResult::fromFetchOne(0)),
            new FakeQueryBuilder(FakeResult::fromFetchOne(0)),
            $insert,
        ]);
        $questionMapper = $this->createMock(QuestionMapper::class);
        $questionMapper->method('findByIds')->willReturnCallback(
            static fn(array $ids): array => array_values(array_filter($all, static fn($q) => in_array($q->getId(), $ids, true)))
        );
        $questionMapper->method('findByPoolId')->willReturn($all);
        $service = $this->createService(
            db: $db,
            questionMapper: $questionMapper,
            courseService: $this->practiceCourseService($this->practiceCourse(questions: 3, minutes: 45), range(1, 10), [42]),
        );

        $payload = $service->startPracticeExam(7, 'alice');

        $this->assertSame(3, $insert->insertValues['total_questions']['value']);
        $this->assertCount(3, json_decode($insert->insertValues['question_order_json']['value'], true));
        $this->assertSame(45 * 60, $insert->insertValues['time_limit_seconds']['value']);
        $this->assertCount(3, $payload['questions']);
    }

    /**
     * Codeberg #9 follow-up: limited to required pools with none marked is the instructor's to
     * fix, so it must not read as the generic "no questions" — and must not create a session.
     */
    public function testPracticeExamLimitedToRequiredPoolsRefusesWhenNoneIsMarked(): void {
        $insert = new FakeQueryBuilder(new FakeResult(), 0, 503);
        $service = $this->createService(
            db: new FakeDbConnection([$insert]),
            courseService: $this->practiceCourseService($this->practiceCourse(), [], [], requiredOnly: true),
        );
        try {
            $service->startPracticeExam(7, 'alice');
            $this->fail('must refuse');
        } catch (\Exception $e) {
            $this->assertSame(TrainingService::PRACTICE_NO_REQUIRED_POOLS, $e->getMessage());
        }
        $this->assertSame([], $insert->insertValues);
    }

    public function testPracticeExamRefusedWhenDisabled(): void {
        $service = $this->createService(
            db: new FakeDbConnection([]),
            courseService: $this->practiceCourseService($this->practiceCourse(enabled: false), [10], [42]),
        );
        $this->expectExceptionMessage('Practice exams are not enabled for this course');
        $service->startPracticeExam(7, 'alice');
    }

    /**
     * A practice review reveals answers; while a certificate-relevant exam runs on one of the
     * course's pools, starting a practice exam would turn it into an answer oracle.
     */
    public function testPracticeExamRefusedWhileStandardExamRunsOnACoursePool(): void {
        $running = ['id' => 9, 'mode' => 'exam', 'pool_id' => 43, 'user_id' => 'alice', 'started_at' => time() - 60,
            'time_limit_seconds' => 3600, 'completed_at' => null];
        $insert = new FakeQueryBuilder(new FakeResult(), 0, 503);
        $db = new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetch(false)),
            new FakeQueryBuilder(FakeResult::fromFetchAll([])),
            new FakeQueryBuilder(FakeResult::fromFetchAll([$running])),
            $insert,
        ]);
        $service = $this->createService(
            db: $db,
            courseService: $this->practiceCourseService($this->practiceCourse(), [10, 20], [42, 43]),
        );
        try {
            $service->startPracticeExam(7, 'alice');
            $this->fail('must refuse');
        } catch (\Exception $e) {
            $this->assertStringContainsString('exam is active', $e->getMessage());
        }
        $this->assertSame([], $insert->insertValues);
    }

    private function completedPracticeSession(int $correct, int $total, int $pass): array {
        return ['id' => 600, 'pool_id' => 42, 'course_id' => 7, 'user_id' => 'alice', 'mode' => 'exam',
            'exam_kind' => 'practice', 'pass_percent' => $pass, 'started_at' => time() - 600,
            'completed_at' => time() - 10, 'time_limit_seconds' => null, 'total_questions' => $total,
            'correct_answers' => $correct, 'attempt_no' => 1, 'question_order_json' => json_encode([10, 20])];
    }

    private function practiceQuestionMapper(): QuestionMapper {
        $questionMapper = $this->createMock(QuestionMapper::class);
        $questionMapper->method('findByIds')->willReturn([$this->makeQuestion(10, 42, 'A'), $this->makeQuestion(20, 43, 'B')]);
        return $questionMapper;
    }

    /**
     * Pass verdict is integer math against the threshold stored at start: 74.6 % rounds to 75
     * but must not pass 75 %. The review carries the explanation (practice only).
     */
    public function testPracticeCompletionVerdictAndExplanations(): void {
        $session = $this->completedPracticeSession(97, 130, 75); // 74.6 %
        $uaRow = ['id' => 1, 'question_id' => 10, 'is_correct' => '1', 'answer_id' => 100, 'answer_ids' => null,
            'question_text' => 'A', 'question_type' => 'single', 'question_explanation' => 'Because of ICAO Annex 10.'];
        $db = new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetch($session)),          // verifySessionOwnership
            new FakeQueryBuilder(FakeResult::fromFetchAll([])),             // active exam on pool 42
            new FakeQueryBuilder(FakeResult::fromFetchAll([])),             // active exam on pool 43
            new FakeQueryBuilder(FakeResult::fromFetchAll([$uaRow])),       // review: user answers
            new FakeQueryBuilder(FakeResult::fromFetchAll([['id' => 100, 'text' => 'Yes']])), // correct answers
            new FakeQueryBuilder(FakeResult::fromFetchAll([['id' => 100, 'text' => 'Yes', 'is_correct' => '1']])), // all answers
        ]);
        $service = $this->createService(db: $db, questionMapper: $this->practiceQuestionMapper());

        $result = $service->completeSession(600, 'alice');

        $this->assertSame('practice', $result['exam_kind']);
        $this->assertSame(75, $result['pass_percent']);
        $this->assertFalse($result['passed'], '74.6 % must not pass 75 %');
        $this->assertFalse($result['review_withheld']);
        $this->assertSame('Because of ICAO Annex 10.', $result['review'][0]['explanation']);
    }

    public function testPracticeCompletionPassesAtThreshold(): void {
        $db = new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetch($this->completedPracticeSession(3, 4, 75))),
            new FakeQueryBuilder(FakeResult::fromFetchAll([])),
            new FakeQueryBuilder(FakeResult::fromFetchAll([])),
            new FakeQueryBuilder(FakeResult::fromFetchAll([])),
        ]);
        $service = $this->createService(db: $db, questionMapper: $this->practiceQuestionMapper());

        $this->assertTrue($service->completeSession(600, 'alice')['passed']);
    }

    /**
     * complete() on an OLD practice session while a certificate-relevant exam runs on one of its
     * pools must not hand out the review — that path is the one an attacker would use.
     */
    public function testPracticeReviewWithheldWhileStandardExamRuns(): void {
        $running = ['id' => 9, 'mode' => 'exam', 'pool_id' => 43, 'user_id' => 'alice', 'started_at' => time() - 60,
            'time_limit_seconds' => 3600, 'completed_at' => null];
        $reviewQuery = new FakeQueryBuilder(FakeResult::fromFetchAll([['id' => 1]]));
        $db = new FakeDbConnection([
            new FakeQueryBuilder(FakeResult::fromFetch($this->completedPracticeSession(1, 2, 50))),
            new FakeQueryBuilder(FakeResult::fromFetchAll([])),
            new FakeQueryBuilder(FakeResult::fromFetchAll([$running])),
            $reviewQuery,
        ]);
        $service = $this->createService(db: $db, questionMapper: $this->practiceQuestionMapper());

        $result = $service->completeSession(600, 'alice');

        $this->assertTrue($result['review_withheld']);
        $this->assertSame([], $result['review']);
        $this->assertSame([], $reviewQuery->selects, 'the review query must never run');
    }

    private function createService(
        FakeDbConnection $db,
        ?QuestionMapper $questionMapper = null,
        ?AnswerMapper $answerMapper = null,
        ?PoolMapper $poolMapper = null,
        ?BadgeService $badgeService = null,
        ?StreakService $streakService = null,
        ?XpService $xpService = null,
        ?FakeCacheFactory $cacheFactory = null,
        ?CourseService $courseService = null,
        ?VideoProgressService $videoProgressService = null
    ): TrainingService {
        $questionMapper ??= $this->createMock(QuestionMapper::class);
        $answerMapper ??= $this->createMock(AnswerMapper::class);
        $poolMapper ??= $this->createMock(PoolMapper::class);
        $shareMapper = $this->createMock(PoolShareMapper::class);
        $badgeService ??= $this->createMock(BadgeService::class);
        $streakService ??= $this->createMock(StreakService::class);
        $xpService ??= $this->createMock(XpService::class);
        $cacheFactory ??= new FakeCacheFactory();
        $translationService = $this->createMock(TranslationService::class);
        $config = $this->createMock(IConfig::class);
        $logger = $this->createMock(LoggerInterface::class);
        if ($courseService === null) {
            $courseService = $this->createMock(CourseService::class);
            // Default: the pool belongs to no gated course (the gate-bypass lookup returns empty).
            $courseService->method('getGatedCourseIdsForPool')->willReturn([]);
        }
        $videoProgressService ??= $this->createMock(VideoProgressService::class);

        $translationService->method('normalizeLang')
            ->willReturnCallback(static fn(?string $lang): ?string => $lang === '' ? null : $lang);
        $translationService->method('translateQuestions')
            ->willReturnCallback(static fn(array $questions): array => $questions);
        $translationService->method('translateReviewEntries')
            ->willReturnCallback(static fn(array $entries): array => $entries);
        $config->method('getUserValue')->willReturn('');
        // logger->info() is void — no willReturn needed, mock accepts any call by default

        $lernprofilService = $this->createMock(LernprofilService::class);
        $noteGeneratorService = $this->createMock(NoteGeneratorService::class);
        $telosService = $this->createMock(TelosService::class);
        // Default: consent granted, so the existing auto-note (TRIG-01) path behaves as before.
        $telosService->method('hasAiConsent')->willReturn(true);

        return new TrainingService(
            $db,
            $questionMapper,
            $answerMapper,
            $poolMapper,
            $shareMapper,
            $badgeService,
            $streakService,
            $xpService,
            $cacheFactory,
            $translationService,
            $config,
            $logger,
            $courseService,
            $lernprofilService,
            $noteGeneratorService,
            $videoProgressService,
            $telosService
        );
    }

    private function makeQuestion(int $id, int $poolId, string $text): Question {
        $question = new Question();
        $question->setId($id);
        $question->setPoolId($poolId);
        $question->setUserId('alice');
        $question->setText($text);
        $question->setExplanation('');
        $question->setDifficulty('easy');
        $question->setQuestionType('single');
        return $question;
    }

    private function makeAnswer(int $id, int $questionId, string $text): Answer {
        $answer = new Answer();
        $answer->setId($id);
        $answer->setQuestionId($questionId);
        $answer->setText($text);
        $answer->setIsCorrect(true);
        $answer->setPosition(1);
        return $answer;
    }
}
