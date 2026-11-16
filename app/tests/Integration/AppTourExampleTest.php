<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Command\ImportPoolJsonCommand;
use OCA\Learning\Command\ImportQuestionMediaCommand;
use OCA\Learning\Service\QuestionMediaStorage;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * GitHub #66: the example course examples/app-tour imports on a real Nextcloud exactly as its
 * README says — both pools from pool.json, then each pool's media bundle with the occ command,
 * every step with exit code 0, and each pool gets the hint, feedback and audio of its own language.
 *
 * The first version shipped one bundle for both pools: every run reported the other pool's 18
 * exam_keys as unknown (exit 1), and the German pool got the English texts, because questions
 * from pool.json carry no language and the importer then takes "en".
 *
 * scripts/nc-integration.sh copies examples/ into the container and sets LEARNING_EXAMPLES_DIR.
 * A missing example fails the test: it must never pass by not looking.
 */
final class AppTourExampleTest extends TestCase {
	private const OWNER = 'apptour-owner';

	private IDBConnection $db;
	private string $dir = '';

	protected function setUp(): void {
		$this->db = Server::get(IDBConnection::class);
		$base = getenv('LEARNING_EXAMPLES_DIR');
		$base = is_string($base) && $base !== '' ? $base : dirname(__DIR__, 3) . '/examples';
		$this->dir = rtrim($base, '/') . '/app-tour';
		$this->assertFileExists($this->dir . '/pool.json', 'examples/app-tour must be present (scripts/nc-integration.sh copies it)');
		$this->cleanUp();
	}

	protected function tearDown(): void {
		$this->cleanUp();
	}

	public function testTheReadmeStepsImportBothPoolsInTheirOwnLanguage(): void {
		[$code, $out] = $this->runCommand(ImportPoolJsonCommand::class, ['file' => $this->dir . '/pool.json', '--user' => self::OWNER, '--dry-run' => true]);
		$this->assertSame(0, $code, $out);
		[$code, $out] = $this->runCommand(ImportPoolJsonCommand::class, ['file' => $this->dir . '/pool.json', '--user' => self::OWNER]);
		$this->assertSame(0, $code, $out);

		$pools = $this->pools();
		$this->assertSame(['How to learn with this app', 'So lernst du mit dieser App'], array_values($pools));
		$manifests = [];
		foreach ($pools as $poolId => $name) {
			$lang = str_starts_with($name, 'So ') ? 'de' : 'en';
			$bundle = $this->dir . '/bundle/' . $lang;
			$manifests[$lang] = json_decode((string)file_get_contents($bundle . '/manifest.json'), true);

			[$code, $out] = $this->runCommand(ImportQuestionMediaCommand::class, ['poolId' => (string)$poolId, 'bundle-dir' => $bundle, '--dry-run' => true]);
			$this->assertSame(0, $code, $out);
			[$code, $out] = $this->runCommand(ImportQuestionMediaCommand::class, ['poolId' => (string)$poolId, 'bundle-dir' => $bundle]);
			$this->assertSame(0, $code, $out);
			$this->assertStringContainsString('matched:            18', $out);
			$this->assertStringContainsString('unknown exam_key:   0', $out);
			$this->assertStringNotContainsString('other languages than the question', $out);

			// idempotent: a second run writes nothing
			[$code, $out] = $this->runCommand(ImportQuestionMediaCommand::class, ['poolId' => (string)$poolId, 'bundle-dir' => $bundle]);
			$this->assertSame(0, $code, $out);
			$this->assertStringContainsString('tracks written: 0', $out);
			$this->assertStringContainsString('feedback texts written: 0, unchanged: 18', $out);
		}

		// The German pool holds the German hint, the English pool the English one.
		foreach (['en', 'de'] as $lang) {
			$entry = $this->entry($manifests[$lang], 'app-tour-03-' . $lang);
			$this->assertSame($entry['hint'][$lang], $this->question('app-tour-03-' . $lang)['hint']);
			$this->assertSame($entry['background_fact'][$lang], $this->question('app-tour-03-' . $lang)['background_fact']);
		}
		$this->assertStringStartsWith('Erinnere dich', (string)$this->question('app-tour-03-de')['hint']);

		// Audio: question 3 in the pool's language, question 13 in both, question 1 none.
		$this->assertSame(['dialog-de', 'intro-de'], $this->tracks('app-tour-03-de'));
		$this->assertSame(['dialog-en', 'intro-en'], $this->tracks('app-tour-03-en'));
		$this->assertSame(['dialog-de', 'dialog-en', 'intro-de', 'intro-en'], $this->tracks('app-tour-13-de'));
		$this->assertSame([], $this->tracks('app-tour-01-en'));
	}

	/**
	 * @param class-string $class
	 * @param array<string, mixed> $args
	 * @return array{0: int, 1: string}
	 */
	private function runCommand(string $class, array $args): array {
		$output = new BufferedOutput();
		$code = Server::get($class)->run(new ArrayInput($args), $output);
		return [$code, $output->fetch()];
	}

	/** @return array<int, string> pool id → name, by id */
	private function pools(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'name')->from('learning_pools')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::OWNER)))
			->orderBy('id', 'ASC');
		$result = $qb->executeQuery();
		$pools = [];
		foreach ($result->fetchAll() as $row) {
			$pools[(int)$row['id']] = (string)$row['name'];
		}
		$result->closeCursor();
		return $pools;
	}

	/** @return array<string, mixed> */
	private function question(string $examKey): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('q.id', 'q.hint', 'q.background_fact')->from('learning_questions', 'q')
			->innerJoin('q', 'learning_pools', 'p', 'p.id = q.pool_id')
			->where($qb->expr()->eq('p.user_id', $qb->createNamedParameter(self::OWNER)))
			->andWhere($qb->expr()->eq('q.exam_key', $qb->createNamedParameter($examKey)));
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		$this->assertCount(1, $rows, $examKey);
		return $rows[0];
	}

	/** @return list<string> "kind-lang" of the stored tracks, sorted */
	private function tracks(string $examKey): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('kind', 'lang')->from('learning_qmedia_tracks')
			->where($qb->expr()->eq('question_id', $qb->createNamedParameter((int)$this->question($examKey)['id'], IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$tracks = array_map(static fn (array $r): string => $r['kind'] . '-' . $r['lang'], $result->fetchAll());
		$result->closeCursor();
		sort($tracks);
		return $tracks;
	}

	/**
	 * @param array<string, mixed> $manifest
	 * @return array<string, mixed>
	 */
	private function entry(array $manifest, string $examKey): array {
		foreach ($manifest['questions'] as $entry) {
			if ($entry['exam_key'] === $examKey) {
				return $entry;
			}
		}
		$this->fail('no manifest entry ' . $examKey);
	}

	private function cleanUp(): void {
		$storage = Server::get(QuestionMediaStorage::class);
		foreach (array_keys($this->pools()) as $poolId) {
			$storage->deleteForPool($poolId);
			$d = $this->db->getQueryBuilder();
			$d->delete('learning_pools')->where($d->expr()->eq('id', $d->createNamedParameter($poolId, IQueryBuilder::PARAM_INT)))->executeStatement();
		}
		$storage->sweepOrphans(0);
	}
}
