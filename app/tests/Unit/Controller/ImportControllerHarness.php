<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\ImportController;
use OCA\Learning\Db\Answer;
use OCA\Learning\Db\AnswerMapper;
use OCA\Learning\Db\Pool;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\PoolShareMapper;
use OCA\Learning\Db\Question;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Db\AnswerTranslation;
use OCA\Learning\Db\QuestionTranslation;
use OCA\Learning\Service\PoolBulkImportService;
use OCA\Learning\Service\PoolService;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\TranslationService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The real ImportController with mapper mocks that record what would be written.
 * IL10N renders the English source with its parameters (what an untranslated instance shows);
 * pass $translate to put a marker in front and prove a message went through t().
 */
trait ImportControllerHarness {
    /** @var list<Question> */
    private array $questions = [];
    /** @var array<int, list<Answer>> answers per question id */
    private array $answers = [];
    private int $nextId = 1000;
    private FakeDbConnection $db;
    /** @var list<array{0: string, 1: list<mixed>}> TranslationService calls: [method, args] */
    private array $translationCalls = [];

    private function importController(?IRequest $request = null, string $translationMarker = ''): ImportController {
        $this->questions = [];
        $this->answers = [];
        $this->translationCalls = [];
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
            if ($a->getId() === null) {
                $a->setId($this->nextId++);
            }
            $this->answers[(int)$a->getQuestionId()][] = $a;
            return $a;
        });

        // The translations go through the real checks of PoolBulkImportService into a recording
        // TranslationService (which validates the language as the real one does).
        $translations = $this->createMock(TranslationService::class);
        $translations->method('setQuestionTranslation')->willReturnCallback(function (...$args): QuestionTranslation {
            if (!in_array($args[1], TranslationService::ALLOWED_LANGS, true)) {
                throw new \InvalidArgumentException('Language must be one of: ' . implode(', ', TranslationService::ALLOWED_LANGS));
            }
            $this->translationCalls[] = ['question', $args];
            return new QuestionTranslation();
        });
        $translations->method('setAnswerTranslation')->willReturnCallback(function (...$args): AnswerTranslation {
            $this->translationCalls[] = ['answer', $args];
            return new AnswerTranslation();
        });
        $poolImport = new PoolBulkImportService(
            $this->createMock(PoolService::class),
            $this->createMock(QuestionService::class),
            $this->db,
            $this->createMock(LoggerInterface::class),
            $translations
        );

        $poolMapper = $this->createMock(PoolMapper::class);
        $poolMapper->method('find')->willReturn(new Pool());

        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(fn (string $app, string $key, $default = '') => $default);

        return new ImportController(
            'learning',
            $request ?? $this->createMock(IRequest::class),
            $questionMapper,
            $answerMapper,
            $poolMapper,
            $this->createMock(PoolShareMapper::class),
            $config,
            $this->db,
            'teacher',
            $this->l10nStub($translationMarker),
            $poolImport
        );
    }

    private function l10nStub(string $marker = ''): IL10N {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(
            static fn (string $text, $parameters = []): string => $marker . vsprintf($text, is_array($parameters) ? $parameters : [$parameters])
        );
        return $l10n;
    }

    /** @return list<array{text: string, correct: bool}> */
    private function storedAnswers(Question $q): array {
        return array_map(
            static fn (Answer $a): array => ['text' => (string)$a->getText(), 'correct' => (bool)$a->getIsCorrect()],
            $this->answers[(int)$q->getId()] ?? []
        );
    }
}
