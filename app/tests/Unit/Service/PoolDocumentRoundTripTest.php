<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Answer;
use OCA\Learning\Db\AnswerMapper;
use OCA\Learning\Db\AnswerTranslation;
use OCA\Learning\Db\AnswerTranslationMapper;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CoursePoolMapper;
use OCA\Learning\Db\Pool;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\Question;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Db\QuestionTranslation;
use OCA\Learning\Db\QuestionTranslationMapper;
use OCA\Learning\Service\DataMobilityService;
use OCA\Learning\Service\PoolBulkImportService;
use OCA\Learning\Service\PoolJsonFormat;
use OCA\Learning\Service\PoolService;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\RichFeedbackFormat;
use OCA\Learning\Service\TranslationService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Unit\Controller\ImportControllerHarness;
use OCA\Learning\Tests\Unit\Support\PoolImportHarness;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pool export ⇄ import without losses (5.18.0): a pool exported as a learning-pool/2 document
 * (occ learning:export-pool, Export JSON in the interface) and imported again through
 * PoolBulkImportService (occ learning:import-pool-json, Import pools in the Pools tab) is the
 * same pool, field for field, IDs aside.
 *
 * The check is against the entities, not export against import: every property of Pool,
 * Question, Answer, QuestionTranslation and AnswerTranslation is either compared or listed in
 * NOT_CARRIED with the reason. A new column without either fails testEveryColumnIsCarriedOrExcluded.
 *
 * The importer runs on recording mocks; the stored entities are rebuilt from the calls as
 * QuestionService::create() and TranslationService store them. The same round trip against a real
 * Nextcloud: tests/Integration/PoolJsonRoundTripTest.php.
 */
#[AllowMockObjectsWithoutExpectations]
class PoolDocumentRoundTripTest extends TestCase {
    use ImportControllerHarness;
    use PoolImportHarness;

    /** Columns an export does not carry, and why. Everything else must come back unchanged. */
    private const NOT_CARRIED = [
        Pool::class => [
            'id' => 'new id', 'userId' => 'the importing user owns the new pool',
            'createdAt' => 'set on import', 'updatedAt' => 'set on import',
            'reviewStatus' => 'review state of this instance, an import is published',
            'reviewerId' => 'review state', 'reviewedAt' => 'review state',
            'visibility' => 'legacy column, read and ignored',
        ],
        Question::class => [
            'id' => 'new id', 'poolId' => 'the new pool', 'userId' => 'the importing user',
            'createdAt' => 'set on import', 'updatedAt' => 'set on import',
            'reviewStatus' => 'review state of this instance, an import is published',
            'reviewerId' => 'review state', 'reviewedAt' => 'review state',
            'imagePath' => 'a path into this instance\'s app data; never taken from a file (docs: Round trip)',
            'lang' => 'no code path writes it (QuestionService::create() has no parameter); always null for app-created questions',
        ],
        Answer::class => [
            'id' => 'new id', 'questionId' => 'the new question',
            // position: compared as the order of the answers (positionsInOrder), not as numbers
        ],
        QuestionTranslation::class => [
            'id' => 'new id', 'questionId' => 'the new question', 'createdAt' => 'set on import',
            // optionFeedback: compared with positions as answer order
        ],
        AnswerTranslation::class => [
            'id' => 'new id', 'answerId' => 'the new answer', 'createdAt' => 'set on import',
        ],
    ];

    private const RANKING = ['intro_note' => 'Order them', 'items' => [['id' => 'a', 'label' => 'Plan', 'correct_position' => 1], ['id' => 'b', 'label' => 'Do', 'correct_position' => 2]]];
    private const DROPDOWN = ['questions' => [['id' => 'q1', 'prompt' => 'HTTPS', 'options' => ['22', '443'], 'correct' => '443']]];

