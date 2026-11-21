<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Command\ExportPoolCommand;
use OCA\Learning\Controller\ImportController;
use OCA\Learning\Command\ImportPoolJsonCommand;
use OCA\Learning\Db\Answer;
use OCA\Learning\Db\AnswerMapper;
use OCA\Learning\Db\AnswerTranslation;
use OCA\Learning\Db\AnswerTranslationMapper;
use OCA\Learning\Db\Pool;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\PoolShareMapper;
use OCA\Learning\Db\Question;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Db\QuestionTranslation;
use OCA\Learning\Db\QuestionTranslationMapper;
use OCA\Learning\Service\DataMobilityService;
use OCA\Learning\Service\PoolBulkImportService;
use OCA\Learning\Service\PoolService;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\TranslationService;
use OCA\Learning\Tests\Integration\Support\PoolColumns;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\L10N\IFactory;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A pool exported with `occ learning:export-pool` and imported again with
 * `occ learning:import-pool-json` keeps every question and its type — and, since 5.18.0 (format
 * learning-pool/2), every field: pool metadata, translations, instructor notes, rich feedback.
 * The same round trip as the unit test tests/Unit/Service/PoolDocumentRoundTripTest.php, on a real
 * database: the export of the copy equals the export of the source, and — because a field the
 * export never writes would be missing from both exports alike — the rows stored for the copy
 * equal the rows stored for the source, column by column (PoolColumns lists what is compared and
 * what is left out with a reason), with every compared column set somewhere in the source.
 */
final class PoolJsonRoundTripTest extends TestCase {
	private const USER = 'roundtrip-it';

	/** @var int[] */
	private array $poolIds = [];

	protected function tearDown(): void {
		$pools = Server::get(PoolService::class);
		foreach ($this->poolIds as $id) {
			try {
				$pools->delete($id, self::USER);
			} catch (\Throwable $e) {
				// best effort: a failed cleanup must not hide the assertion that failed
			}
		}
	}

	public function testExportedPoolImportsWithAllQuestionTypes(): void {
		$source = Server::get(PoolService::class)->create('Round trip source', null, self::USER);
		$this->poolIds[] = $source->getId();
		$questions = Server::get(QuestionService::class);
		$questions->create($source->getId(), self::USER, 'Single question', null, 'medium', [
			['text' => 'right', 'is_correct' => true],
			['text' => 'wrong', 'is_correct' => false],
		], 'single');
		$questions->create($source->getId(), self::USER, 'Multi question', null, 'medium', [
			['text' => 'right one', 'is_correct' => true],
			['text' => 'right two', 'is_correct' => true],
			['text' => 'wrong', 'is_correct' => false],
		], 'multi');
		$questions->create($source->getId(), self::USER, 'Open question', null, 'medium', [
			['text' => 'model answer', 'is_correct' => true],
		], 'open');

		$exported = Server::get(DataMobilityService::class)->exportPoolJson($source->getId());
		$file = tempnam(sys_get_temp_dir(), 'learning-roundtrip-');
		$this->assertNotFalse($file);
		file_put_contents($file, json_encode(['pools' => [['name' => 'Round trip copy', 'questions' => $exported]]]));

		$output = new BufferedOutput();
		$exit = Server::get(ImportPoolJsonCommand::class)->run(new ArrayInput(['file' => $file, '--user' => self::USER]), $output);
		unlink($file);
		$log = $output->fetch();
		$this->assertSame(0, $exit, $log);
		$this->assertSame(1, preg_match('/Pool ID: (\d+)/', $log, $m), $log);
		$this->poolIds[] = (int)$m[1];

		$this->assertSame(
			['Multi question' => 'multi', 'Open question' => 'open', 'Single question' => 'single'],
			$this->typesByText((int)$m[1]),
			$log
		);
	}

