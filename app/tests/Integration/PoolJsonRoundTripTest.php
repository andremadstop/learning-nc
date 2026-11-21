<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Command\ExportPoolCommand;
use OCA\Learning\Command\ImportPoolJsonCommand;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Service\DataMobilityService;
use OCA\Learning\Service\PoolService;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\TranslationService;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A pool exported with `occ learning:export-pool` and imported again with
 * `occ learning:import-pool-json` keeps every question and its type — and, since 5.18.0 (format
 * learning-pool/2), every field: pool metadata, translations, instructor notes, rich feedback.
 * The field-by-field check against the entities is the unit test
 * tests/Unit/Service/PoolDocumentRoundTripTest.php; this one runs the same round trip on a real
 * database and compares the export of the copy with the export of the source.
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
			'hint' => 'TLS (en)', 'background_fact' => null, 'key_takeaway' => '443 (en).',
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
		$questions->create($source->getId(), self::USER, 'Order the PDCA steps', null, 'medium', [], 'pbq', 'ranking',
			(string)json_encode(['items' => [['id' => 'a', 'label' => 'Plan', 'correct_position' => 1], ['id' => 'b', 'label' => 'Do', 'correct_position' => 2]]]),
			null, false, null, null, null, '1.0', null, null, 'A team meeting.', ['key_takeaway' => 'Plan first.']);
		$questions->create($source->getId(), self::USER, 'Match the ports', null, 'hard', [], 'pbq', 'dropdown',
			(string)json_encode(['questions' => [['id' => 'q1', 'prompt' => 'HTTPS', 'options' => ['22', '443'], 'correct' => '443']]]));

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
	}

	public function testSeveralCorrectAnswersWithoutTypeImportAsMulti(): void {
		$this->assertSame('multi', ImportPoolJsonCommand::resolveQuestionType([], 'single', [
			['text' => 'a', 'is_correct' => true],
			['text' => 'b', 'is_correct' => true],
		]));
		$this->assertSame('single', ImportPoolJsonCommand::resolveQuestionType(['type' => 'true_false'], 'single', []));
		$this->assertSame('open', ImportPoolJsonCommand::resolveQuestionType(['question_type' => 'open', 'type' => 'multi'], 'single', []));
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