    /**
     * The source pool: one question per type (single, multi, open, two PBQ subtypes), every
     * field set somewhere, and the traps — a question without chapter in a pool with one, a
     * question without difficulty, a multi with one correct answer, answer positions with gaps.
     *
     * @return array{pool: Pool, questions: list<Question>, answers: array<int, list<Answer>>, qt: list<QuestionTranslation>, at: list<AnswerTranslation>}
     */
    private function source(): array {
        $pool = new Pool();
        $pool->setId(5);
        $pool->setName('Network basics');
        $pool->setDescription("Ports and protocols.\nSecond line.");
        $pool->setHandbookKey('ccna');
        $pool->setHandbookTitle('CCNA 200-301');
        $pool->setChapterKey('3');
        $pool->setChapterTitle('Transport layer');
        $pool->setChapterOrder(4);

        $single = new Question();
        $single->setId(21);
        $single->setText('Which port does HTTPS use?');
        $single->setQuestionType('single');
        $single->setExplanation('443/tcp.');
        $single->setDifficulty('hard');
        $single->setScenario("A web server.\nBehind a proxy.");
        $single->setHandbookKey('ccna');
        $single->setHandbookTitle('CCNA 200-301');
        $single->setExamKey('ccna-q7');
        $single->setChapterKey('3.2');
        $single->setChapterTitle('Transport');
        $single->setChapterOrder(7);
        $single->setInstructorNote('Ask about 8443 too.');
        $single->setNoteVisible(true);
        $single->setHint('Think of TLS.');
        $single->setBackgroundFact('443 was registered in 1994.');
        $single->setKeyTakeaway('HTTPS = 443.');
        // Positions 3 and 7: stored positions need not be 0, 1, …
        $single->setOptionFeedback('[{"position":3,"why":"80 is plain HTTP.","misconception":"Forgetting the S"},{"position":7,"why":"Registered for HTTPS.","misconception":null}]');

        $multi = new Question();
        $multi->setId(22);
        $multi->setText('Pick all that apply');
        $multi->setQuestionType('multi');
        // no difficulty, no chapter, no handbook — the pool's must not be filled in

        $open = new Question();
        $open->setId(23);
        $open->setText('Name the protocol on port 22.');
        $open->setQuestionType('open');
        $open->setDifficulty('easy');
        $open->setExamKey('ccna-q9');
        $open->setInstructorNote('Hidden note.');

        $ranking = new Question();
        $ranking->setId(24);
        $ranking->setText('Order the PDCA steps');
        $ranking->setQuestionType('pbq');
        $ranking->setPbqSubtype('ranking');
        $ranking->setPbqConfig((string)json_encode(self::RANKING));
        $ranking->setDifficulty('medium');
        $ranking->setScenario('A team meeting.');
        $ranking->setKeyTakeaway('Plan first.');
        $ranking->setChapterKey('1.0');

        $dropdown = new Question();
        $dropdown->setId(25);
        $dropdown->setText('Match the ports');
        $dropdown->setQuestionType('pbq');
        $dropdown->setPbqSubtype('dropdown');
        $dropdown->setPbqConfig((string)json_encode(self::DROPDOWN));
        $dropdown->setDifficulty('hard');

        $answers = [
            21 => [self::answer(211, 21, '80', false, 3), self::answer(212, 21, '443', true, 7)],
            22 => [self::answer(221, 22, 'a', true, 0), self::answer(222, 22, 'b', false, 1), self::answer(223, 22, 'c', false, 2)],
            23 => [self::answer(231, 23, 'SSH', true, 0)],
        ];

        $qt = [
            self::questionTranslation(21, 'en', 'Which port does HTTPS use? (en)', 'Port 443.', 'A web server (en).', 'TLS (en)', 'Registered 1994 (en).', '443 (en).',
                '[{"position":3,"why":"Plain HTTP (en).","misconception":null},{"position":7,"why":null,"misconception":"None (en)"}]'),
            self::questionTranslation(21, 'ru', 'Какой порт использует HTTPS?'),
            self::questionTranslation(22, 'de', 'Wähle alle passenden'),
            self::questionTranslation(22, 'fr', 'Choisis toutes les bonnes réponses'),
            self::questionTranslation(23, 'ar', 'ما البروتوكول على المنفذ 22؟', 'SSH.'),
            self::questionTranslation(24, 'de', 'Ordne die PDCA-Schritte', null, 'Eine Teambesprechung.', null, null, 'Erst planen.'),
        ];
        $at = [
            self::answerTranslation(211, 'en', '80 (en)'),
            self::answerTranslation(212, 'en', '443 (en)'),
            self::answerTranslation(212, 'ru', '443 (ru)'),
            self::answerTranslation(231, 'ar', 'إس إس إتش'),
            self::answerTranslation(221, 'uk', 'а (uk)'),
        ];

        return ['pool' => $pool, 'questions' => [$single, $multi, $open, $ranking, $dropdown], 'answers' => $answers, 'qt' => $qt, 'at' => $at];
    }