	public function testExportThenImportKeepsEveryField(): void {
		$source = $this->fullSource();

		$file = tempnam(sys_get_temp_dir(), 'learning-roundtrip-');
		$this->assertNotFalse($file);
		$out = new BufferedOutput();
		$this->assertSame(0, Server::get(ExportPoolCommand::class)->run(new ArrayInput(['poolId' => (string)$source->getId(), '--output' => $file]), $out), $out->fetch());

		$output = new BufferedOutput();
		$exit = Server::get(ImportPoolJsonCommand::class)->run(new ArrayInput(['file' => $file, '--user' => self::USER]), $output);
		unlink($file);
		$log = $output->fetch();
		$this->assertSame(0, $exit, $log);
		$this->assertStringNotContainsString('Warnings/Errors', $log);
		$this->assertSame(1, preg_match('/Pool ID: (\d+)/', $log, $m), $log);
		$this->poolIds[] = (int)$m[1];

		$mobility = Server::get(DataMobilityService::class);
		$expected = $mobility->exportPoolDocument($source->getId());
		$this->assertSame('Round trip full', $expected['name']);
		$this->assertArrayHasKey('translations', $expected['questions'][0]);
		$this->assertSame($expected, $mobility->exportPoolDocument((int)$m[1]), $log);

		$this->assertStoredLikeSource($source->getId(), (int)$m[1]);
	}

	public function testSeveralCorrectAnswersWithoutTypeImportAsMulti(): void {
		$this->assertSame('multi', ImportPoolJsonCommand::resolveQuestionType([], 'single', [
			['text' => 'a', 'is_correct' => true],
			['text' => 'b', 'is_correct' => true],
		]));
		$this->assertSame('single', ImportPoolJsonCommand::resolveQuestionType(['type' => 'true_false'], 'single', []));
		$this->assertSame('open', ImportPoolJsonCommand::resolveQuestionType(['question_type' => 'open', 'type' => 'multi'], 'single', []));
	}

	/**
	 * The import into an existing pool (Manage → Import, ImportController::importJson) on a
	 * real database: the export of the full pool goes into an empty pool and every question,
	 * answer and translation column comes back — instructor notes and translations included,
	 * which this import dropped before 5.18.0. The pool's own fields cannot go into an existing
	 * pool; the answer names them. The controller also resolves through the DI container with
	 * its new dependency.
	 */
	public function testTheQuestionImportInAPoolKeepsEveryQuestionField(): void {
		$this->assertInstanceOf(ImportController::class, Server::get(ImportController::class));

		$source = $this->fullSource();
		$target = Server::get(PoolService::class)->create('Import target', null, self::USER);
		$this->poolIds[] = $target->getId();
		$document = Server::get(DataMobilityService::class)->exportPoolDocument($source->getId());

		$l10n = Server::get(IFactory::class)->get('learning', 'en');
		$controller = new ImportController(
			'learning',
			Server::get(IRequest::class),
			Server::get(QuestionMapper::class),
			Server::get(AnswerMapper::class),
			Server::get(PoolMapper::class),
			Server::get(PoolShareMapper::class),
			Server::get(IConfig::class),
			Server::get(IDBConnection::class),
			self::USER,
			$l10n,
			Server::get(PoolBulkImportService::class)
		);
		$response = $controller->importJson($target->getId(), (string)json_encode($document));
		$data = $response->getData();

		$this->assertSame(201, $response->getStatus(), (string)json_encode($data));
		$this->assertSame(5, $data['imported'], (string)json_encode($data));
		$this->assertSame([], $data['errors'], (string)json_encode($data));
		$this->assertCount(1, $data['warnings'] ?? [], (string)json_encode($data));
		$this->assertStringContainsString('(name, description, handbook_key, handbook_title, chapter_key, chapter_title, chapter_order)', $data['warnings'][0]);
		$this->assertStoredLikeSource($source->getId(), $target->getId(), false);
	}

