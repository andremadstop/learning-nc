<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\ImportController;
use OCA\Learning\Db\Answer;
use OCA\Learning\Db\AnswerMapper;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CoursePoolMapper;
use OCA\Learning\Db\Pool;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\PoolShareMapper;
use OCA\Learning\Db\Question;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Service\DataMobilityService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #34: "I can't import question via JSON. Nothing works right now."
 *
 * The pool import in the UI (POST /api/pools/{id}/import/json) answered 500 for the very
 * format the documentation shows ({_meta, pools:[…]}), for a single question object and for
 * any numeric field, and it dropped answers given as plain strings or as options + index,
 * which then ended as "Need at least 2 answers" and 0 imported.
 *
 * These tests drive the real controller and assert what reaches the mappers, not just the
 * status: a 201 with the wrong answer marked correct is the failure shape that matters.
 */
#[AllowMockObjectsWithoutExpectations]
class ImportControllerJsonTest extends TestCase {

    /** @var list<Question> */
    private array $questions = [];
    /** @var array<int, list<Answer>> answers per question id */
    private array $answers = [];
    private int $nextId = 1000;
    private FakeDbConnection $db;

    private function controller(): ImportController {
        $this->questions = [];
        $this->answers = [];
        $this->db = new FakeDbConnection();

        $questionMapper = $this->createMock(QuestionMapper::class);
        $questionMapper->method('createOrUpdate')->willReturnCallback(function (Question $q): Question {
            if ($q->getId() === null) {
                $q->setId($this->nextId++);
            }
            $this->questions[] = $q;
            return $q;
        });

        $answerMapper = $this->createMock(AnswerMapper::class);
        $answerMapper->method('createOrUpdate')->willReturnCallback(function (Answer $a): Answer {
            $this->answers[(int)$a->getQuestionId()][] = $a;
            return $a;
        });

        $poolMapper = $this->createMock(PoolMapper::class);
        $poolMapper->method('find')->willReturn(new Pool());

        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(fn (string $app, string $key, $default = '') => $default);

        // English source with its parameters: what an instance without a translation shows.
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(
            static fn (string $text, $parameters = []): string => vsprintf($text, is_array($parameters) ? $parameters : [$parameters])
        );

        return new ImportController(
            'learning',
            $this->createMock(IRequest::class),
            $questionMapper,
            $answerMapper,
            $poolMapper,
            $this->createMock(PoolShareMapper::class),
            $config,
            $this->db,
            'teacher',
            $l10n
        );
    }

    private function import(mixed $payload): DataResponse {
        $json = is_string($payload) ? $payload : json_encode($payload, JSON_THROW_ON_ERROR);
        return $this->controller()->importJson(7, $json);
    }

    /** @return list<string> texts of the answers marked correct for the n-th stored question */
    private function correctTexts(int $n): array {
        $q = $this->questions[$n];
        $out = [];
        foreach ($this->answers[(int)$q->getId()] ?? [] as $a) {
            if ($a->getIsCorrect()) {
                $out[] = (string)$a->getText();
            }
        }
        return $out;
    }

    /** @return list<string> */
    private function answerTexts(int $n): array {
        $q = $this->questions[$n];
        return array_map(static fn (Answer $a): string => (string)$a->getText(), $this->answers[(int)$q->getId()] ?? []);
    }

    private function assertImported(DataResponse $r, int $count): void {
        $data = $r->getData();
        $this->assertSame(201, $r->getStatus(), 'response: ' . json_encode($data));
        $this->assertSame($count, $data['imported'], 'response: ' . json_encode($data));
    }

    // ---------------------------------------------------------------------------------------
    // (1) The documented example, read from the documentation at runtime
    // ---------------------------------------------------------------------------------------

    /** First ```json block of docs/import-pool-workflow.md — never a copy, so doc and importer cannot drift apart. */
    private static function documentedExample(): string {
        // Repo root locally (app/tests/Unit/Controller → 4 up); "/" in the gate container, which
        // places the file at /docs/import-pool-workflow.md (scripts/gate-container.sh).
        $path = dirname(__DIR__, 4) . '/docs/import-pool-workflow.md';
        self::assertFileExists($path, 'the import documentation must be reachable from the test');
        $doc = (string)file_get_contents($path);
        $found = preg_match('/```json\s*\n(.*?)\n```/s', $doc, $m);
        self::assertSame(1, $found, 'docs/import-pool-workflow.md has no ```json block');
        return $m[1];
    }

