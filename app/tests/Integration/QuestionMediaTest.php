<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Command\ImportQuestionMediaCommand;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\LeitnerService;
use OCA\Learning\Service\NotFoundException;
use OCA\Learning\Service\PoolBulkImportService;
use OCA\Learning\Service\PoolService;
use OCA\Learning\Service\QuestionMediaBundle;
use OCA\Learning\Service\QuestionMediaService;
use OCA\Learning\Service\QuestionMediaStorage;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\TrainingService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\L10N\IFactory;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * GitHub #66 part B on a real Nextcloud: occ learning:import-question-media with the generic
 * fixture (tests/fixtures/question-media) into a pool imported from its pool.json, the files in the
 * app's real app data, who gets which part of the media (IDOR, exam lock, the dialog only with
 * the grant of an answer response of training and Leitner), and the clean-up on delete.
 * Runs against pgsql and mysql (the tables reference learning_questions with ON DELETE CASCADE).
 */
final class QuestionMediaTest extends TestCase {
	private const OWNER = 'qmedia-owner';
	private const STUDENT = 'qmedia-student';
	private const STRANGER = 'qmedia-stranger';
	private const FIXTURE = __DIR__ . '/../fixtures/question-media';

	private IDBConnection $db;
	private QuestionMediaService $media;
	private int $poolId = 0;
	private int $courseId = 0;
	/** @var array<string, int> exam_key → question id */
	private array $questions = [];
	private string $tmp = '';

	protected function setUp(): void {
		$this->db = Server::get(IDBConnection::class);
		$this->media = Server::get(QuestionMediaService::class);
		$this->cleanUp();

		$doc = json_decode((string)file_get_contents(self::FIXTURE . '/pool.json'), true);
		$results = Server::get(PoolBulkImportService::class)->import(['pools' => [$doc]], self::OWNER, false, Server::get(IFactory::class)->get('learning', 'en'));
		$this->assertSame([], $results[0]['errors'], 'the fixture pool must import');
		$this->poolId = (int)$results[0]['id'];
		foreach (Server::get(QuestionMapper::class)->findByPoolId($this->poolId) as $q) {
			$this->questions[(string)$q->getExamKey()] = (int)$q->getId();
		}
		$this->assertEqualsCanonicalizing(['demo-q1', 'demo-q2'], array_keys($this->questions));

		$this->courseId = $this->insert('learning_courses', [
			'title' => 'qmedia course', 'instructor_id' => self::OWNER, 'created_at' => time(), 'updated_at' => time(),
		]);
		$this->insert('learning_course_pools', ['course_id' => $this->courseId, 'pool_id' => $this->poolId]);
		$this->insert('learning_course_members', ['course_id' => $this->courseId, 'user_id' => self::STUDENT, 'role' => 'student', 'enrolled_at' => time()]);
	}

	protected function tearDown(): void {
		$this->cleanUp();
		if ($this->tmp !== '' && is_dir($this->tmp)) {
			exec('rm -r -- ' . escapeshellarg($this->tmp));
		}
	}