	/**
	 * The source pool: metadata, one question per type (single, multi with one correct answer,
	 * open, ranking and dropdown PBQ), instructor notes, rich feedback, question and answer
	 * translations in several languages.
	 */
	private function fullSource(): Pool {
		$source = Server::get(PoolService::class)->create('Round trip full', "Ports.\nSecond line.", self::USER, 'ccna', 'CCNA 200-301', '3', 'Transport layer', 4);
		$this->poolIds[] = $source->getId();
		$questions = Server::get(QuestionService::class);
		$translations = Server::get(TranslationService::class);

		$single = $questions->create($source->getId(), self::USER, 'Which port does HTTPS use?', '443/tcp.', 'hard', [
			['text' => '80', 'is_correct' => false],
			['text' => '443', 'is_correct' => true],
		], 'single', null, null, 'Ask about 8443 too.', true, 'ccna', 'CCNA 200-301', 'ccna-q7', '3.2', 'Transport', 7, "A web server.\nBehind a proxy.", [
			'hint' => 'Think of TLS.',
			'background_fact' => '443 was registered in 1994.',
			'key_takeaway' => 'HTTPS = 443.',
			'option_feedback' => [['position' => 0, 'why' => '80 is plain HTTP.', 'misconception' => 'Forgetting the S'], ['position' => 1, 'why' => 'Registered for HTTPS.', 'misconception' => null]],
		]);
		$translations->setQuestionTranslation((int)$single['id'], 'en', 'Which port? (en)', 'Port 443.', 'A web server (en).', [
			'hint' => 'TLS (en)', 'background_fact' => 'Registered 1994 (en).', 'key_takeaway' => '443 (en).',
			'option_feedback' => [['position' => 0, 'why' => 'Plain HTTP (en).', 'misconception' => null]],
		]);
		$translations->setQuestionTranslation((int)$single['id'], 'ru', 'Какой порт?');
		$translations->setAnswerTranslation((int)$single['answers'][1]['id'], 'en', '443 (en)');
		$translations->setAnswerTranslation((int)$single['answers'][1]['id'], 'ru', '443 (ru)');
		// multi with one correct answer, no difficulty, no chapter in a pool with one
		$questions->create($source->getId(), self::USER, 'Pick all that apply', null, null, [
			['text' => 'a', 'is_correct' => true],
			['text' => 'b', 'is_correct' => false],
		], 'multi');
		$open = $questions->create($source->getId(), self::USER, 'Name the protocol on port 22.', null, 'easy', [
			['text' => 'SSH', 'is_correct' => true],
		], 'open', null, null, 'Hidden note.', false, null, null, 'ccna-q9');
		$translations->setAnswerTranslation((int)$open['answers'][0]['id'], 'ar', 'إس إس إتش');
		$translations->setAnswerTranslation((int)$open['answers'][0]['id'], 'uk', 'SSH (uk)');
		$translations->setQuestionTranslation((int)$open['id'], 'fr', 'Nommez le protocole du port 22.');
		$ranking = $questions->create($source->getId(), self::USER, 'Order the PDCA steps', null, 'medium', [], 'pbq', 'ranking',
			(string)json_encode(['items' => [['id' => 'a', 'label' => 'Plan', 'correct_position' => 1], ['id' => 'b', 'label' => 'Do', 'correct_position' => 2]]]),
			null, false, null, null, null, '1.0', null, null, 'A team meeting.', ['key_takeaway' => 'Plan first.']);
		$translations->setQuestionTranslation((int)$ranking['id'], 'de', 'Ordne die PDCA-Schritte', null, 'Eine Teambesprechung.', ['key_takeaway' => 'Erst planen.']);
		$questions->create($source->getId(), self::USER, 'Match the ports', null, 'hard', [], 'pbq', 'dropdown',
			(string)json_encode(['questions' => [['id' => 'q1', 'prompt' => 'HTTPS', 'options' => ['22', '443'], 'correct' => '443']]]));
		return $source;
	}