    public function testTheDocumentedExampleImportsInTheDialog(): void {
        $example = self::documentedExample();
        $decoded = json_decode($example, true);
        $this->assertIsArray($decoded, 'the documented example must be valid JSON');
        $this->assertCount(1, $decoded['pools'], 'the example is meant to hold exactly one pool');
        $expected = $decoded['pools'][0]['questions'];

        $r = $this->import($example);

        $this->assertImported($r, count($expected));
        $this->assertSame([], $r->getData()['errors']);
        $this->assertSame(trim($expected[0]['text']), $this->questions[0]->getText());
        $this->assertSame('medium', $this->questions[0]->getDifficulty());
        $correct = array_values(array_map(
            static fn (array $a): string => $a['text'],
            array_filter($expected[0]['answers'], static fn (array $a): bool => $a['is_correct'])
        ));
        $this->assertSame($correct, $this->correctTexts(0));
        $this->assertCount(count($expected[0]['answers']), $this->answerTexts(0));
    }

    // ---------------------------------------------------------------------------------------
    // (2) Export from the app → import in the dialog
    // ---------------------------------------------------------------------------------------

    public function testAPoolExportImportsBackUnchanged(): void {
        $single = new Question();
        $single->setId(11);
        $single->setText('Which port does HTTPS use?');
        $single->setQuestionType('single');
        $single->setExplanation('443/tcp.');
        $multi = new Question();
        $multi->setId(12);
        $multi->setText('Which are private ranges?');
        $multi->setQuestionType('multi');
        $multi->setScenario("An office network.\nTwo sites.");
        $open = new Question();
        $open->setId(13);
        $open->setText('What does DNS stand for?');
        $open->setQuestionType('open');

        $mk = static function (int $qid, string $text, bool $correct): Answer {
            $a = new Answer();
            $a->setQuestionId($qid);
            $a->setText($text);
            $a->setIsCorrect($correct);
            return $a;
        };

        $poolMapper = $this->createMock(PoolMapper::class);
        $poolMapper->method('findById')->willReturn(new Pool());
        $questionMapper = $this->createMock(QuestionMapper::class);
        $questionMapper->method('findByPoolId')->willReturn([$single, $multi, $open]);
        $answerMapper = $this->createMock(AnswerMapper::class);
        $answerMapper->method('findByQuestions')->willReturn([
            11 => [$mk(11, '80', false), $mk(11, '443', true), $mk(11, '22', false)],
            12 => [$mk(12, '10.0.0.0/8', true), $mk(12, '8.8.8.0/24', false), $mk(12, '192.168.0.0/16', true)],
            13 => [$mk(13, 'Domain Name System', true)],
        ]);

        $export = (new DataMobilityService(
            $poolMapper,
            $questionMapper,
            $answerMapper,
            $this->createMock(CourseMapper::class),
            $this->createMock(CoursePoolMapper::class),
            $this->createMock(CourseMemberMapper::class),
            new FakeDbConnection(),
            $this->createMock(IUserManager::class)
        ))->exportPoolJson(5);

        $r = $this->import(json_encode($export, JSON_THROW_ON_ERROR));

        $this->assertImported($r, 3);
        $this->assertSame(['single', 'multi', 'open'], array_map(static fn (Question $q) => $q->getQuestionType(), $this->questions));
        $this->assertSame(['443'], $this->correctTexts(0));
        $this->assertSame('443/tcp.', $this->questions[0]->getExplanation());
        $this->assertSame(['10.0.0.0/8', '192.168.0.0/16'], $this->correctTexts(1));
        $this->assertSame("An office network.\nTwo sites.", $this->questions[1]->getScenario());
        $this->assertSame(['Domain Name System'], $this->correctTexts(2));
    }

    // ---------------------------------------------------------------------------------------
    // (3) Answers as strings / options, correct answer named on the question
    // ---------------------------------------------------------------------------------------

    public function testOptionsWithZeroBasedAnswerIndex(): void {
        $r = $this->import([[
            'question' => 'Capital of France?',
            'options' => ['Berlin', 'Paris', 'Rome'],
            'answer' => 1,
        ]]);

        $this->assertImported($r, 1);
        $this->assertSame(['Berlin', 'Paris', 'Rome'], $this->answerTexts(0));
        $this->assertSame(['Paris'], $this->correctTexts(0));
    }