	public function testDryRunWritesNothingAndTheRealRunIsIdempotent(): void {
		[$code, $out] = $this->runCommand(true);
		$this->assertSame(0, $code, $out);
		$this->assertStringContainsString('matched:            2', $out);
		$this->assertStringContainsString('tracks would be written: 5', $out);
		$this->assertSame(0, $this->rows('learning_qmedia_tracks'));
		$this->assertSame(0, $this->rows('learning_qmedia_cues'));
		$this->assertNull($this->poolFolderFiles(), 'a dry run creates no folder');
		$this->assertNull(Server::get(QuestionMapper::class)->findById($this->questions['demo-q1'])->getHint());

		[$code, $out] = $this->runCommand(false);
		$this->assertSame(0, $code, $out);
		$this->assertStringContainsString('tracks written: 5', $out);
		$this->assertStringContainsString('cues written: 1', $out);
		$this->assertStringContainsString('feedback texts written: 2', $out);
		$this->assertSame(5, $this->rows('learning_qmedia_tracks'));
		$this->assertSame(1, $this->rows('learning_qmedia_cues'));
		$q1 = $this->questions['demo-q1'];
		$this->assertSame([
			"q{$q1}-dialog-de.mp3", "q{$q1}-dialog-en.mp3", "q{$q1}-intro-de.mp3", "q{$q1}-intro-en.mp3",
			'q' . $this->questions['demo-q2'] . '-intro-en.mp3',
		], $this->poolFolderFiles());

		// Text fields in the question's language (en); option feedback keyed by position, the
		// three-option question with two identical texts included.
		$question = Server::get(QuestionMapper::class)->findById($q1);
		$this->assertSame('Think of the colour of grass.', $question->getHint());
		$this->assertSame('Blue plus yellow is green.', $question->getKeyTakeaway());
		$feedback = json_decode((string)$question->getOptionFeedback(), true);
		$this->assertSame([0, 1, 2, 3], array_column($feedback, 'position'));
		$q2 = Server::get(QuestionMapper::class)->findById($this->questions['demo-q2']);
		$this->assertSame('The same wrong number twice.', json_decode((string)$q2->getOptionFeedback(), true)[2]['why']);

		[$code, $out] = $this->runCommand(false);
		$this->assertSame(0, $code, $out);
		$this->assertStringContainsString('tracks written: 0 (0 bytes), unchanged: 5, removed: 0', $out);
		$this->assertStringContainsString('cues written: 0, unchanged: 1', $out);
		$this->assertStringContainsString('feedback texts written: 0, unchanged: 2', $out);
		$this->assertSame(5, $this->rows('learning_qmedia_tracks'));
	}

	public function testARerunBringsBackAFileTheAppDataLost(): void {
		$this->runCommand(false);
		$q1 = $this->questions['demo-q1'];
		$lost = "q{$q1}-dialog-en.mp3";
		// The database survived, the app data did not (restore or move without appdata_<id>).
		$path = 'appdata_' . Server::get(IConfig::class)->getSystemValueString('instanceid') . '/learning/' . QuestionMediaStorage::FOLDER_PREFIX . $this->poolId . '/' . $lost;
		Server::get(IRootFolder::class)->get($path)->delete();
		$this->assertNotContains($lost, $this->poolFolderFiles());

		[$code, $out] = $this->runCommand(false);
		$this->assertSame(0, $code, $out);
		$this->assertStringContainsString('tracks written: 1', $out);
		$this->assertStringContainsString('unchanged: 4', $out);
		$this->assertContains($lost, $this->poolFolderFiles());
		$opened = $this->media->openTrack($this->trackId($q1, 'dialog', 'en'), self::OWNER, null, null);
		$this->assertSame(file_get_contents(self::FIXTURE . '/bundle/demo-q1/dialog-en.mp3'), $opened['bytes']);
	}

	public function testUnknownKeysMissingFilesAndRemovedTracksAreReported(): void {
		$this->runCommand(false);
		$bundle = $this->copyFixture();
		$manifest = json_decode((string)file_get_contents($bundle . '/manifest.json'), true);
		$manifest['questions'][1]['tracks'] = [];                                  // demo-q2: intro dropped
		$manifest['questions'][0]['tracks'][3]['file'] = 'demo-q1/gone.mp3';      // dialog-de missing
		$manifest['questions'][] = ['exam_key' => 'not-in-pool', 'tracks' => []];
		file_put_contents($bundle . '/manifest.json', json_encode($manifest));

		[$code, $out] = $this->runCommand(false, $bundle);
		$this->assertSame(1, $code, 'unknown keys and missing files must not exit 0');
		$this->assertStringContainsString('unknown exam_key:   1', $out);
		$this->assertStringContainsString('missing files:      1', $out);
		$this->assertStringContainsString('demo-q1: demo-q1/gone.mp3', $out);
		$this->assertStringContainsString('removed: 1', $out);
		// The missing dialog keeps its stored copy; demo-q2's intro is gone with its file.
		$this->assertSame(4, $this->rows('learning_qmedia_tracks'));
		$this->assertNotContains('q' . $this->questions['demo-q2'] . '-intro-en.mp3', $this->poolFolderFiles());
	}