    /** @return array<string, mixed> the document, decoded from JSON as an importer reads the file */
    private function export(array $state): array {
        $poolMapper = $this->createMock(PoolMapper::class);
        $poolMapper->method('findById')->willReturn($state['pool']);
        $questionMapper = $this->createMock(QuestionMapper::class);
        $questionMapper->method('findByPoolId')->willReturn($state['questions']);
        $answerMapper = $this->createMock(AnswerMapper::class);
        $answerMapper->method('findByQuestions')->willReturn($state['answers']);
        $qtMapper = $this->createMock(QuestionTranslationMapper::class);
        $qtMapper->method('findByQuestions')->willReturn($state['qt']);
        $atMapper = $this->createMock(AnswerTranslationMapper::class);
        $atMapper->method('findByAnswers')->willReturn($state['at']);

        $document = (new DataMobilityService(
            $poolMapper,
            $questionMapper,
            $answerMapper,
            $this->createMock(CourseMapper::class),
            $this->createMock(CoursePoolMapper::class),
            $this->createMock(CourseMemberMapper::class),
            new FakeDbConnection(),
            $this->createMock(IUserManager::class),
            $qtMapper,
            $atMapper,
        ))->exportPoolDocument(5);

        return json_decode(json_encode($document, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Import a decoded document through PoolBulkImportService and rebuild what it stored.
     *
     * @param array<string, mixed> $document as read from the file
     * @return array{state: array, results: list<array>}
     */
    private function import(array $document, bool $dryRun = false): array {
        $poolArgs = null;
        $created = [];
        $qtCalls = [];
        $atCalls = [];

        $pools = $this->createMock(PoolService::class);
        $pools->method('create')->willReturnCallback(static function (...$args) use (&$poolArgs): Pool {
            $poolArgs = $args;
            $p = new Pool();
            $p->setId(77);
            return $p;
        });
        $questions = $this->createMock(QuestionService::class);
        $questions->method('create')->willReturnCallback(static function (...$args) use (&$created): array {
            $n = count($created) + 1;
            $created[] = $args;
            // What create() returns: the question with its answers in stored order (position = index).
            return ['id' => 500 + $n, 'answers' => array_map(static fn (int $i): array => ['id' => 5000 + $n * 10 + $i, 'position' => $i], array_keys($args[5]))];
        });
        $translations = $this->createMock(TranslationService::class);
        $translations->method('setQuestionTranslation')->willReturnCallback(static function (...$args) use (&$qtCalls): QuestionTranslation {
            $qtCalls[] = $args;
            return new QuestionTranslation();
        });
        $translations->method('setAnswerTranslation')->willReturnCallback(static function (...$args) use (&$atCalls): AnswerTranslation {
            $atCalls[] = $args;
            return new AnswerTranslation();
        });

        $importer = new PoolBulkImportService($pools, $questions, new FakeDbConnection(), $this->createMock(LoggerInterface::class), $translations);
        $prepared = PoolBulkImportService::asPoolsDocument($document, 'pool-5');
        $this->assertNotNull($prepared);
        $results = $importer->import($prepared, 'bob', $dryRun, $this->englishL10n());

        return ['state' => $dryRun ? [] : $this->rebuild($poolArgs, $created, $qtCalls, $atCalls), 'results' => $results];
    }

    /** The entities as PoolService::create(), QuestionService::create() and TranslationService store them. */
    private function rebuild(?array $poolArgs, array $created, array $qtCalls, array $atCalls): array {
        $this->assertNotNull($poolArgs, 'the pool was created');
        [$name, $description, , $hbKey, $hbTitle, $chKey, $chTitle, $chOrder] = $poolArgs;
        $pool = new Pool();
        $pool->setId(77);
        $pool->setName($name);
        $pool->setDescription($description);
        $pool->setHandbookKey($hbKey);
        $pool->setHandbookTitle($hbTitle);
        $pool->setChapterKey($chKey);
        $pool->setChapterTitle($chTitle);
        $pool->setChapterOrder($chOrder);

        $questions = [];
        $answers = [];
        foreach ($created as $i => $a) {
            // create(poolId, user, text, expl, difficulty, answers, type, sub, cfg, note, visible,
            //        hbKey, hbTitle, examKey, chKey, chTitle, chOrder, scenario, richFeedback)
            $q = new Question();
            $q->setId(501 + $i);
            $q->setText($a[2]);
            $q->setExplanation($a[3]);
            $q->setDifficulty($a[4]);
            $q->setQuestionType($a[6]);
            $q->setPbqSubtype($a[7]);
            $q->setPbqConfig($a[8]);
            $q->setInstructorNote($a[9]);
            $q->setNoteVisible($a[10]);
            $q->setHandbookKey($a[11]);
            $q->setHandbookTitle($a[12]);
            $q->setExamKey($a[13]);
            $q->setChapterKey($a[14]);
            $q->setChapterTitle($a[15]);
            $q->setChapterOrder($a[16]);
            $q->setScenario($a[17]);
            $rich = RichFeedbackFormat::normalize($a[18] ?? []);
            $q->setHint($rich['hint'] ?? null);
            $q->setBackgroundFact($rich['background_fact'] ?? null);
            $q->setKeyTakeaway($rich['key_takeaway'] ?? null);
            $q->setOptionFeedback(RichFeedbackFormat::encodeOptions($rich['option_feedback'] ?? []));
            $questions[] = $q;
            foreach ($a[5] as $position => $answer) {
                $answers[501 + $i][] = self::answer(5000 + ($i + 1) * 10 + $position, 501 + $i, $answer['text'], $answer['is_correct'], $position);
            }
        }

        $qt = [];
        foreach ($qtCalls as [$questionId, $lang, $text, $explanation, $scenario, $rich]) {
            $rich = RichFeedbackFormat::normalize($rich ?? []);
            $qt[] = self::questionTranslation($questionId, $lang, $text, $explanation, $scenario === '' ? null : $scenario,
                $rich['hint'] ?? null, $rich['background_fact'] ?? null, $rich['key_takeaway'] ?? null,
                RichFeedbackFormat::encodeOptions($rich['option_feedback'] ?? []));
        }
        $at = [];
        foreach ($atCalls as [$answerId, $lang, $text]) {
            $at[] = self::answerTranslation($answerId, $lang, $text);
        }
        return ['pool' => $pool, 'questions' => $questions, 'answers' => $answers, 'qt' => $qt, 'at' => $at];
    }

    public function testTheExportIsALearningPool2DocumentWithThePoolFields(): void {
        $document = $this->export($this->source());

        $this->assertSame(['format' => PoolJsonFormat::EXPORT_FORMAT], $document['_meta']);
        $this->assertSame(['Network basics', "Ports and protocols.\nSecond line.", 'ccna', 'CCNA 200-301', '3', 'Transport layer', 4], [
            $document['name'], $document['description'], $document['handbook_key'], $document['handbook_title'],
            $document['chapter_key'], $document['chapter_title'], $document['chapter_order'],
        ]);
        $this->assertCount(5, $document['questions']);
        $single = $document['questions'][0];
        $this->assertSame(['Ask about 8443 too.', true], [$single['instructor_note'], $single['note_visible']]);
        $this->assertSame(['en', 'ru'], array_keys($single['translations']));
        $this->assertSame([
            ['option_index' => 0, 'letter' => 'A', 'verdict' => 'wrong', 'why' => 'Plain HTTP (en).'],
            ['option_index' => 1, 'letter' => 'B', 'verdict' => 'correct', 'misconception' => 'None (en)'],
        ], $single['translations']['en']['feedback_per_option']);
        $this->assertSame(['en' => '443 (en)', 'ru' => '443 (ru)'], $single['answers'][1]['translations']);
        $this->assertArrayNotHasKey('note_visible', $document['questions'][2], 'a hidden note is the default');
        $this->assertArrayNotHasKey('image_path', $single);
    }

    public function testExportThenImportIsTheSamePoolFieldForField(): void {
        $source = $this->source();
        ['state' => $copy, 'results' => $results] = $this->import($this->export($source));

        $this->assertSame(5, $results[0]['imported'], json_encode($results));
        $this->assertSame([], $results[0]['question_errors']);
        $this->assertSame([], $results[0]['warnings']);

        $this->assertSameEntity($source['pool'], $copy['pool']);
        $this->assertCount(count($source['questions']), $copy['questions']);
        foreach ($source['questions'] as $i => $original) {
            $rebuilt = $copy['questions'][$i];
            $originalAnswers = $source['answers'][$original->getId()] ?? [];
            $rebuiltAnswers = $copy['answers'][$rebuilt->getId()] ?? [];
            $this->assertSameEntity($original, $rebuilt, ['optionFeedback' => [self::byRank($originalAnswers, $original->getOptionFeedback()), self::byRank($rebuiltAnswers, $rebuilt->getOptionFeedback())]]);

            $this->assertCount(count($originalAnswers), $rebuiltAnswers, (string)$original->getText());
            foreach ($originalAnswers as $k => $answer) {
                $this->assertSameEntity($answer, $rebuiltAnswers[$k], ['position' => [$k, (int)$rebuiltAnswers[$k]->getPosition()]]);
                $this->assertSame(
                    self::answerTranslationsOf($source['at'], (int)$answer->getId()),
                    self::answerTranslationsOf($copy['at'], (int)$rebuiltAnswers[$k]->getId()),
                    'answer translations of ' . $answer->getText()
                );
            }

            $originalT = self::questionTranslationsOf($source['qt'], (int)$original->getId());
            $rebuiltT = self::questionTranslationsOf($copy['qt'], (int)$rebuilt->getId());
            $this->assertSame(array_keys($originalT), array_keys($rebuiltT), 'translation languages of ' . $original->getText());
            foreach ($originalT as $lang => $t) {
                $this->assertSameEntity($t, $rebuiltT[$lang], ['optionFeedback' => [self::byRank($originalAnswers, $t->getOptionFeedback()), self::byRank($rebuiltAnswers, $rebuiltT[$lang]->getOptionFeedback())]]);
            }
        }
    }

    /** The fixpoint: exporting the imported copy gives the same file. */
    public function testTheImportedCopyExportsToTheSameDocument(): void {
        $first = $this->export($this->source());
        $copy = $this->import($first)['state'];

        $this->assertSame($first, $this->export($copy));
    }

    /** The dry run promises exactly what the import stores. */
    public function testTheDryRunAgrees(): void {
        $results = $this->import($this->export($this->source()), true)['results'];

        $this->assertSame([5, 0, [], []], [$results[0]['importable'], $results[0]['imported'], $results[0]['question_errors'], $results[0]['warnings']]);
    }

    /**
     * Every column of the five tables is compared above or excluded with a reason, so a new
     * column cannot silently go missing from the export.
     */
    public function testEveryColumnIsCarriedOrExcluded(): void {
        $compared = [
            Pool::class => ['name', 'description', 'handbookKey', 'handbookTitle', 'chapterKey', 'chapterTitle', 'chapterOrder'],
            Question::class => ['text', 'explanation', 'difficulty', 'questionType', 'pbqSubtype', 'pbqConfig', 'instructorNote', 'noteVisible',
                'handbookKey', 'handbookTitle', 'examKey', 'chapterKey', 'chapterTitle', 'chapterOrder', 'scenario',
                'hint', 'backgroundFact', 'keyTakeaway', 'optionFeedback'],
            Answer::class => ['text', 'isCorrect', 'position'],
            QuestionTranslation::class => ['lang', 'text', 'explanation', 'scenario', 'hint', 'backgroundFact', 'keyTakeaway', 'optionFeedback'],
            AnswerTranslation::class => ['lang', 'text'],
        ];
        foreach ($compared as $class => $fields) {
            // id is declared on OCP\AppFramework\Db\Entity, the rest on the class itself.
            $all = array_merge(['id'], self::properties($class));
            $listed = array_merge($fields, array_keys(self::NOT_CARRIED[$class]));
            sort($all);
            sort($listed);
            $this->assertSame($all, $listed, $class . ': a property is neither carried by the export nor excluded with a reason');
        }
    }

    /** The question import in a pool (Manage → Import) reads the new export as before. */
    public function testTheQuestionImportInAPoolReadsTheExport(): void {
        $r = $this->importController()->importJson(9, json_encode($this->export($this->source()), JSON_THROW_ON_ERROR));

        $this->assertSame(201, $r->getStatus(), json_encode($r->getData()));
        $this->assertSame(5, $r->getData()['imported'], json_encode($r->getData()));
        $this->assertArrayNotHasKey('errors', array_filter($r->getData()), json_encode($r->getData()));
        $this->assertSame('multi', $this->questions[1]->getQuestionType());
        $this->assertNull($this->questions[1]->getChapterKey(), 'the pool\'s chapter is not filled into the question');
    }

    /**
     * Only a learning-pool/2 file skips the defaults: the same pool without the marker still gets
     * the pool's chapter and `medium` for a question without them (format 1 as before).
     */
    public function testDefaultsStillApplyWithoutTheExportMarker(): void {
        $question = ['text' => 'Q', 'type' => 'single', 'answers' => [['text' => 'a', 'is_correct' => true], ['text' => 'b', 'is_correct' => false]]];
        $plain = ['pools' => [['name' => 'P', 'chapter_key' => '3', 'questions' => [$question]]]];
        $created = [];
        $questions = $this->createMock(QuestionService::class);
        $questions->method('create')->willReturnCallback(static function (...$args) use (&$created): array {
            $created[] = $args;
            return [];
        });
        $pools = $this->createMock(PoolService::class);
        $pools->method('create')->willReturnCallback(static function (): Pool {
            $p = new Pool();
            $p->setId(1);
            return $p;
        });
        $importer = $this->poolImporter($pools, $questions);

        $importer->import($plain, 'bob', false, $this->englishL10n());
        $plain['_meta'] = ['format' => PoolJsonFormat::EXPORT_FORMAT];
        $importer->import($plain, 'bob', false, $this->englishL10n());

        $this->assertSame(['medium', '3'], [$created[0][4], $created[0][14]], 'format 1: defaults as before');
        $this->assertSame([null, null], [$created[1][4], $created[1][14]], 'learning-pool/2: nothing filled in');
    }

    /** An older export — a bare list of questions — still imports, with its types. */
    public function testAnOldBareListExportStillImports(): void {
        $document = $this->export($this->source());
        $results = $this->import($document['questions'])['results'];

        $this->assertSame('pool-5', $results[0]['name']);
        $this->assertSame(5, $results[0]['imported'], json_encode($results));
    }

    /** A translation the app cannot store is left out with a warning; the question is imported. */
    public function testATranslationThatCannotBeStoredIsReportedNotFatal(): void {
        $document = ['_meta' => ['format' => PoolJsonFormat::EXPORT_FORMAT], 'name' => 'P', 'questions' => [[
            'text' => 'Q', 'type' => 'single',
            'translations' => [
                'es' => ['text' => 'Q (es)'],
                'en' => ['explanation' => 'no text'],
                'de' => ['text' => 'Q (de)', 'scenario' => str_repeat('x', QuestionService::SCENARIO_MAX_LENGTH + 1)],
                'ru' => ['text' => 'Q (ru)'],
            ],
            'answers' => [
                ['text' => 'a', 'is_correct' => true, 'translations' => ['pl' => 'a (pl)', 'ru' => 'a (ru)']],
                ['text' => 'b', 'is_correct' => false, 'translations' => ['ru' => '']],
            ],
        ]]];

        ['state' => $copy, 'results' => $results] = $this->import($document);

        $this->assertSame(1, $results[0]['imported'], json_encode($results));
        $this->assertSame([
            'Q1: translation en was not imported — Translation text missing',
            'Q1: translation de was not imported — Scenario must be max ' . QuestionService::SCENARIO_MAX_LENGTH . ' characters',
            'Q1: translation ru was not imported — Translation text missing',
            'Q1: translations into es, pl were not imported — supported languages: de, en, fr, ru, ar, uk',
        ], $results[0]['warnings']);
        $this->assertSame(['ru'], array_map(static fn (QuestionTranslation $t): string => (string)$t->getLang(), $copy['qt']));
        $this->assertSame([['ru', 'a (ru)']], array_map(static fn (AnswerTranslation $t): array => [$t->getLang(), $t->getText()], $copy['at']));
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * Every compared property of two entities equal; $special overrides a property with its
     * [expected, actual] pair.
     *
     * @param array<string, array{0: mixed, 1: mixed}> $special
     */
    private function assertSameEntity(object $expected, object $actual, array $special = []): void {
        $class = get_class($expected);
        foreach (self::properties($class) as $property) {
            if (isset(self::NOT_CARRIED[$class][$property])) {
                continue;
            }
            [$want, $got] = $special[$property] ?? [$expected->{'get' . ucfirst($property)}(), $actual->{'get' . ucfirst($property)}()];
            $this->assertSame($want, $got, sprintf('%s::%s', $class, $property));
        }
    }

    /** @return list<string> */
    private static function properties(string $class): array {
        $out = [];
        foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PROTECTED) as $p) {
            if ($p->getDeclaringClass()->getName() === $class) {
                $out[] = $p->getName();
            }
        }
        return $out;
    }

    /**
     * Option feedback with each stored position replaced by the answer's rank in stored order —
     * positions are renumbered on import (0, 1, …), the answer an entry belongs to is what counts.
     *
     * @param list<Answer> $answers in stored order
     * @return list<array{rank: ?int, why: ?string, misconception: ?string}>
     */
    private static function byRank(array $answers, ?string $optionFeedback): array {
        $rank = [];
        foreach (array_values($answers) as $i => $a) {
            $rank[(int)$a->getPosition()] = $i;
        }
        return array_map(static fn (array $e): array => [
            'rank' => $rank[$e['position']] ?? null,
            'why' => $e['why'],
            'misconception' => $e['misconception'],
        ], RichFeedbackFormat::decodeOptions($optionFeedback));
    }

    /** @return array<string, QuestionTranslation> */
    private static function questionTranslationsOf(array $all, int $questionId): array {
        $out = [];
        foreach ($all as $t) {
            if ((int)$t->getQuestionId() === $questionId) {
                $out[(string)$t->getLang()] = $t;
            }
        }
        ksort($out);
        return $out;
    }

    /** @return array<string, string> */
    private static function answerTranslationsOf(array $all, int $answerId): array {
        $out = [];
        foreach ($all as $t) {
            if ((int)$t->getAnswerId() === $answerId) {
                $out[(string)$t->getLang()] = (string)$t->getText();
            }
        }
        ksort($out);
        return $out;
    }

    private static function answer(int $id, int $questionId, string $text, bool $correct, int $position): Answer {
        $a = new Answer();
        $a->setId($id);
        $a->setQuestionId($questionId);
        $a->setText($text);
        $a->setIsCorrect($correct);
        $a->setPosition($position);
        return $a;
    }

    private static function questionTranslation(int $questionId, string $lang, string $text, ?string $explanation = null, ?string $scenario = null,
        ?string $hint = null, ?string $background = null, ?string $takeaway = null, ?string $optionFeedback = null): QuestionTranslation {
        $t = new QuestionTranslation();
        $t->setQuestionId($questionId);
        $t->setLang($lang);
        $t->setText($text);
        $t->setExplanation($explanation);
        $t->setScenario($scenario);
        $t->setHint($hint);
        $t->setBackgroundFact($background);
        $t->setKeyTakeaway($takeaway);
        $t->setOptionFeedback($optionFeedback);
        return $t;
    }

    private static function answerTranslation(int $answerId, string $lang, string $text): AnswerTranslation {
        $t = new AnswerTranslation();
        $t->setAnswerId($answerId);
        $t->setLang($lang);
        $t->setText($text);
        return $t;
    }
}