    public function testCorrectIndexZeroMeansTheFirstAnswer(): void {
        $r = $this->import([['text' => 'First?', 'choices' => ['Yes', 'No'], 'correct_index' => 0]]);

        $this->assertImported($r, 1);
        $this->assertSame(['Yes'], $this->correctTexts(0));
    }

    public function testCorrectNumberCountsFromOne(): void {
        $r = $this->import([['text' => 'Second?', 'answers' => ['a', 'b', 'c'], 'correct_number' => 2]]);

        $this->assertImported($r, 1);
        $this->assertSame(['b'], $this->correctTexts(0));
    }

    public function testStringAnswersWithLetter(): void {
        $r = $this->import([['text' => 'Which is a prime?', 'answers' => ['4', '6', '7', '9'], 'correct' => 'C']]);

        $this->assertImported($r, 1);
        $this->assertSame(['7'], $this->correctTexts(0));
    }

    public function testCorrectAnswerGivenAsItsText(): void {
        $r = $this->import([['text' => 'Largest planet?', 'options' => ['Mars', 'Jupiter', 'Venus'], 'correct_answer' => 'jupiter ']]);

        $this->assertImported($r, 1);
        $this->assertSame(['Jupiter'], $this->correctTexts(0));
    }

    public function testAnswerTextWinsOverLetterAndNumber(): void {
        // "A" and "2" are real answer texts here; matching them as letter / index would mark the wrong one.
        $r = $this->import([
            ['text' => 'Pick the letter', 'options' => ['B', 'A', 'C'], 'answer' => 'A'],
            ['text' => '1 + 1 = ?', 'options' => ['1', '3', '2'], 'answer' => '2'],
        ]);

        $this->assertImported($r, 2);
        $this->assertSame(['A'], $this->correctTexts(0));
        $this->assertSame(['2'], $this->correctTexts(1));
    }

    public function testArrayOfMarkersMakesMultipleChoice(): void {
        $r = $this->import([['text' => 'Even numbers?', 'options' => ['1', 'two', '3', 'four'], 'correct' => ['B', 3]]]);

        $this->assertImported($r, 1);
        $this->assertSame('multi', $this->questions[0]->getQuestionType());
        $this->assertSame(['two', 'four'], $this->correctTexts(0));
    }

    public function testKeyedOptionsResolveByTheirLabel(): void {
        $r = $this->import([['text' => 'OSI layer 3?', 'options' => ['A' => 'Transport', 'B' => 'Network'], 'answer' => 'B']]);

        $this->assertImported($r, 1);
        $this->assertSame(['Transport', 'Network'], $this->answerTexts(0));
        $this->assertSame(['Network'], $this->correctTexts(0));
    }

    public function testNumericKeyedOptionsResolveByTheirKeyNotByPosition(): void {
        // json_decode makes {"1": …, "2": …} int keys; "answer": 1 names key 1 ("a"), not position 1 ("b").
        $r = $this->import('[{"text":"Numbered options","options":{"1":"a","2":"b","3":"c"},"answer":1},'
            . '{"text":"Numbered, as string","options":{"1":"a","2":"b"},"correct":"2"}]');

        $this->assertImported($r, 2);
        $this->assertSame(['a', 'b', 'c'], $this->answerTexts(0));
        $this->assertSame(['a'], $this->correctTexts(0));
        $this->assertSame(['b'], $this->correctTexts(1));
    }

    public function testExistingPerAnswerFlagsStillWork(): void {
        $r = $this->import([['text' => 'Q?', 'answers' => [['text' => 'x', 'correct' => true], ['text' => 'y', 'korrekt' => false]]]]);

        $this->assertImported($r, 1);
        $this->assertSame(['x'], $this->correctTexts(0));
    }

    public function testAnUnresolvableMarkerSkipsTheItemInsteadOfMarkingTheFirstAnswer(): void {
        $r = $this->import([
            ['text' => 'Out of range (0-based)', 'options' => ['a', 'b', 'c'], 'answer' => 3],
            ['text' => 'Unknown letter', 'options' => ['a', 'b'], 'correct' => 'F'],
            ['text' => 'No such text', 'options' => ['a', 'b'], 'correct_answer' => 'zzz'],
            ['text' => 'Fine', 'options' => ['a', 'b'], 'answer' => 0],
        ]);

        $this->assertImported($r, 1);
        $this->assertSame('Fine', $this->questions[0]->getText());
        $errors = $r->getData()['errors'];
        $this->assertCount(3, $errors);
        $this->assertStringContainsString('Item 1', $errors[0]);
        $this->assertStringContainsString('"3"', $errors[0]);
        $this->assertStringContainsString('0 = first', $errors[0]);
        $this->assertStringContainsString('Item 2', $errors[1]);
        $this->assertStringContainsString('Item 3', $errors[2]);
    }