	public function testWhoGetsWhichMedia(): void {
		$this->runCommand(false);
		$q1 = $this->questions['demo-q1'];

		// The owner (editor) gets every track and the cues.
		$owner = $this->media->metadata($q1, self::OWNER);
		$this->assertCount(4, $owner['tracks']);
		$this->assertSame('Blau plus Gelb ist Grün.', $owner['cues']['de']['merksatz']);

		// A course member: the intros only, no cues, no dialog URL to guess.
		$student = $this->media->metadata($q1, self::STUDENT);
		$this->assertSame(['intro', 'intro'], array_column($student['tracks'], 'kind'));
		$this->assertArrayNotHasKey('cues', $student);
		$intro = $student['tracks'][0];
		$this->assertSame(0.6, $intro['timeline'][0]['end']);
		$opened = $this->media->openTrack($intro['id'], self::STUDENT, null, null);
		$this->assertSame(file_get_contents(self::FIXTURE . '/bundle/demo-q1/intro-' . $intro['lang'] . '.mp3'), $opened['bytes']);

		$dialogId = $this->trackId($q1, 'dialog', 'en');
		$this->assertForbidden(fn () => $this->media->openTrack($dialogId, self::STUDENT, null, null), 'a dialog without grant');
		$foreign = $this->media->revealFor($q1, self::OWNER)['question_media']['tracks'][0]['url'];
		[$expires, $grant] = $this->grantOf($foreign);
		$this->assertForbidden(fn () => $this->media->openTrack($dialogId, self::STUDENT, $expires, $grant), 'another user\'s grant');
		$this->assertForbidden(fn () => $this->media->openTrack($dialogId, self::STUDENT, (string)(time() - 1), $this->media->grant(self::STUDENT, $q1, time() - 1)), 'an expired grant');
		$this->assertForbidden(fn () => $this->media->openTrack($dialogId, self::STUDENT, (string)(time() + 60), $this->media->grant(self::STUDENT, $this->questions['demo-q2'], time() + 60)), 'a grant for another question');

		// A user without any access: the question does not exist for them (IDOR).
		$this->assertNotFound(fn () => $this->media->metadata($q1, self::STRANGER));
		$this->assertNotFound(fn () => $this->media->openTrack($this->trackId($q1, 'intro', 'en'), self::STRANGER, null, null));
		[$expires, $grant] = $this->grantOf($this->media->revealFor($q1, self::STRANGER)['question_media']['tracks'][0]['url']);
		$this->assertNotFound(fn () => $this->media->openTrack($dialogId, self::STRANGER, $expires, $grant), 'a self-made grant does not replace access');
		$this->assertNotFound(fn () => $this->media->openTrack(999999999, self::OWNER, null, null));

		// A counting exam running for the student on the pool: nothing at all, the owner unaffected.
		$this->insert('learning_sessions', [
			'pool_id' => $this->poolId, 'user_id' => self::STUDENT, 'started_at' => time(), 'total_questions' => 2,
			'correct_answers' => 0, 'mode' => 'exam',
		]);
		$this->assertForbidden(fn () => $this->media->metadata($q1, self::STUDENT), 'metadata during an exam');
		$this->assertForbidden(fn () => $this->media->openTrack($intro['id'], self::STUDENT, null, null), 'an intro during an exam');
		[$expires, $grant] = $this->grantOf($this->media->revealFor($q1, self::STUDENT)['question_media']['tracks'][0]['url']);
		$this->assertForbidden(fn () => $this->media->openTrack($dialogId, self::STUDENT, $expires, $grant), 'a grant from before the exam');
		$this->assertCount(4, $this->media->metadata($q1, self::OWNER)['tracks']);
	}