	/**
	 * The rows stored for the copy equal the rows stored for the source in every column of
	 * PoolColumns::COMPARED, read back through the mappers — not through the export, which would
	 * miss a field it never writes on both sides. Questions are matched by text (unique in the
	 * fixture), answers by stored order, translations by language.
	 */
	private function assertStoredLikeSource(int $sourceId, int $copyId, bool $comparePool = true): void {
		$source = $this->stored($sourceId);
		$copy = $this->stored($copyId);

		foreach ($source['byClass'] as $class => $entities) {
			$this->assertSame(['missing' => [], 'unknown' => []], PoolColumns::missingFromBothLists($class), $class . ': a property is neither compared nor excluded with a reason');
			$this->assertSame([], PoolColumns::unexercised($class, $entities), $class . ': compared but never stored for the source — the fixture or the code that stores it drops the field');
		}

		if ($comparePool) {
			$this->assertSameColumns($source['pool'], $copy['pool']);
		}
		$this->assertSame(array_keys($source['questions']), array_keys($copy['questions']), 'the same questions');
		foreach ($source['questions'] as $text => $q) {
			$c = $copy['questions'][$text];
			$this->assertSameColumns($q['question'], $c['question']);
			$this->assertCount(count($q['answers']), $c['answers'], (string)$text);
			foreach ($q['answers'] as $i => $answer) {
				$this->assertSameColumns($answer['answer'], $c['answers'][$i]['answer']);
				$this->assertSame(array_keys($answer['translations']), array_keys($c['answers'][$i]['translations']), 'answer translation languages of ' . $answer['answer']->getText());
				foreach ($answer['translations'] as $lang => $t) {
					$this->assertSameColumns($t, $c['answers'][$i]['translations'][$lang]);
				}
			}
			$this->assertSame(array_keys($q['translations']), array_keys($c['translations']), 'translation languages of ' . $text);
			foreach ($q['translations'] as $lang => $t) {
				$this->assertSameColumns($t, $c['translations'][$lang]);
			}
		}
	}

	private function assertSameColumns(object $expected, object $actual): void {
		$class = get_class($expected);
		foreach (PoolColumns::COMPARED[$class] as $property) {
			$getter = 'get' . ucfirst($property);
			$this->assertSame($expected->$getter(), $actual->$getter(), sprintf('%s::%s', $class, $property));
		}
	}

	/**
	 * A pool as stored: the pool row, its questions by text with their answers (stored order) and
	 * the translations of both by language, plus every entity per class.
	 *
	 * @return array{pool: Pool, questions: array<string, array{question: Question, answers: list<array{answer: Answer, translations: array<string, AnswerTranslation>}>, translations: array<string, QuestionTranslation>}>, byClass: array<class-string, list<object>>}
	 */
	private function stored(int $poolId): array {
		$pool = Server::get(PoolMapper::class)->findById($poolId);
		$questions = Server::get(QuestionMapper::class)->findByPoolId($poolId);
		$questionIds = array_map(static fn (Question $q): int => (int)$q->getId(), $questions);
		$answers = Server::get(AnswerMapper::class)->findByQuestions($questionIds);
		$answerIds = [];
		foreach ($answers as $list) {
			foreach ($list as $a) {
				$answerIds[] = (int)$a->getId();
			}
		}
		$qt = Server::get(QuestionTranslationMapper::class)->findByQuestions($questionIds);
		$at = Server::get(AnswerTranslationMapper::class)->findByAnswers($answerIds);

		$out = [];
		$allAnswers = [];
		foreach ($questions as $q) {
			$entry = ['question' => $q, 'answers' => [], 'translations' => []];
			foreach ($answers[(int)$q->getId()] ?? [] as $a) {
				$allAnswers[] = $a;
				$translations = [];
				foreach ($at as $t) {
					if ((int)$t->getAnswerId() === (int)$a->getId()) {
						$translations[(string)$t->getLang()] = $t;
					}
				}
				ksort($translations);
				$entry['answers'][] = ['answer' => $a, 'translations' => $translations];
			}
			foreach ($qt as $t) {
				if ((int)$t->getQuestionId() === (int)$q->getId()) {
					$entry['translations'][(string)$t->getLang()] = $t;
				}
			}
			ksort($entry['translations']);
			$out[(string)$q->getText()] = $entry;
		}
		ksort($out);
		return ['pool' => $pool, 'questions' => $out, 'byClass' => [
			Pool::class => [$pool],
			Question::class => $questions,
			Answer::class => $allAnswers,
			QuestionTranslation::class => $qt,
			AnswerTranslation::class => $at,
		]];
	}

	/** @return array<string, string> */
	private function typesByText(int $poolId): array {
		$types = [];
		foreach (Server::get(QuestionMapper::class)->findByPoolId($poolId) as $question) {
			$types[(string)$question->getText()] = (string)$question->getQuestionType();
		}
		ksort($types);
		return $types;
	}
}