    public function testOpenQuestionTakesItsModelAnswerFromAnswer(): void {
        $r = $this->import([['text' => 'What is H2O?', 'question_type' => 'open', 'answer' => 'Water']]);

        $this->assertImported($r, 1);
        $this->assertSame('open', $this->questions[0]->getQuestionType());
        $this->assertSame(['Water'], $this->correctTexts(0));
    }

    // ---------------------------------------------------------------------------------------
    // (4) Numeric and other non-string scalars
    // ---------------------------------------------------------------------------------------

    public function testNumericFieldsDoNotBreakTheImport(): void {
        $r = $this->import([
            ['text' => 1984, 'explanation' => 42, 'difficulty' => 3, 'answers' => [['text' => 1, 'is_correct' => true], ['text' => 2.5]]],
            ['text' => 'Next one is still read', 'answers' => [1, 2], 'answer' => 0],
        ]);

        $this->assertImported($r, 2);
        $this->assertSame('1984', $this->questions[0]->getText());
        $this->assertSame('42', $this->questions[0]->getExplanation());
        $this->assertSame('3', $this->questions[0]->getDifficulty());
        $this->assertSame(['1', '2.5'], $this->answerTexts(0));
        $this->assertSame(['1'], $this->correctTexts(0));
        $this->assertSame(['1'], $this->correctTexts(1));
    }

    public function testNonScalarFieldsAreReportedPerItemNotAsServerError(): void {
        $r = $this->import([
            ['text' => ['not', 'text'], 'answers' => ['a', 'b']],
            ['text' => 'Bad answers', 'answers' => 'a,b'],
            ['text' => 'Good', 'explanation' => ['x'], 'difficulty' => ['y'], 'handbook_key' => ['z'], 'answers' => ['a', 'b'], 'answer' => 1],
            'just a string',
            ['type' => 'pbq', 'text' => 'PBQ', 'subtype' => ['dropdown'], 'pbq_config' => []],
        ]);

        $this->assertImported($r, 1);
        $this->assertSame('Good', $this->questions[0]->getText());
        $this->assertNull($this->questions[0]->getExplanation());
        $this->assertNull($this->questions[0]->getDifficulty());
        $this->assertNull($this->questions[0]->getHandbookKey());
        $errors = $r->getData()['errors'];
        $this->assertCount(4, $errors);
        $this->assertStringContainsString('Item 1', $errors[0]);
        $this->assertStringContainsString('Item 2', $errors[1]);
        $this->assertStringContainsString('Item 4', $errors[2]);
        $this->assertStringContainsString('#5', $errors[3]);
    }

    // ---------------------------------------------------------------------------------------
    // (5) question_type
    // ---------------------------------------------------------------------------------------

    public function testQuestionTypeIsReadLikeType(): void {
        $r = $this->import([
            ['text' => 'Explain TCP.', 'question_type' => 'open', 'answers' => [['text' => 'Reliable transport', 'is_correct' => true]]],
            ['text' => 'Open, upper case', 'question_type' => 'OPEN', 'answers' => ['Model']],
        ]);

        $this->assertImported($r, 2);
        $this->assertSame('open', $this->questions[0]->getQuestionType());
        $this->assertSame('open', $this->questions[1]->getQuestionType());
        $this->assertSame(['Model'], $this->correctTexts(1));
    }

    public function testQuestionTypePbqTakesThePbqPath(): void {
        $r = $this->import([[
            'question_type' => 'pbq',
            'question_text' => 'Match the ports',
            'subtype' => 'dropdown',
            'pbq_config' => ['items' => []],
            'difficulty' => 2,
        ]]);

        $this->assertImported($r, 1);
        $this->assertSame('pbq', $this->questions[0]->getQuestionType());
        $this->assertSame('dropdown', $this->questions[0]->getPbqSubtype());
        $this->assertSame('2', $this->questions[0]->getDifficulty());
    }

    // ---------------------------------------------------------------------------------------
    // (6) Several pools, (7) single object, and other shapes
    // ---------------------------------------------------------------------------------------