	public function testTheAnswerResponsesOfTrainingAndLeitnerCarryTheDialogWithAGrant(): void {
		$this->runCommand(false);
		$q1 = $this->questions['demo-q1'];
		$right = $this->answerId($q1, 'Green');

		$session = Server::get(TrainingService::class)->startSession($this->poolId, self::STUDENT, null, 'training', null, 'en', $this->courseId);
		$response = Server::get(TrainingService::class)->submitAnswer((int)$session['session_id'], $q1, $right, self::STUDENT);
		$this->assertArrayHasKey('question_media', $response, 'training reveals the dialog');
		$this->assertSame(['dialog', 'dialog'], array_column($response['question_media']['tracks'], 'kind'));
		$this->assertSame('Blue plus yellow is green.', $response['question_media']['cues']['en']['merksatz']);
		$track = $response['question_media']['tracks'][0];
		[$expires, $grant] = $this->grantOf($track['url']);
		$opened = $this->media->openTrack($track['id'], self::STUDENT, $expires, $grant);
		$this->assertSame(file_get_contents(self::FIXTURE . '/bundle/demo-q1/dialog-' . $track['lang'] . '.mp3'), $opened['bytes']);

		$leitner = Server::get(LeitnerService::class);
		$leitner->initializePool($this->poolId, self::STUDENT, $this->courseId);
		$itemId = (int)$this->scalar('learning_leitner_items', 'id', ['user_id' => self::STUDENT, 'question_id' => $q1]);
		$preview = $leitner->answerQuestion($itemId, $right, self::STUDENT, null, null, null, null, true, 'en');
		$this->assertArrayHasKey('question_media', $preview, 'the Leitner answer (FSRS preview) reveals the dialog');

		// demo-q2 has an intro only: no question_media in its answer response.
		$q2 = $this->questions['demo-q2'];
		$response = Server::get(TrainingService::class)->submitAnswer((int)$session['session_id'], $q2, $this->answerId($q2, 'Eight'), self::STUDENT);
		$this->assertArrayNotHasKey('question_media', $response);
	}

	public function testDeletingAQuestionOrThePoolRemovesItsMediaAndTheSweepRemovesOrphans(): void {
		$this->runCommand(false);
		$q1 = $this->questions['demo-q1'];
		$q2 = $this->questions['demo-q2'];

		Server::get(QuestionService::class)->delete($q1, self::OWNER);
		$this->assertSame(1, $this->rows('learning_qmedia_tracks'));
		$this->assertSame(0, $this->rows('learning_qmedia_cues'));
		$this->assertSame(["q{$q2}-intro-en.mp3"], $this->poolFolderFiles());

		// A file nothing points at, and the folder of a pool that does not exist.
		$storage = Server::get(QuestionMediaStorage::class);
		$storage->put($this->poolId, 'q' . $q1 . '-intro-en.mp3', 'stale');
		$storage->put(999999998, 'q1-intro-en.mp3', 'stale');
		$removed = $storage->sweepOrphans(0);
		$this->assertSame(1, $removed['files']);
		$this->assertGreaterThanOrEqual(1, $removed['folders']);
		$this->assertSame(["q{$q2}-intro-en.mp3"], $this->poolFolderFiles());

		Server::get(PoolService::class)->delete($this->poolId, self::OWNER);
		$this->assertSame(0, $this->rows('learning_qmedia_tracks'));
		$this->assertNull($this->poolFolderFiles());
		$this->poolId = 0;
	}

	// ------------------------------------------------------------------ helpers

	/** @return array{0: int, 1: string} */
	private function runCommand(bool $dryRun, ?string $bundle = null): array {
		$command = Server::get(ImportQuestionMediaCommand::class);
		$output = new BufferedOutput();
		$args = ['poolId' => (string)$this->poolId, 'bundle-dir' => $bundle ?? self::FIXTURE . '/bundle'];
		if ($dryRun) {
			$args['--dry-run'] = true;
		}
		$code = $command->run(new ArrayInput($args), $output);
		return [$code, $output->fetch()];
	}

	private function copyFixture(): string {
		$this->tmp = sys_get_temp_dir() . '/qmedia-' . bin2hex(random_bytes(4));
		exec('cp -r ' . escapeshellarg(self::FIXTURE . '/bundle') . ' ' . escapeshellarg($this->tmp), $o, $rc);
		$this->assertSame(0, $rc);
		return $this->tmp;
	}

