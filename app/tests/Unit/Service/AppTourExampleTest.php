<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\QuestionMediaBundle;
use OCA\Learning\Service\RichFeedbackFormat;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #66: the example course examples/app-tour (the file itself, never a copy) stays
 * importable without a database: pool.json holds both pools, each media bundle opens with the
 * importer's own reader, lists exactly the exam_keys of its pool, reads every file it names, and
 * carries its texts in that pool's language only. Questions from pool.json have no language, so
 * a second language in a bundle would make the importer drop the pool's own language for "en"
 * (the first version did that to the German pool). The real occ import runs in
 * tests/Integration/AppTourExampleTest.php.
 *
 * scripts/gate-container.sh places examples/ at /examples, four levels above this directory,
 * as it is in the repository.
 */
final class AppTourExampleTest extends TestCase {
	private const MAX_BYTES = 4194304; // the repository copy stays small; full audio is a release asset

	private string $dir;

	protected function setUp(): void {
		$this->dir = dirname(__DIR__, 4) . '/examples/app-tour';
		$this->assertFileExists($this->dir . '/pool.json', 'examples/app-tour must be present (scripts/gate-container.sh copies it)');
	}

	public function testPoolJsonHoldsBothPoolsWithMatchingQuestions(): void {
		$pools = $this->pools();
		$this->assertSame(['en', 'de'], array_keys($pools));
		foreach ($pools as $lang => $pool) {
			$types = array_count_values(array_column($pool['questions'], 'question_type'));
			ksort($types);
			$this->assertSame(['multi' => 4, 'open' => 2, 'single' => 12], $types, $lang);
			$keys = array_column($pool['questions'], 'exam_key');
			$expected = array_map(static fn (int $n): string => sprintf('app-tour-%02d-%s', $n, $lang), range(1, 18));
			$this->assertSame($expected, $keys, $lang);
			foreach ($pool['questions'] as $q) {
				$this->assertNotSame([], array_filter(array_column($q['answers'], 'is_correct')), $q['exam_key'] . ' has a correct answer');
			}
		}
	}

	public function testEachBundleCoversItsPoolInItsLanguageOnly(): void {
		foreach ($this->pools() as $lang => $pool) {
			$bundle = QuestionMediaBundle::open($this->dir . '/bundle/' . $lang);
			$byKey = array_column($pool['questions'], null, 'exam_key');
			$entries = $bundle->questions();
			$this->assertSame(array_keys($byKey), array_column($entries, 'exam_key'), $lang . ': manifest = pool');

			$tracks = 0;
			foreach ($entries as $entry) {
				$key = $entry['exam_key'];
				$question = $byKey[$key];
				$isCorrect = array_map(static fn (array $a): bool => (bool)$a['is_correct'], $question['answers']);
				$parsed = RichFeedbackFormat::fromItem(['lang' => null] + $entry, null, $isCorrect);
				$this->assertSame([], $parsed['dropped_languages'], $key . ': one language per entry');
				$this->assertSame(0, $parsed['verdict_mismatches'], $key . ': verdicts match the answer key');
				$this->assertNotNull($parsed['hint'], $key);
				$this->assertNotNull($parsed['background_fact'], $key);
				$this->assertNotNull($parsed['key_takeaway'], $key);
				$this->assertSame($this->text($entry['hint'], $lang), $parsed['hint'], $key . ': hint in ' . $lang);
				if ($question['question_type'] !== 'open') {
					$this->assertCount(count($question['answers']), $parsed['option_feedback'], $key . ': feedback for every option');
				}

				$cues = $bundle->readCues($entry['cues']);
				$this->assertArrayHasKey($lang, $cues, $key);
				foreach ($entry['tracks'] as $track) {
					$this->assertTrue(QuestionMediaBundle::looksLikeMp3($bundle->readMp3($track['file'])), $track['file']);
					$this->assertNotSame([], $bundle->readTimeline($track['timeline']), $track['timeline']);
					$tracks++;
				}
				$langs = array_values(array_unique(array_column($entry['tracks'], 'lang')));
				if ($langs !== []) {
					$this->assertSame($lang, $langs[0], $key . ': the pool language plays first');
				}
			}
			$this->assertSame(10, $tracks, $lang . ': intro + dialog for questions 3, 7, 11 and 13 in both languages');
		}
	}

	public function testTheExampleStaysSmall(): void {
		$bytes = 0;
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			$bytes += $file->getSize();
		}
		$this->assertLessThanOrEqual(self::MAX_BYTES, $bytes);
	}

	/** @return array<string, array<string, mixed>> 'en'|'de' → pool */
	private function pools(): array {
		$doc = json_decode((string)file_get_contents($this->dir . '/pool.json'), true, 64, JSON_THROW_ON_ERROR);
		$this->assertCount(2, $doc['pools']);
		$byLang = [];
		foreach ($doc['pools'] as $pool) {
			$byLang[substr((string)$pool['questions'][0]['exam_key'], -2)] = $pool;
		}
		return $byLang;
	}

	private function text(mixed $value, string $lang): ?string {
		return is_array($value) ? ($value[$lang] ?? null) : (is_string($value) ? $value : null);
	}
}
