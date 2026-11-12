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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #34: QuestionService accepts only the question types the app knows
 * (QuestionService::QUESTION_TYPES). Before, an unknown type was checked like a single-choice
 * question and stored as it was — or, longer than the varchar(20) column, failed on insert.
 * A question that already carries an unknown type from that time stays editable.
 */
#[AllowMockObjectsWithoutExpectations]
class QuestionTypeTest extends TestCase {
    private const ANSWERS = [
        ['text' => 'Yes', 'is_correct' => true],
        ['text' => 'No', 'is_correct' => false],
    ];

    /** @var Question[] */
    private array $saved = [];
    private ?Question $stored = null;
    private FakeDbConnection $db;

    private function createService(): QuestionService {
        $questionMapper = $this->createMock(QuestionMapper::class);
        $questionMapper->method('createOrUpdate')->willReturnCallback(function (Question $q): Question {
            if ($q->getId() === null) {
                $q->setId(501);
            }
            $this->saved[] = $q;
            return $q;
        });
        $questionMapper->method('findById')->willReturnCallback(fn (): Question => $this->stored);
        $answerMapper = $this->createMock(AnswerMapper::class);
        $answerMapper->method('createOrUpdate')->willReturnArgument(0);
        $poolMapper = $this->createMock(PoolMapper::class);
        $poolMapper->method('find')->willReturn(new Pool());
        $this->db = new FakeDbConnection();
        return new QuestionService(
            $questionMapper,
            $answerMapper,
            $this->createMock(PoolShareMapper::class),
            $poolMapper,
            $this->db,
            $this->createMock(TranslationService::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    private function storedQuestion(string $type): Question {
        $q = new Question();
        $q->setId(42);
        $q->setPoolId(7);
        $q->setText('Old text');
        $q->setQuestionType($type);
        return $q;
    }

    public function testTheValidTypesAreTheFormsTypesAndPbq(): void {
        $this->assertSame(['single', 'multi', 'open', 'pbq'], QuestionService::QUESTION_TYPES);
    }

    /** @return array<string, array{string}> */
    public static function unknownTypes(): array {
        return [
            'too long for the column' => ['multiple_choice_extended'],
            'short' => ['truefalse'],
            'legacy alias, not mapped by the service' => ['true_false'],
            'upper case' => ['Single'],
            'empty' => [''],
        ];
    }

    #[DataProvider('unknownTypes')]
    public function testCreateRefusesAnUnknownTypeBeforeAnyWrite(string $type): void {
        $service = $this->createService();
        try {
            $service->create(7, 'author', 'Q?', null, null, self::ANSWERS, $type);
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Unknown question type', $e->getMessage());
        }
        $this->assertSame([], $this->saved);
        $this->assertSame(0, $this->db->beginTransactionCalls);
    }

    #[DataProvider('unknownTypes')]
    public function testValidateForImportRefusesAnUnknownType(string $type): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown question type');
        $this->createService()->validateForImport('Q?', self::ANSWERS, $type, null);
    }

    public function testEveryValidTypeIsStillAccepted(): void {
        $service = $this->createService();
        $service->validateForImport('Q?', self::ANSWERS, 'single', null);
        $service->validateForImport('Q?', [['text' => 'a', 'is_correct' => true], ['text' => 'b', 'is_correct' => true]], 'multi', null);
        $service->validateForImport('Q?', [['text' => 'model', 'is_correct' => true]], 'open', null);
        $service->validateForImport('Q?', [], 'pbq', null);
        $service->validateForImport('Q?', self::ANSWERS, null, null);

        $service->create(7, 'author', 'PBQ', null, null, [], 'pbq', 'ranking', '{"items":[]}');
        $service->create(7, 'author', 'Default', null, null, self::ANSWERS);
        $this->assertSame(['pbq', 'single'], array_map(static fn (Question $q) => $q->getQuestionType(), $this->saved));
        $this->assertSame('ranking', $this->saved[0]->getPbqSubtype());
    }

    /** A stored unknown type (written before the check) can be saved again unchanged ... */
    public function testAStoredUnknownTypeStaysEditable(): void {
        $service = $this->createService();
        $this->stored = $this->storedQuestion('truefalse');
        $service->update(42, 'author', 'Same type sent back', null, null, self::ANSWERS, 'truefalse');
        $this->stored = $this->storedQuestion('truefalse');
        $service->update(42, 'author', 'No type sent', null, null, self::ANSWERS);

        $this->assertSame(['truefalse', 'truefalse'], array_map(static fn (Question $q) => $q->getQuestionType(), $this->saved));
    }

    /** ... and changed to a valid type, but never to another unknown one. */
    public function testAStoredUnknownTypeCanOnlyBeChangedToAValidOne(): void {
        $service = $this->createService();
        $this->stored = $this->storedQuestion('truefalse');
        $service->update(42, 'author', 'Fixed', null, null, self::ANSWERS, 'single');
        $this->assertSame('single', $this->saved[0]->getQuestionType());

        $this->stored = $this->storedQuestion('truefalse');
        $this->expectExceptionMessage('Unknown question type');
        $service->update(42, 'author', 'Other junk', null, null, self::ANSWERS, 'yesno');
    }

    public function testUpdateRefusesAnUnknownTypeForAValidQuestion(): void {
        $service = $this->createService();
        $this->stored = $this->storedQuestion('single');
        try {
            $service->update(42, 'author', 'Q', null, null, self::ANSWERS, 'multiple_choice_extended');
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Unknown question type', $e->getMessage());
        }
        $this->assertSame([], $this->saved);
    }
}
