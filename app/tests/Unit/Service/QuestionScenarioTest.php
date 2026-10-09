<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Controller\QuestionController;
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
use OCA\Learning\Service\TranslationService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Codeberg #10: a question can carry a scenario / info text that learners read before the
 * question. These tests pin how it is stored, kept, cleared, translated and delivered.
 */
class QuestionScenarioTest extends TestCase {
    private const ANSWERS = [
        ['text' => 'Yes', 'is_correct' => true],
        ['text' => 'No', 'is_correct' => false],
    ];

    /** @var Question[] what the mapper was asked to persist */
    private array $saved = [];
    private ?Question $stored = null;
    private FakeDbConnection $db;

    private function createService(array $queuedBuilders = [], ?TranslationService $translations = null): QuestionService {
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
        $answerMapper->method('createOrUpdate')->willReturnArgument(0);

        $poolMapper = $this->createMock(PoolMapper::class);
        $poolMapper->method('find')->willReturn(new Pool());

        if ($translations === null) {
            $translations = $this->createMock(TranslationService::class);
            $translations->method('translateQuestion')->willReturnArgument(0);
        }

        $this->db = new FakeDbConnection($queuedBuilders);
        return new QuestionService(
            $questionMapper,
            $answerMapper,
            $this->createMock(PoolShareMapper::class),
            $poolMapper,
            $this->db,
            $translations,
            $this->createMock(LoggerInterface::class)
        );
    }

    private function storedQuestion(?string $scenario): Question {
        $q = new Question();
        $q->setId(42);
        $q->setPoolId(7);
        $q->setText('Old text');
        $q->setQuestionType('single');
        $q->setScenario($scenario);
        return $q;
    }

    public function testCreateStoresScenarioWithLineBreaksTrimmed(): void {
        $service = $this->createService();
        $result = $service->create(7, 'author', 'What do you do?', null, null, self::ANSWERS,
            scenario: "  A patient arrives at 3 a.m.\n\nShe is confused.  \n");

        $this->assertSame("A patient arrives at 3 a.m.\n\nShe is confused.", $this->saved[0]->getScenario());
        $this->assertSame("A patient arrives at 3 a.m.\n\nShe is confused.", $result['scenario']);
    }

    public function testCreateStoresBlankScenarioAsNull(): void {
        $service = $this->createService();
        $service->create(7, 'author', 'Q?', null, null, self::ANSWERS, scenario: " \n ");

        $this->assertNull($this->saved[0]->getScenario());
    }

    public function testTooLongScenarioIsRejectedBeforeAnyWrite(): void {
        $service = $this->createService();
        try {
            $service->create(7, 'author', 'Q?', null, null, self::ANSWERS,
                scenario: str_repeat('x', QuestionService::SCENARIO_MAX_LENGTH + 1));
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Scenario', $e->getMessage());
        }
        $this->assertSame([], $this->saved);
        $this->assertSame(0, $this->db->beginTransactionCalls);
    }

    public function testScenarioAtTheLimitIsAccepted(): void {
        $service = $this->createService();
        $service->create(7, 'author', 'Q?', null, null, self::ANSWERS,
            scenario: str_repeat('ä', QuestionService::SCENARIO_MAX_LENGTH));

        $this->assertSame(QuestionService::SCENARIO_MAX_LENGTH, mb_strlen((string)$this->saved[0]->getScenario()));
    }

    public function testUpdateWithoutScenarioKeepsTheStoredOne(): void {
        $this->stored = $this->storedQuestion('Keep me');
        $service = $this->createService();
        $service->update(42, 'author', 'New text', null, null, self::ANSWERS, scenario: null);

        $this->assertSame('New text', $this->saved[0]->getText());
        $this->assertSame('Keep me', $this->saved[0]->getScenario());
    }

    public function testUpdateWithEmptyScenarioClearsIt(): void {
        $this->stored = $this->storedQuestion('Remove me');
        $service = $this->createService();
        $service->update(42, 'author', 'New text', null, null, self::ANSWERS, scenario: '');

        $this->assertNull($this->saved[0]->getScenario());
    }