	/**
	 * @return list<string>|null the file names in the pool's media folder, null when it has none.
	 * Read through the root folder, not IAppData: an IAppData instance caches its folder nodes,
	 * and a cached node of a deleted folder still answers (with an empty listing).
	 */
	private function poolFolderFiles(): ?array {
		$path = 'appdata_' . Server::get(IConfig::class)->getSystemValueString('instanceid') . '/learning/' . QuestionMediaStorage::FOLDER_PREFIX . $this->poolId;
		$root = Server::get(IRootFolder::class);
		if (!$root->nodeExists($path)) {
			return null;
		}
		$folder = $root->get($path);
		$this->assertInstanceOf(Folder::class, $folder);
		$names = array_map(static fn ($n) => $n->getName(), $folder->getDirectoryListing());
		sort($names);
		return $names;
	}

	/** @return array{0: string, 1: string} */
	private function grantOf(string $url): array {
		parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
		return [(string)$query['expires'], (string)$query['grant']];
	}

	private function trackId(int $questionId, string $kind, string $lang): int {
		return (int)$this->scalar('learning_qmedia_tracks', 'id', ['question_id' => $questionId, 'kind' => $kind, 'lang' => $lang]);
	}

	private function answerId(int $questionId, string $text): int {
		return (int)$this->scalar('learning_answers', 'id', ['question_id' => $questionId, 'text' => $text]);
	}

	private function assertForbidden(callable $fn, string $what): void {
		try {
			$fn();
			$this->fail('expected 403 for ' . $what);
		} catch (ForbiddenException $e) {
			$this->addToAssertionCount(1);
		}
	}

	private function assertNotFound(callable $fn, string $what = 'no access'): void {
		try {
			$fn();
			$this->fail('expected 404 for ' . $what);
		} catch (NotFoundException $e) {
			$this->addToAssertionCount(1);
		}
	}

	/** @param array<string, mixed> $where */
	private function scalar(string $table, string $column, array $where): mixed {
		$qb = $this->db->getQueryBuilder();
		$qb->select($column)->from($table)->setMaxResults(1);
		foreach ($where as $key => $value) {
			$qb->andWhere($qb->expr()->eq($key, $qb->createNamedParameter($value, is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR)));
		}
		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();
		$this->assertNotFalse($value, "no row in $table");
		return $value;
	}

	private function rows(string $table): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($table)
		   ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($this->poolId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$n = (int)$result->fetchOne();
		$result->closeCursor();
		return $n;
	}

	/** @param array<string, mixed> $row */
	private function insert(string $table, array $row): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert($table);
		foreach ($row as $key => $value) {
			$type = is_bool($value) ? IQueryBuilder::PARAM_BOOL : (is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR);
			$qb->setValue($key, $qb->createNamedParameter($value, $type));
		}
		$qb->executeStatement();
		return $qb->getLastInsertId();
	}

	private function cleanUp(): void {
		$storage = Server::get(QuestionMediaStorage::class);
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('learning_pools')->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::OWNER)));
		$result = $qb->executeQuery();
		$pools = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();
		foreach ($pools as $poolId) {
			$storage->deleteForPool($poolId);
			foreach (['learning_course_pools', 'learning_leitner_items', 'learning_sessions'] as $table) {
				$d = $this->db->getQueryBuilder();
				$d->delete($table)->where($d->expr()->eq('pool_id', $d->createNamedParameter($poolId, IQueryBuilder::PARAM_INT)))->executeStatement();
			}
			$d = $this->db->getQueryBuilder();
			$d->delete('learning_pools')->where($d->expr()->eq('id', $d->createNamedParameter($poolId, IQueryBuilder::PARAM_INT)))->executeStatement();
		}
		$d = $this->db->getQueryBuilder();
		$d->delete('learning_courses')->where($d->expr()->eq('instructor_id', $d->createNamedParameter(self::OWNER)))->executeStatement();
		$d = $this->db->getQueryBuilder();
		$d->delete('learning_course_members')->where($d->expr()->eq('user_id', $d->createNamedParameter(self::STUDENT)))->executeStatement();
		$storage->sweepOrphans(0);
	}
}
