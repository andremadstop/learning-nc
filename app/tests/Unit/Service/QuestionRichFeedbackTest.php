<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Controller\QuestionController;
use OCA\Learning\Db\Answer;
use OCA\Learning\Db\AnswerMapper;
use OCA\Learning\Db\AnswerTranslationMapper;
use OCA\Learning\Db\Pool;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\PoolShareMapper;
use OCA\Learning\Db\Question;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Db\QuestionTranslation;
use OCA\Learning\Db\QuestionTranslationMapper;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\RichFeedbackFormat;
use OCA\Learning\Service\RichFeedbackService;
use OCA\Learning\Service\TranslationService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #66: the question editor stores, keeps and clears the rich feedback, translations carry
 * it, and RichFeedbackService builds what a learner gets after answering.
 */
#[AllowMockObjectsWithoutExpectations]
class QuestionRichFeedbackTest extends TestCase {
    private const ANSWERS = [
        ['text' => 'Yes', 'is_correct' => true],
        ['text' => 'No', 'is_correct' => false],
    ];
    private const RICH = [
        'hint' => "  Read the\nquestion twice. ",
        'background_fact' => 'Background.',
        'key_takeaway' => 'Takeaway.',
        'option_feedback' => [
            ['position' => 1, 'why' => 'No is wrong.', 'misconception' => 'Reading too fast'],
            ['position' => 0, 'why' => 'Yes is right.'],
        ],
    ];

    /** @var list<Question> */
    private array $saved = [];
    private ?Question $stored = null;