    public function testUpdateReplacesScenario(): void {
        $this->stored = $this->storedQuestion('Old');
        $service = $this->createService();
        $service->update(42, 'author', 'New text', null, null, self::ANSWERS, scenario: 'New scenario');

        $this->assertSame('New scenario', $this->saved[0]->getScenario());
    }

    /** The only frontend caller always sends the key; other API clients may not. */
    public function testControllerUpdateTellsKeptFromCleared(): void {
        $calls = [];
        $service = $this->createMock(QuestionService::class);
        $service->method('update')->willReturnCallback(function (...$args) use (&$calls): array {
            $calls[] = array_key_exists(17, $args) ? $args[17] : 'MISSING';
            return [];
        });

        foreach ([[], ['scenario' => null], ['scenario' => ''], ['scenario' => 'Text']] as $params) {
            $request = $this->createMock(IRequest::class);
            $request->method('getParams')->willReturn(['id' => 42, 'text' => 'Q?'] + $params);
            $controller = new QuestionController('learning', $request, $service, 'author');
            $controller->update(42, 'Q?', null, null, self::ANSWERS,
                scenario: array_key_exists('scenario', $params) ? $params['scenario'] : null);
        }

        // absent → null (keep); explicit null → '' (clear); '' → '' (clear); text → text
        $this->assertSame([null, '', '', 'Text'], $calls);
    }

    public function testGameQuestionCarriesScenario(): void {
        $questionQuery = new FakeQueryBuilder(FakeResult::fromFetch([
            'id' => 42, 'text' => 'Q?', 'image_path' => null, 'scenario' => 'Read this first',
        ]));
        $answersQuery = new FakeQueryBuilder(new FakeResult([
            ['id' => 1, 'text' => 'Yes', 'position' => 0],
        ]));
        $service = $this->createService([$questionQuery, $answersQuery]);

        $question = $service->loadQuestionForGame(42);

        $this->assertContains('scenario', $questionQuery->selects);
        $this->assertSame('Read this first', $question['scenario'] ?? null);
    }

    private function translationService(?QuestionTranslation $translation): TranslationService {
        $mapper = $this->createMock(QuestionTranslationMapper::class);
        $mapper->method('findByQuestionsAndLang')->willReturn($translation ? [$translation] : []);
        $answers = $this->createMock(AnswerTranslationMapper::class);
        $answers->method('findByAnswersAndLang')->willReturn([]);
        return new TranslationService(
            $mapper,
            $answers,
            $this->createMock(QuestionMapper::class),
            $this->createMock(PoolMapper::class),
            $this->createMock(PoolShareMapper::class),
            new FakeDbConnection()
        );
    }

    private function translation(?string $scenario): QuestionTranslation {
        $t = new QuestionTranslation();
        $t->setQuestionId(42);
        $t->setLang('en');
        $t->setText('Translated question');
        $t->setScenario($scenario);
        return $t;
    }

    public function testTranslatedScenarioReplacesOriginal(): void {
        $service = $this->translationService($this->translation('Translated scenario'));
        $out = $service->translateQuestion(['id' => 42, 'text' => 'Frage', 'scenario' => 'Szenario'], 'en');

        $this->assertSame('Translated scenario', $out['scenario']);
        $this->assertSame('Translated question', $out['text']);
    }

    public function testMissingScenarioTranslationKeepsOriginal(): void {
        $service = $this->translationService($this->translation(null));
        $out = $service->translateQuestion(['id' => 42, 'text' => 'Frage', 'scenario' => 'Szenario'], 'en');

        $this->assertSame('Szenario', $out['scenario']);
    }

    /** An author who removed the scenario must not see a stale translation resurface. */
    public function testTranslationDoesNotResurrectARemovedScenario(): void {
        $service = $this->translationService($this->translation('Stale translation'));
        $out = $service->translateQuestion(['id' => 42, 'text' => 'Frage', 'scenario' => null], 'en');

        $this->assertNull($out['scenario']);
    }

    public function testExamReviewScenarioIsTranslated(): void {
        $service = $this->translationService($this->translation('Translated scenario'));
        $out = $service->translateReviewEntries([
            ['questionId' => 42, 'questionText' => 'Frage', 'questionType' => 'single', 'scenario' => 'Szenario'],
        ], 'en');

        $this->assertSame('Translated scenario', $out[0]['scenario']);
    }
}
