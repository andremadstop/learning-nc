<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Answer;
use OCA\Learning\Db\AnswerMapper;
use OCA\Learning\Db\Pool;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\PoolShare;
use OCA\Learning\Db\PoolShareMapper;
use OCA\Learning\Db\Question;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\NotFoundException;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\TranslationService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 2026-11 audit, high finding: every course member read the full answer key through
 * GET /api/pools/{id}/questions (and /questions/{id}, the paged variant, the search and the
 * translation endpoints). is_correct, explanation and the PBQ solution were only withheld while a
 * graded exam was open — before the exam started a learner downloaded every solution, and afterwards
 * the certificate was publicly verifiable.
 *
 * Decision (Andre): only the pool's editors see solutions — the owner, an edit share and a course
 * instructor of a course carrying the pool. Everyone else gets the questions without them.
 */
#[AllowMockObjectsWithoutExpectations]
class QuestionServiceSolutionAccessTest extends TestCase {
    private const POOL = 42;

    private bool $noteVisible = false;

    private function question(): Question {
        $question = new Question();
        $question->setId(10);
        $question->setPoolId(self::POOL);
        $question->setText('Which port does HTTPS use?');
        $question->setExplanation('443 is the registered HTTPS port.');
        $question->setQuestionType('single');
        $question->setInstructorNote('Answer B is the trap.');
        $question->setNoteVisible($this->noteVisible);
        return $question;
    }

    /** @return list<Answer> */
    private function answers(): array {
        $out = [];
        foreach ([[100, '80', false], [101, '443', true]] as [$id, $text, $correct]) {
            $answer = new Answer();
            $answer->setId($id);
            $answer->setQuestionId(10);
            $answer->setText($text);
            $answer->setIsCorrect($correct);
            $out[] = $answer;
        }
        return $out;
    }