    /**
     * @param list<string> $storedAnswerTexts the answers as stored before the save
     */
    private function questionService(array $storedAnswerTexts = [], ?TranslationService $translations = null): QuestionService {
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
        $answerMapper->method('findByQuestion')->willReturn(array_map(static function (string $text, int $i): Answer {
            $a = new Answer();
            $a->setText($text);
            $a->setPosition($i);
            return $a;
        }, $storedAnswerTexts, array_keys($storedAnswerTexts)));
        $poolMapper = $this->createMock(PoolMapper::class);
        $poolMapper->method('find')->willReturn(new Pool());
        return new QuestionService(
            $questionMapper,
            $answerMapper,
            $this->createMock(PoolShareMapper::class),
            $poolMapper,
            new FakeDbConnection(),
            $translations ?? $this->createMock(TranslationService::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    private function storedWithRich(): Question {
        $q = new Question();
        $q->setId(42);
        $q->setPoolId(7);
        $q->setText('Old');
        $q->setQuestionType('single');
        $q->setHint('Stored hint');
        $q->setBackgroundFact('Stored background');
        $q->setKeyTakeaway('Stored takeaway');
        $q->setOptionFeedback('[{"position":0,"why":"Stored why","misconception":null}]');
        return $q;
    }

    public function testCreateStoresTheFieldsNormalized(): void {
        $result = $this->questionService()->create(7, 'author', 'Q?', null, null, self::ANSWERS, richFeedback: self::RICH);

        $q = $this->saved[0];
        $this->assertSame("Read the\nquestion twice.", $q->getHint(), 'trimmed, line breaks kept');
        $this->assertSame('Background.', $q->getBackgroundFact());
        $this->assertSame('Takeaway.', $q->getKeyTakeaway());
        $this->assertSame(
            [['position' => 0, 'why' => 'Yes is right.', 'misconception' => null], ['position' => 1, 'why' => 'No is wrong.', 'misconception' => 'Reading too fast']],
            RichFeedbackFormat::decodeOptions($q->getOptionFeedback())
        );
        $this->assertSame("Read the\nquestion twice.", $result['hint'], 'the editor gets back what it saved');
        $this->assertCount(2, $result['option_feedback']);
    }

    public function testATooLongFieldIsRefusedBeforeAnyWrite(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(RichFeedbackFormat::TOO_LONG_MESSAGE);
        try {
            $this->questionService()->create(7, 'author', 'Q?', null, null, self::ANSWERS,
                richFeedback: ['key_takeaway' => str_repeat('x', RichFeedbackFormat::TAKEAWAY_MAX_LENGTH + 1)]);
        } finally {
            $this->assertSame([], $this->saved);
        }
    }

    public function testUpdateWithoutTheFieldsKeepsWhatIsStored(): void {
        $this->stored = $this->storedWithRich();
        $this->questionService()->update(42, 'author', 'New', null, null, self::ANSWERS);

        $q = $this->saved[0];
        $this->assertSame(['Stored hint', 'Stored background', 'Stored takeaway'], [$q->getHint(), $q->getBackgroundFact(), $q->getKeyTakeaway()]);
        $this->assertSame('[{"position":0,"why":"Stored why","misconception":null}]', $q->getOptionFeedback());
    }

    public function testUpdateClearsAFieldSentEmptyAndKeepsTheOthers(): void {
        $this->stored = $this->storedWithRich();
        $this->questionService()->update(42, 'author', 'New', null, null, self::ANSWERS,
            richFeedback: ['hint' => '', 'option_feedback' => []]);

        $q = $this->saved[0];
        $this->assertNull($q->getHint());
        $this->assertNull($q->getOptionFeedback());
        $this->assertSame('Stored background', $q->getBackgroundFact());
    }

    /** The controller passes on exactly the keys the request carries, in the service's names. */
    /**
     * Gesamt-Review C4: option feedback is keyed by the answer position, and the editor renumbers
     * the answers 0..n-1 on save. A translation kept the old positions, so after removing answer
     * B the translated "why" and misconception of B showed under C. Now the stored and the
     * translated feedback move with their answers, matched by text; feedback of B is dropped.
     */
    public function testRemovingAnAnswerMovesTheFeedbackWithItsAnswersAndDropsTheRemovedOne(): void {
        $translation = new QuestionTranslation();
        $translation->setQuestionId(42);
        $translation->setLang('en');
        $translation->setText('Question');
        $translation->setOptionFeedback('[{"position":0,"why":"A is right.","misconception":null},{"position":1,"why":"B is wrong.","misconception":"B trap"},{"position":2,"why":"C is wrong.","misconception":"C trap"}]');
        $transMapper = $this->createMock(QuestionTranslationMapper::class);
        $transMapper->method('findByQuestion')->willReturn([$translation]);
        $transMapper->method('findByQuestionsAndLang')->willReturn([$translation]);
        $transMapper->expects($this->once())->method('update')->willReturnArgument(0);
        $translations = new TranslationService($transMapper, $this->createMock(AnswerTranslationMapper::class), $this->createMock(PoolMapper::class), $this->createMock(PoolShareMapper::class), new FakeDbConnection());

        $this->stored = $this->storedWithRich();
        $this->stored->setOptionFeedback('[{"position":0,"why":"A ok","misconception":null},{"position":1,"why":"B no","misconception":"Bm"},{"position":2,"why":"C no","misconception":"Cm"}]');
        // An API caller that does not send option_feedback: the stored feedback moves too.
        $this->questionService(['A', 'B', 'C'], $translations)->update(42, 'author', 'Q?', null, null,
            [['text' => 'A', 'is_correct' => true], ['text' => 'C', 'is_correct' => false]]);

        $this->assertSame(
            [['position' => 0, 'why' => 'A ok', 'misconception' => null], ['position' => 1, 'why' => 'C no', 'misconception' => 'Cm']],
            RichFeedbackFormat::decodeOptions($this->saved[0]->getOptionFeedback())
        );
        $out = $translations->translateRichFeedback([42 => [
            'background_fact' => null,
            'key_takeaway' => null,
            'option_feedback' => [
                ['position' => 0, 'why' => 'A ok', 'misconception' => null],
                ['position' => 1, 'why' => 'C no', 'misconception' => 'Cm'],
            ],
        ]], 'en')[42];
        $this->assertSame('C is wrong.', $out['option_feedback'][1]['why'], 'C keeps its own translated feedback');
        $this->assertSame('C trap', $out['option_feedback'][1]['misconception'], 'never the misconception of the removed B');
    }

    public function testSavingTheSameAnswersLeavesTheTranslatedFeedbackAlone(): void {
        $translations = $this->createMock(TranslationService::class);
        $translations->expects($this->never())->method('remapOptionFeedback');
        $this->stored = $this->storedWithRich();
        $this->questionService(['Yes', 'No'], $translations)->update(42, 'author', 'New', null, null, self::ANSWERS);

        $this->assertSame('[{"position":0,"why":"Stored why","misconception":null}]', $this->saved[0]->getOptionFeedback());
    }

    public function testTheControllerPassesOnlyTheKeysTheRequestCarries(): void {
        $calls = [];
        $service = $this->createMock(QuestionService::class);
        $service->method('update')->willReturnCallback(function (...$args) use (&$calls): array {
            $calls[] = $args[18] ?? 'MISSING';
            return [];
        });
        foreach ([[], ['hint' => null, 'optionFeedback' => [['position' => 0, 'why' => 'w']]], ['backgroundFact' => 'B', 'keyTakeaway' => 'K']] as $params) {
            $request = $this->createMock(IRequest::class);
            $request->method('getParams')->willReturn(['id' => 42, 'text' => 'Q?'] + $params);
            (new QuestionController('learning', $request, $service, 'author'))->update(42, 'Q?', null, null, self::ANSWERS);
        }

        $this->assertSame([
            [],
            ['hint' => null, 'option_feedback' => [['position' => 0, 'why' => 'w']]],
            ['background_fact' => 'B', 'key_takeaway' => 'K'],
        ], $calls);
    }

    private function translationService(?QuestionTranslation $translation): TranslationService {
        $mapper = $this->createMock(QuestionTranslationMapper::class);
        $mapper->method('findByQuestionsAndLang')->willReturn($translation ? [$translation] : []);
        $mapper->method('findByQuestion')->willReturn($translation ? [$translation] : []);
        $answers = $this->createMock(AnswerTranslationMapper::class);
        $answers->method('findByAnswersAndLang')->willReturn([]);
        return new TranslationService($mapper, $answers, $this->createMock(PoolMapper::class), $this->createMock(PoolShareMapper::class), new FakeDbConnection());
    }

    private function translation(): QuestionTranslation {
        $t = new QuestionTranslation();
        $t->setQuestionId(42);
        $t->setLang('de');
        $t->setText('Frage');
        $t->setHint('Hinweis');
        $t->setBackgroundFact('Hintergrund');
        $t->setKeyTakeaway(null);
        $t->setOptionFeedback('[{"position":1,"why":"Nein ist falsch.","misconception":null},{"position":3,"why":"Gibt es nicht.","misconception":null}]');
        return $t;
    }

    public function testTheHintIsTranslatedWhereThePayloadCarriesOne(): void {
        $service = $this->translationService($this->translation());

        $this->assertSame('Hinweis', $service->translateQuestion(['id' => 42, 'text' => 'Q?', 'hint' => 'Hint'], 'de')['hint']);
        $this->assertArrayNotHasKey('hint', $service->translateQuestion(['id' => 42, 'text' => 'Q?'], 'de'), 'never added to a payload without one (exam)');
    }

    public function testTheFeedbackIsTranslatedFieldByFieldWithoutBringingBackRemovedFeedback(): void {
        $rows = [42 => [
            'background_fact' => 'Background',
            'key_takeaway' => 'Takeaway',
            'option_feedback' => [
                ['position' => 0, 'why' => 'Yes is right.', 'misconception' => null],
                ['position' => 1, 'why' => 'No is wrong.', 'misconception' => 'Haste'],
            ],
        ]];

        $out = $this->translationService($this->translation())->translateRichFeedback($rows, 'de')[42];

        $this->assertSame('Hintergrund', $out['background_fact']);
        $this->assertSame('Takeaway', $out['key_takeaway'], 'untranslated keeps the original');
        $this->assertSame('Yes is right.', $out['option_feedback'][0]['why']);
        $this->assertSame('Nein ist falsch.', $out['option_feedback'][1]['why']);
        $this->assertSame('Haste', $out['option_feedback'][1]['misconception']);
        $this->assertCount(2, $out['option_feedback'], 'a translated option the original has no feedback for stays out');
    }

    public function testTheTranslationDialogEndpointWithholdsTheFeedbackFromLearners(): void {
        $service = $this->translationService($this->translation());

        $learner = $service->getQuestionTranslations(42, true)[0];
        foreach (['explanation', 'hint', 'background_fact', 'key_takeaway', 'option_feedback'] as $field) {
            $this->assertArrayNotHasKey($field, $learner);
        }
        $editor = $service->getQuestionTranslations(42, false)[0]->jsonSerialize();
        $this->assertSame('Hinweis', $editor['hint']);
        $this->assertCount(2, $editor['option_feedback']);
    }

    public function testATranslationKeepsWhatTheCallerDidNotSend(): void {
        $existing = $this->translation();
        $mapper = $this->createMock(QuestionTranslationMapper::class);
        $mapper->method('findByQuestionAndLang')->willReturn($existing);
        $mapper->method('update')->willReturnArgument(0);
        $service = new TranslationService($mapper, $this->createMock(AnswerTranslationMapper::class), $this->createMock(PoolMapper::class), $this->createMock(PoolShareMapper::class), new FakeDbConnection());

        $service->setQuestionTranslation(42, 'de', 'Frage', null, null);
        $this->assertSame('Hinweis', $existing->getHint());

        $service->setQuestionTranslation(42, 'de', 'Frage', null, null, ['hint' => 'Neuer Hinweis', 'background_fact' => '']);
        $this->assertSame('Neuer Hinweis', $existing->getHint());
        $this->assertNull($existing->getBackgroundFact());
        $this->assertStringContainsString('Nein ist falsch.', (string)$existing->getOptionFeedback());
    }

    public function testTheServiceJoinsTheFeedbackToTheAnswersByPosition(): void {
        $questions = new FakeQueryBuilder(new FakeResult([[
            'id' => 42,
            'background_fact' => 'Background',
            'key_takeaway' => '',
            'option_feedback' => '[{"position":0,"why":"Right.","misconception":null},{"position":1,"why":"Wrong.","misconception":"Haste"},{"position":5,"why":"Gone.","misconception":null}]',
        ]]));
        $answers = new FakeQueryBuilder(new FakeResult([
            ['id' => 900, 'question_id' => 42, 'is_correct' => '1', 'position' => 0],
            ['id' => 901, 'question_id' => 42, 'is_correct' => '0', 'position' => 1],
        ]));
        $service = new RichFeedbackService(new FakeDbConnection([$questions, $answers]), $this->translationService(null));

        $this->assertSame([
            'option_feedback' => [
                ['answer_id' => 900, 'position' => 0, 'is_correct' => true, 'why' => 'Right.', 'misconception' => null],
                ['answer_id' => 901, 'position' => 1, 'is_correct' => false, 'why' => 'Wrong.', 'misconception' => 'Haste'],
            ],
            'background_fact' => 'Background',
            'key_takeaway' => null,
        ], $service->forQuestion(42, null));
    }

    public function testAQuestionWithoutRichFeedbackGetsNone(): void {
        $questions = new FakeQueryBuilder(new FakeResult([['id' => 42, 'background_fact' => null, 'key_takeaway' => null, 'option_feedback' => null]]));
        $db = new FakeDbConnection([$questions]);
        $service = new RichFeedbackService($db, $this->translationService(null));

        $this->assertNull($service->forQuestion(42, null));
        $this->assertCount(1, $db->issuedBuilders, 'no answer query for a question without feedback');
    }
}
