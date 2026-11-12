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
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;

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

    private function importController(?IRequest $request = null, string $translationMarker = ''): ImportController {
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
            $this->l10nStub($translationMarker)
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
