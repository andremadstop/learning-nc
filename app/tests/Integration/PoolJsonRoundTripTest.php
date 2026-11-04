<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Command\ImportPoolJsonCommand;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Service\DataMobilityService;
use OCA\Learning\Service\PoolService;
use OCA\Learning\Service\QuestionService;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A pool exported with `occ learning:export-pool` and imported again with
 * `occ learning:import-pool-json` keeps every question and its type.
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