    /**
     * @param 'read-share'|'member'|'instructor'|'owner'|'edit' $role
     * @param list<FakeQueryBuilder> $extraQueries queued after the access queries
     */
    private function service(string $role, array $extraQueries = [], ?QuestionMapper $questionMapper = null): QuestionService {
        if ($questionMapper === null) {
            $questionMapper = $this->createMock(QuestionMapper::class);
            $questionMapper->method('findByPoolId')->willReturn([$this->question()]);
            $questionMapper->method('findByPoolIdPaged')->willReturn([$this->question()]);
            $questionMapper->method('countByPoolId')->willReturn(1);
            $questionMapper->method('findById')->willReturn($this->question());
        }
        $answerMapper = $this->createMock(AnswerMapper::class);
        $answerMapper->method('findByQuestions')->willReturn([10 => $this->answers()]);
        $answerMapper->method('findByQuestion')->willReturn($this->answers());

        $poolMapper = $this->createMock(PoolMapper::class);
        $shareMapper = $this->createMock(PoolShareMapper::class);
        $queries = [];
        if ($role === 'owner') {
            $poolMapper->method('find')->willReturn(new Pool());
        } else {
            $poolMapper->method('find')->willThrowException(new DoesNotExistException('not the owner'));
            if ($role === 'read-share' || $role === 'edit') {
                $share = new PoolShare();
                $share->setPermission($role === 'edit' ? 'edit' : 'read');
                $shareMapper->method('findByPoolAndUser')->willReturn($share);
                if ($role === 'read-share') {
                    // isCourseInstructorForPool(): not an instructor of a course carrying the pool.
                    $queries[] = new FakeQueryBuilder(FakeResult::fromFetch(false));
                }
            } else {
                $shareMapper->method('findByPoolAndUser')->willReturn(null);
                // hasCoursePoolAccess(): reachable through a course (as member or instructor).
                $queries[] = new FakeQueryBuilder(FakeResult::fromFetch(['id' => 1]));
                // isCourseInstructorForPool()
                $queries[] = new FakeQueryBuilder(FakeResult::fromFetch($role === 'instructor' ? ['id' => 1] : false));
            }
        }

        return new QuestionService(
            $questionMapper,
            $answerMapper,
            $shareMapper,
            $poolMapper,
            new FakeDbConnection(array_merge($queries, $extraQueries)),
            $this->createMock(TranslationService::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    /** @return array<string, array{callable(QuestionService): array}> */
    public static function readPaths(): array {
        return [
            'findByPool' => [static fn (QuestionService $s): array => $s->findByPool(self::POOL, 'u')[0]],
            'findByPoolPaged' => [static fn (QuestionService $s): array => $s->findByPoolPaged(self::POOL, 'u')['questions'][0]],
            'find' => [static fn (QuestionService $s): array => $s->find(10, 'u')],
        ];
    }

    /** @return array<string, array{callable(QuestionService): array, string}> */
    public static function learnerReadPaths(): array {
        $cases = [];
        foreach (self::readPaths() as $name => [$read]) {
            $cases[$name . ' as course member'] = [$read, 'member'];
            $cases[$name . ' as read share'] = [$read, 'read-share'];
        }
        return $cases;
    }

    /** @return array<string, array{callable(QuestionService): array, string}> */
    public static function editorReadPaths(): array {
        $cases = [];
        foreach (self::readPaths() as $name => [$read]) {
            foreach (['owner', 'edit', 'instructor'] as $role) {
                $cases[$name . ' as ' . $role] = [$read, $role];
            }
        }
        return $cases;
    }

    #[DataProvider('learnerReadPaths')]
    public function testLearnerGetsNoAnswerKeyWithoutAnyExam(callable $read, string $role): void {
        $question = $read($this->service($role));

        $this->assertSame('Which port does HTTPS use?', $question['text']);
        $this->assertArrayNotHasKey('explanation', $question);
        $this->assertCount(2, $question['answers'], 'the answers themselves stay readable');
        foreach ($question['answers'] as $answer) {
            $this->assertArrayNotHasKey('is_correct', $answer);
            $this->assertNotSame('', $answer['text']);
        }
    }

    #[DataProvider('editorReadPaths')]
    public function testEditorsKeepTheAnswerKey(callable $read, string $role): void {
        $question = $read($this->service($role));

        $this->assertSame('443 is the registered HTTPS port.', $question['explanation']);
        $this->assertSame([false, true], array_map(static fn (array $a): bool => (bool)$a['is_correct'], $question['answers']));
    }

    /**
     * #18/#26/#55 (audit 2026-11): a note the instructor kept hidden (note_visible=false) reached
     * every reader; only the Vue templates checked note_visible.
     */
    #[DataProvider('learnerReadPaths')]
    public function testLearnerGetsNoHiddenInstructorNote(callable $read, string $role): void {
        $question = $read($this->service($role));

        $this->assertArrayNotHasKey('instructor_note', $question);
    }

    #[DataProvider('learnerReadPaths')]
    public function testLearnerGetsAVisibleInstructorNote(callable $read, string $role): void {
        $this->noteVisible = true;
        $question = $read($this->service($role));

        $this->assertSame('Answer B is the trap.', $question['instructor_note']);
    }

    #[DataProvider('editorReadPaths')]
    public function testEditorsKeepAHiddenInstructorNote(callable $read, string $role): void {
        // The question editor saves the note back as received; a stripped copy would erase it.
        $question = $read($this->service($role));

        $this->assertSame('Answer B is the trap.', $question['instructor_note']);
        $this->assertFalse($question['note_visible']);
    }

    public function testTranslationEndpointsFollowTheSameRule(): void {
        $this->assertTrue($this->service('read-share')->mustWithholdSolutionForQuestion(10, 'u'));
        $this->assertFalse($this->service('owner')->mustWithholdSolutionForQuestion(10, 'u'));
        $this->assertFalse($this->service('edit')->mustWithholdSolutionForQuestion(10, 'u'));
    }

    /** @return array<string, array{array|false, bool}> */
    public static function courseRoles(): array {
        return ['course member' => [false, true], 'course instructor' => [['id' => 1], false]];
    }

    #[DataProvider('courseRoles')]
    public function testTranslationRuleForCourseRoles(array|false $instructorRow, bool $withheld): void {
        // mustWithholdSolutionForQuestion() does not run the read-access query, only the instructor one.
        $poolMapper = $this->createMock(PoolMapper::class);
        $poolMapper->method('find')->willThrowException(new DoesNotExistException('no'));
        $shareMapper = $this->createMock(PoolShareMapper::class);
        $shareMapper->method('findByPoolAndUser')->willReturn(null);
        $questionMapper = $this->createMock(QuestionMapper::class);
        $questionMapper->method('findById')->willReturn($this->question());
        $service = new QuestionService($questionMapper, $this->createMock(AnswerMapper::class), $shareMapper, $poolMapper,
            new FakeDbConnection([new FakeQueryBuilder(FakeResult::fromFetch($instructorRow))]),
            $this->createMock(TranslationService::class), $this->createMock(LoggerInterface::class));

        $this->assertSame($withheld, $service->mustWithholdSolutionForQuestion(10, 'u'));
    }

    private function searchService(): QuestionService {
        $questionMapper = $this->createMock(QuestionMapper::class);
        $questionMapper->method('searchByText')->willReturn([
            ['id' => 1, 'pool_id' => 7, 'text' => 'own pool', 'explanation' => 'mine',
                'pbq_config' => '{"positions":[{"id":"a","correct":"Firewall"}]}',
                'instructor_note' => 'own note', 'note_visible' => false],
            ['id' => 2, 'pool_id' => 8, 'text' => 'course pool', 'explanation' => 'secret',
                'pbq_config' => '{"positions":[{"id":"a","label":"Edge","correct":"Firewall"}]}',
                'instructor_note' => 'hidden note', 'note_visible' => '0'],
            ['id' => 3, 'pool_id' => 8, 'text' => 'course pool 2', 'explanation' => 'secret 2', 'pbq_config' => null,
                'instructor_note' => 'shared note', 'note_visible' => '1'],
        ]);
        $poolMapper = $this->createMock(PoolMapper::class);
        $poolMapper->method('find')->willReturnCallback(static function (int $poolId): Pool {
            if ($poolId === 7) {
                return new Pool();
            }
            throw new DoesNotExistException('not the owner');
        });
        $shareMapper = $this->createMock(PoolShareMapper::class);
        $shareMapper->method('findByPoolAndUser')->willReturn(null);

        return new QuestionService($questionMapper, $this->createMock(AnswerMapper::class), $shareMapper, $poolMapper,
            // Pool 8: isCourseInstructorForPool() once — the decision is cached per pool.
            new FakeDbConnection([new FakeQueryBuilder(FakeResult::fromFetch(false))]),
            $this->createMock(TranslationService::class), $this->createMock(LoggerInterface::class));
    }

    public function testSearchWithholdsSolutionsOfPoolsTheUserCannotEdit(): void {
        $rows = $this->searchService()->search('pool', 'u');

        $this->assertSame('mine', $rows[0]['explanation']);
        $this->assertStringContainsString('Firewall', $rows[0]['pbq_config']);

        $this->assertArrayNotHasKey('explanation', $rows[1]);
        $this->assertArrayNotHasKey('explanation', $rows[2]);
        $config = json_decode($rows[1]['pbq_config'], true);
        $this->assertSame('Edge', $config['positions'][0]['label']);
        $this->assertArrayNotHasKey('correct', $config['positions'][0]);
        $this->assertNull($rows[2]['pbq_config']);
    }

    public function testSearchWithholdsHiddenNotesOfPoolsTheUserCannotEdit(): void {
        // Raw rows: MariaDB returns note_visible as '0'/'1'.
        $rows = $this->searchService()->search('pool', 'u');

        $this->assertSame('own note', $rows[0]['instructor_note'], 'the editor keeps a hidden note');
        $this->assertArrayNotHasKey('instructor_note', $rows[1]);
        $this->assertSame('shared note', $rows[2]['instructor_note'], 'a visible note stays');
    }

    public function testGradingLoaderKeepsTheKeyForServerSideUse(): void {
        // AiController::explain builds its prompt from the correct answers on the server.
        $question = $this->service('member')->findWithSolution(10, 'u');
        $this->assertSame('443 is the registered HTTPS port.', $question['explanation']);
        $this->assertSame([false, true], array_map(static fn (array $a): bool => (bool)$a['is_correct'], $question['answers']));
    }

    public function testGradingLoaderStillChecksReadAccess(): void {
        $poolMapper = $this->createMock(PoolMapper::class);
        $poolMapper->method('find')->willThrowException(new DoesNotExistException('no'));
        $shareMapper = $this->createMock(PoolShareMapper::class);
        $shareMapper->method('findByPoolAndUser')->willReturn(null);
        $questionMapper = $this->createMock(QuestionMapper::class);
        $questionMapper->method('findById')->willReturn($this->question());
        $service = new QuestionService($questionMapper, $this->createMock(AnswerMapper::class), $shareMapper, $poolMapper,
            new FakeDbConnection([new FakeQueryBuilder(FakeResult::fromFetch(false))]),
            $this->createMock(TranslationService::class), $this->createMock(LoggerInterface::class));

        $this->expectException(\Exception::class);
        $service->findWithSolution(10, 'stranger');
    }

    // ---- checkAnswer(): server-side grading for the bot modes ---------------------------------

    /** @return list<FakeQueryBuilder> exam lookup, answer lookup, correct-answer lookup */
    private function checkQueries(bool $examActive, array|false $answerRow, ?int $correctId = 101): array {
        return [
            new FakeQueryBuilder(FakeResult::fromFetch($examActive ? ['id' => 9] : false)),
            new FakeQueryBuilder(FakeResult::fromFetch($answerRow)),
            new FakeQueryBuilder(FakeResult::fromFetch($correctId === null ? false : ['id' => $correctId])),
        ];
    }

    private function memberCheckService(array $extra): QuestionService {
        $poolMapper = $this->createMock(PoolMapper::class);
        $poolMapper->method('find')->willThrowException(new DoesNotExistException('no'));
        $shareMapper = $this->createMock(PoolShareMapper::class);
        $shareMapper->method('findByPoolAndUser')->willReturn(null);
        $questionMapper = $this->createMock(QuestionMapper::class);
        $questionMapper->method('findById')->willReturn($this->question());
        return new QuestionService($questionMapper, $this->createMock(AnswerMapper::class), $shareMapper, $poolMapper,
            new FakeDbConnection(array_merge([new FakeQueryBuilder(FakeResult::fromFetch(['id' => 1]))], $extra)),
            $this->createMock(TranslationService::class), $this->createMock(LoggerInterface::class));
    }

    public function testCheckAnswerGradesACorrectChoice(): void {
        $result = $this->memberCheckService($this->checkQueries(false, ['is_correct' => true]))->checkAnswer(10, 101, 'u');
        $this->assertSame(['correct' => true, 'correct_answer_id' => 101], $result);
    }

    public function testCheckAnswerGradesAWrongChoiceAndNamesTheRightOne(): void {
        $result = $this->memberCheckService($this->checkQueries(false, ['is_correct' => false]))->checkAnswer(10, 100, 'u');
        $this->assertSame(['correct' => false, 'correct_answer_id' => 101], $result);
    }

    public function testCheckAnswerReadsIsCorrectFromMysqlStyleStrings(): void {
        // MySQL/MariaDB return the boolean column as '1'/'0'.
        $result = $this->memberCheckService($this->checkQueries(false, ['is_correct' => '0']))->checkAnswer(10, 100, 'u');
        $this->assertFalse($result['correct']);
    }

    public function testCheckAnswerIsRefusedDuringAGradedExamOnThePool(): void {
        $service = $this->memberCheckService($this->checkQueries(true, ['is_correct' => true]));
        $this->expectException(ForbiddenException::class);
        $service->checkAnswer(10, 101, 'u');
    }

    public function testCheckAnswerRefusesAnAnswerOfAnotherQuestion(): void {
        $service = $this->memberCheckService($this->checkQueries(false, false));
        $this->expectException(\InvalidArgumentException::class);
        $service->checkAnswer(10, 999, 'u');
    }

    public function testCheckAnswerWithoutPoolAccessIsNotFound(): void {
        $poolMapper = $this->createMock(PoolMapper::class);
        $poolMapper->method('find')->willThrowException(new DoesNotExistException('no'));
        $shareMapper = $this->createMock(PoolShareMapper::class);
        $shareMapper->method('findByPoolAndUser')->willReturn(null);
        $questionMapper = $this->createMock(QuestionMapper::class);
        $questionMapper->method('findById')->willReturn($this->question());
        $db = new FakeDbConnection([new FakeQueryBuilder(FakeResult::fromFetch(false))]);
        $service = new QuestionService($questionMapper, $this->createMock(AnswerMapper::class), $shareMapper, $poolMapper,
            $db, $this->createMock(TranslationService::class), $this->createMock(LoggerInterface::class));

        try {
            $service->checkAnswer(10, 101, 'stranger');
            $this->fail('a user without pool access must not be graded');
        } catch (NotFoundException $e) {
            $this->assertCount(1, $db->issuedBuilders, 'no answer lookup without access');
        }
    }

    public function testCheckAnswerForMissingQuestionIsNotFound(): void {
        $questionMapper = $this->createMock(QuestionMapper::class);
        $questionMapper->method('findById')->willThrowException(new DoesNotExistException('gone'));
        $service = $this->service('owner', [], $questionMapper);
        $this->expectException(NotFoundException::class);
        $service->checkAnswer(10, 101, 'u');
    }
}