    public function testSeveralPoolsAreRefusedWithAClearMessage(): void {
        $q = ['text' => 'Q?', 'answers' => [['text' => 'a', 'is_correct' => true], ['text' => 'b']]];
        $r = $this->import(['pools' => [['name' => 'One', 'questions' => [$q]], ['name' => 'Two', 'questions' => [$q]]]]);

        $this->assertSame(400, $r->getStatus());
        $error = $r->getData()['error'];
        $this->assertStringContainsString('2 pools', $error);
        $this->assertStringContainsString('occ learning:import-pool-json', $error);
        $this->assertSame([], $this->questions, 'nothing may be written, and pools are never merged');
        $this->assertSame(0, $this->db->beginTransactionCalls);
    }

    public function testEmptyPoolsListIsRefused(): void {
        $r = $this->import(['pools' => []]);

        $this->assertSame(400, $r->getStatus());
        $this->assertArrayHasKey('error', $r->getData());
        $this->assertArrayNotHasKey('errors', $r->getData(), 'an empty errors list would hide the message in the dialog');
    }

    public function testASingleQuestionObjectIsImported(): void {
        $r = $this->import(['question' => 'Only one?', 'answers' => [['text' => 'yes', 'is_correct' => true], ['text' => 'no']]]);

        $this->assertImported($r, 1);
        $this->assertSame('Only one?', $this->questions[0]->getText());
        $this->assertSame(['yes'], $this->correctTexts(0));
    }

    public function testAnObjectThatIsNoQuestionListIsA400(): void {
        $r = $this->import(['name' => 'My pool', 'stuff' => [1, 2]]);

        $this->assertSame(400, $r->getStatus());
        $this->assertStringContainsString('Expected a list of questions', $r->getData()['error']);
        $this->assertArrayNotHasKey('errors', $r->getData());
    }

    public function testAnEmptyListIsA400WithAMessage(): void {
        $r = $this->import('[]');

        $this->assertSame(400, $r->getStatus());
        $this->assertNotSame('', (string)($r->getData()['error'] ?? ''));
        $this->assertArrayNotHasKey('errors', $r->getData());
    }

    public function testItemNumbersCountUpInsideAWrapper(): void {
        $r = $this->import(['questions' => [
            ['text' => 'ok', 'answers' => ['a', 'b'], 'answer' => 0],
            ['answers' => ['a', 'b']],
        ]]);

        $this->assertImported($r, 1);
        $this->assertSame(['Item 2: Missing question text'], $r->getData()['errors']);
        $this->assertSame(2, $r->getData()['total_items']);
    }

    public function testMarkerErrorsNameTheirReason(): void {
        $ten = ['o1', 'o2', 'o3', 'o4', 'o5', 'o6', 'o7', 'o8', 'o9', 'o10'];
        $r = $this->import([
            ['text' => 'Past eight', 'options' => $ten, 'correct_index' => 9],
            ['text' => 'Mask', 'options' => ['a', 'b', 'c', 'd'], 'correct' => [1, 0, 1, 0]],
            ['text' => 'Too many', 'options' => ['a', 'b'], 'correct' => [0, 1, 0]],
            ['text' => 'Huge', 'options' => array_map(static fn (int $i): string => "x$i", range(1, 51)), 'answer' => 0],
        ]);

        $this->assertSame(400, $r->getStatus());
        $this->assertSame([
            'Item 1: A correct answer comes after answer 8, but only the first 8 answers are imported — question skipped',
            'Item 2: Correct answer "1, 0, 1, 0" names the same answer twice — list each correct answer once (a 0/1 list per answer is not supported)',
            'Item 3: Correct answer "0, 1, 0" names more answers than the question has',
            'Item 4: More than 50 answers — question skipped',
        ], $r->getData()['errors']);
        $this->assertArrayNotHasKey('warnings', $r->getData(), 'no "defaulted to first answer" for a file that named its correct answer');
    }

    public function testATruncatedAnswerIsReportedAsAWarning(): void {
        $long = str_repeat('y', 2001);
        $r = $this->import([
            ['text' => 'MC', 'answers' => [['text' => $long, 'is_correct' => true], 'b']],
            ['text' => 'Open', 'type' => 'open', 'answer' => $long],
        ]);

        $this->assertImported($r, 2);
        $this->assertSame(['Item 1: Answer text truncated to 2000 chars', 'Item 2: Answer text truncated to 2000 chars'], $r->getData()['warnings']);
    }
}
