<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\AnswerMapper;
use OCA\Learning\Db\Pool;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\PoolShare;
use OCA\Learning\Db\PoolShareMapper;
use OCA\Learning\Db\Question;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\TranslationService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 2026-11 audit S02: during an active exam the question read APIs (findByPool, findByPoolPaged,
 * find) withheld explanation and is_correct, but returned pbq_config with its solution keys —
 * GET /api/pools/{id}/questions in a second tab revealed every PBQ answer of the exam.
 *
 * Recheck D1: the pool's editors (owner, edit share) are exempt. The question editor loads
 * through these paths and saves pbq_config, answers and explanation back unchanged; stripping
 * them for an editor with an open exam of their own erased the solution on save.
 *
 * 2026-11 audit, high finding (answer key): the solution is now withheld from every non-editor,
 * with or without an exam — so these read paths no longer look for an exam at all. The only query
 * they issue for a learner is isCourseInstructorForPool().
 */
#[AllowMockObjectsWithoutExpectations]
class QuestionServiceExamPbqTest extends TestCase {
    private function pbqQuestion(): Question {
        $question = new Question();
        $question->setId(10);
        $question->setPoolId(42);
        $question->setText('Place the devices');
        $question->setQuestionType('pbq');
        $question->setPbqSubtype('placement');
        $question->setPbqConfig((string)json_encode([
            'positions' => [['id' => 'edge', 'label' => 'Edge', 'correct' => 'Firewall']],
            'device_options' => ['Firewall', 'Switch'],
        ]));
        return $question;
    }

    /** @param 'student'|'owner'|'edit' $role */
    private function service(string $role = 'student'): QuestionService {
        $questionMapper = $this->createMock(QuestionMapper::class);
        $questionMapper->method('findByPoolId')->willReturn([$this->pbqQuestion()]);
        $questionMapper->method('findByPoolIdPaged')->willReturn([$this->pbqQuestion()]);
        $questionMapper->method('countByPoolId')->willReturn(1);
        $questionMapper->method('findById')->willReturn($this->pbqQuestion());
        $answerMapper = $this->createMock(AnswerMapper::class);
        $answerMapper->method('findByQuestions')->willReturn([]);
        $answerMapper->method('findByQuestion')->willReturn([]);
        $poolMapper = $this->createMock(PoolMapper::class);
        $shareMapper = $this->createMock(PoolShareMapper::class);
        if ($role === 'owner') {
            $poolMapper->method('find')->willReturn(new Pool());
        } else {
            $poolMapper->method('find')->willThrowException(new DoesNotExistException('not the owner'));
            $share = new PoolShare();
            $share->setPermission($role === 'edit' ? 'edit' : 'read');
            $shareMapper->method('findByPoolAndUser')->willReturn($share);
        }

        // The only query these paths issue for a read share is isCourseInstructorForPool() — not one.
        // An open exam no longer matters, so none is queued: a lookup would consume this result.
        $db = new FakeDbConnection([new FakeQueryBuilder(FakeResult::fromFetch(false))]);

        return new QuestionService(
            $questionMapper,
            $answerMapper,
            $shareMapper,
            $poolMapper,
            $db,
            $this->createMock(TranslationService::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    /** @return array<string, array{callable(QuestionService): array}> */
    public static function readPaths(): array {
        return [
            'findByPool' => [static fn (QuestionService $s): array => $s->findByPool(42, 'student')[0]],
            'findByPoolPaged' => [static fn (QuestionService $s): array => $s->findByPoolPaged(42, 'student')['questions'][0]],
            'find' => [static fn (QuestionService $s): array => $s->find(10, 'student')],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('readPaths')]
    public function testPbqSolutionIsWithheldFromALearner(callable $read): void {
        $question = $read($this->service());
        $this->assertArrayNotHasKey('correct', $question['pbq_config']['positions'][0]);
        $this->assertSame('Edge', $question['pbq_config']['positions'][0]['label'], 'render config must survive');
    }

    /** @return array<string, array{callable(QuestionService): array, string}> */
    public static function editorReadPaths(): array {
        $cases = [];
        foreach (self::readPaths() as $name => [$read]) {
            $cases[$name . ' as owner'] = [$read, 'owner'];
            $cases[$name . ' as edit share'] = [$read, 'edit'];
        }
        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('editorReadPaths')]
    public function testEditorKeepsTheSolution(callable $read, string $role): void {
        $question = $read($this->service($role));
        $this->assertSame('Firewall', $question['pbq_config']['positions'][0]['correct'],
            'the editor saves pbq_config back; a stripped copy would erase the solution');
    }

    public function testTranslationReaderExemptsEditorsButNotLearners(): void {
        $this->assertFalse($this->service('owner')->mustWithholdSolutionForQuestion(10, 'owner'));
        $this->assertFalse($this->service('edit')->mustWithholdSolutionForQuestion(10, 'editor'));
        $this->assertTrue($this->service()->mustWithholdSolutionForQuestion(10, 'student'));
    }
}
