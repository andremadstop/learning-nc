<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\AnswerMapper;
use OCA\Learning\Db\Pool;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\PoolShareMapper;
use OCA\Learning\Db\Question;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\TranslationService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Audit #38: the question form saved a new PBQ as 'single' (Training/Exam/Leitner recognise a PBQ
 * only by question_type 'pbq'), attached dummy answers to an edited PBQ, and "no PBQ" could not
 * clear a stored subtype/config because update() only applied non-null values.
 */
#[AllowMockObjectsWithoutExpectations]
final class QuestionPbqTypeTest extends TestCase {
    private const ANSWERS = [
        ['text' => 'Yes', 'is_correct' => true],
        ['text' => 'No', 'is_correct' => false],
    ];

    /** @var Question[] */
    private array $saved = [];
    /** @var int number of answer rows written */
    private int $answerWrites = 0;
    private ?Question $stored = null;

    private function service(): QuestionService {
        $questionMapper = $this->createMock(QuestionMapper::class);
        $questionMapper->method('createOrUpdate')->willReturnCallback(function (Question $q): Question {
            if ($q->getId() === null) {
                $q->setId(501);
            }
            $this->saved[] = $q;
            return $q;
        });
        $questionMapper->method('findById')->willReturnCallback(fn(): Question => $this->stored);

        $answerMapper = $this->createMock(AnswerMapper::class);
        $answerMapper->method('createOrUpdate')->willReturnCallback(function ($answer) {
            $this->answerWrites++;
            return $answer;
        });

        $poolMapper = $this->createMock(PoolMapper::class);
        $poolMapper->method('find')->willReturn(new Pool());

        return new QuestionService(
            $questionMapper,
            $answerMapper,
            $this->createMock(PoolShareMapper::class),
            $poolMapper,
            new FakeDbConnection(),
            $this->createMock(TranslationService::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    private function storedPbq(): Question {
        $q = new Question();
        $q->setId(42);
        $q->setPoolId(7);
        $q->setText('Old');
        $q->setQuestionType('pbq');
        $q->setPbqSubtype('dropdown');
        $q->setPbqConfig('{"questions":[]}');
        return $q;
    }

    public function testCreateWithASubtypeStoresAPbqWithoutAnswers(): void {
        $this->service()->create(7, 'author', 'Configure the port', null, null, [], 'single',
            pbqSubtype: 'cli', pbqConfig: '{"terminals":[]}');

        $this->assertSame('pbq', $this->saved[0]->getQuestionType());
        $this->assertSame('cli', $this->saved[0]->getPbqSubtype());
        $this->assertSame(0, $this->answerWrites);
    }

    public function testEditingAPbqStoresNoDummyAnswers(): void {
        $this->stored = $this->storedPbq();
        $this->service()->update(42, 'author', 'Fixed typo', null, null, self::ANSWERS, 'pbq',
            pbqSubtype: 'dropdown', pbqConfig: '{"questions":[]}');

        $this->assertSame('pbq', $this->saved[0]->getQuestionType());
        $this->assertSame(0, $this->answerWrites);
    }

    public function testEmptySubtypeClearsSubtypeAndConfig(): void {
        $this->stored = $this->storedPbq();
        $this->service()->update(42, 'author', 'Now a choice question', null, null, self::ANSWERS, 'single',
            pbqSubtype: '', pbqConfig: null);

        $this->assertSame('single', $this->saved[0]->getQuestionType());
        $this->assertNull($this->saved[0]->getPbqSubtype());
        $this->assertNull($this->saved[0]->getPbqConfig());
        $this->assertSame(2, $this->answerWrites);
    }

    public function testNullSubtypeKeepsTheStoredOne(): void {
        $this->stored = $this->storedPbq();
        $this->service()->update(42, 'author', 'Text only', null, null, [], null);

        $this->assertSame('pbq', $this->saved[0]->getQuestionType());
        $this->assertSame('dropdown', $this->saved[0]->getPbqSubtype());
    }

    public function testAPbqWithoutSubtypeIsRefused(): void {
        $this->stored = $this->storedPbq();
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->update(42, 'author', 'Text', null, null, [], 'pbq', pbqSubtype: '');
    }
}
