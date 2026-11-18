<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Answer;
use OCA\Learning\Db\AnswerMapper;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\Question;
use OCA\Learning\Db\QuestionCues;
use OCA\Learning\Db\QuestionCuesMapper;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Db\QuestionTrack;
use OCA\Learning\Db\QuestionTrackMapper;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\QuestionMediaBundle;
use OCA\Learning\Service\QuestionMediaService;
use OCA\Learning\Service\QuestionMediaStorage;
use OCA\Learning\Service\QuestionMediaUnavailableException;
use OCA\Learning\Service\QuestionService;
use OCP\Files\GenericFileException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #66 review follow-ups of the media import and delivery, without a Nextcloud: a rerun
 * heals a track whose row survived but whose file is gone; a storage or database error is
 * reported per entry and the run goes on; a read error of the storage is a defined "unavailable",
 * not an uncaught exception. Import and access on a real instance: tests/Integration/QuestionMediaTest.
 */
#[AllowMockObjectsWithoutExpectations]
final class QuestionMediaServiceTest extends TestCase {
    private const FIXTURE = __DIR__ . '/../../fixtures/question-media/bundle';
    private const FIXTURE_V2 = __DIR__ . '/../../fixtures/question-media/bundle-v2';
    private const POOL = 5;
    private const QUESTION = 11;

    private QuestionMediaStorage&MockObject $storage;
    private QuestionTrackMapper&MockObject $tracks;
    private QuestionCuesMapper&MockObject $cues;
    private QuestionMapper&MockObject $questions;
    private QuestionService&MockObject $questionService;
    private string $tmp = '';
    /** @var list<Answer> what AnswerMapper::findByQuestion returns */
    private array $answers = [];

    protected function setUp(): void {
        $this->storage = $this->createMock(QuestionMediaStorage::class);
        $this->tracks = $this->createMock(QuestionTrackMapper::class);
        $this->cues = $this->createMock(QuestionCuesMapper::class);
        $this->questions = $this->createMock(QuestionMapper::class);
        $this->questionService = $this->createMock(QuestionService::class);

        $question = new Question();
        $question->setId(self::QUESTION);
        $question->setPoolId(self::POOL);
        $question->setExamKey('demo-q1');
        $question->setLang('en');
        $this->questions->method('findByPoolId')->willReturn([$question]);
        $this->questions->method('findById')->willReturn($question);
    }

    protected function tearDown(): void {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            exec('rm -r -- ' . escapeshellarg($this->tmp));
        }
    }

    public function testARerunWritesATrackAgainWhoseRowIsUnchangedButWhoseFileIsGone(): void {
        $bundle = $this->bundle(['intro-en', 'dialog-en']);
        $this->tracks->method('findByQuestion')->willReturn([
            $this->storedTrack(1, 'intro', 'en', $bundle),
            $this->storedTrack(2, 'dialog', 'en', $bundle),
        ]);
        $introName = QuestionMediaStorage::fileName(self::QUESTION, 'intro', 'en');
        // The intro's file is still there; the dialog's was lost (database restored without app data).
        $this->storage->method('holds')->willReturnCallback(fn (int $pool, string $name, int $size) => $name === $introName);
        $written = [];
        $this->storage->method('put')->willReturnCallback(function (int $pool, string $name, string $bytes) use (&$written) {
            $written[] = $name;
        });
        $this->tracks->expects($this->once())->method('update');
        $this->tracks->expects($this->never())->method('insert');

        $dry = $this->service()->importBundle(self::POOL, QuestionMediaBundle::open($bundle), true);
        $this->assertSame(1, $dry['tracks_written'], 'the dry run already says the lost file would be written');
        $this->assertSame([], $written);

        $report = $this->service()->importBundle(self::POOL, QuestionMediaBundle::open($bundle), false);
        $this->assertSame([QuestionMediaStorage::fileName(self::QUESTION, 'dialog', 'en')], $written);
        $this->assertSame(1, $report['tracks_written']);
        $this->assertSame(1, $report['tracks_unchanged']);
        $this->assertSame([], $report['write_failed']);
    }

    public function testAStorageOrDatabaseErrorIsReportedPerEntryAndTheRunGoesOn(): void {
        $bundle = $this->bundle(['intro-en', 'intro-de', 'dialog-en'], true);
        $this->tracks->method('findByQuestion')->willReturn([]);
        $this->cues->method('findByQuestion')->willReturn(null);
        $this->storage->method('holds')->willReturn(false);
        $put = [];
        $this->storage->method('put')->willReturnCallback(function (int $pool, string $name) use (&$put) {
            $put[] = $name;
            if (str_contains($name, 'intro-en')) {
                throw new NotPermittedException('quota exceeded');
            }
        });
        $inserted = 0;
        $this->tracks->method('insert')->willReturnCallback(function (QuestionTrack $t) use (&$inserted) {
            if ($t->getLang() === 'de') {
                throw new \RuntimeException('unique violation');
            }
            $inserted++;
            return $t;
        });
        $this->cues->method('insert')->willThrowException(new \RuntimeException('disk full'));
        $this->questions->method('update')->willThrowException(new \RuntimeException('connection lost'));

        $report = $this->service()->importBundle(self::POOL, QuestionMediaBundle::open($bundle), false);

        $this->assertCount(3, $put, 'every track is tried, a failure does not end the run');
        $this->assertSame(1, $inserted);
        $this->assertSame(1, $report['tracks_written'], 'only what really landed counts as written');
        $this->assertSame(0, $report['cues_written']);
        $this->assertSame(0, $report['feedback_updated']);
        $this->assertCount(4, $report['write_failed']);
        $this->assertStringContainsString('demo-q1: track intro-en (NotPermittedException: quota exceeded)', $report['write_failed'][0]);
        $this->assertStringContainsString('track intro-de', $report['write_failed'][1]);
        $this->assertStringContainsString('cues', $report['write_failed'][2]);
        $this->assertStringContainsString('feedback texts', $report['write_failed'][3]);
    }

    public function testAReadErrorOfTheStorageIsUnavailableNotAnUncaughtException(): void {
        $track = $this->storedTrack(7, 'intro', 'en', null);
        $this->tracks->method('findById')->willReturn($track);
        $this->questionService->method('hasPoolAccess')->willReturn(true);
        $this->questionService->method('mustWithholdSolutionForQuestion')->willReturn(false);
        $file = $this->createMock(ISimpleFile::class);
        $file->method('getContent')->willThrowException(new GenericFileException('short read'));
        $this->storage->method('get')->willReturn($file);

        $this->expectException(QuestionMediaUnavailableException::class);
        $this->service()->openTrack(7, 'owner', null, null);
    }

    /**
     * A /1 bundle lists the intro and dialog tracks of a question and says nothing about the
     * spoken explanations per option: a rerun of it must not remove them (the old loop removed
     * every stored track the list did not name).
     */
    public function testAFormatOneBundleLeavesTheOptionTracksAlone(): void {
        $bundle = $this->bundle(['intro-en']);
        $this->tracks->method('findByQuestion')->willReturn([
            $this->storedTrack(1, 'intro', 'en', $bundle),
            $this->storedTrack(2, 'opt1', 'en', null),
        ]);
        $this->storage->method('holds')->willReturn(true);
        // Recorded, not expects(never()): the import catches every Throwable of a delete.
        $deleted = [];
        $this->tracks->method('delete')->willReturnCallback(function (QuestionTrack $t) use (&$deleted) {
            $deleted[] = $t->getKind() . '-' . $t->getLang();
            return $t;
        });

        $report = $this->service()->importBundle(self::POOL, QuestionMediaBundle::open($bundle), false);
        $this->assertSame([], $deleted, 'a /1 bundle must not remove an option voice');
        $this->assertSame(1, $report['tracks_unchanged']);
        $this->assertSame(0, $report['tracks_removed']);
        $this->assertSame(0, $report['option_tracks_removed']);
    }

    /**
     * Format /2: the voice of option_index n is stored as opt<position of the n-th answer in
     * stored order> — the same mapping as the option feedback texts — with the sidecar as its
     * one-line timeline. Positions here are 10, 20, 30, 40 so index and position differ.
     */
    public function testAFormatTwoBundleStoresTheVoicePerOptionByAnswerPosition(): void {
        $this->answers = $this->answerRows([10 => false, 20 => true, 30 => false, 40 => false]);
        $this->tracks->method('findByQuestion')->willReturn([]);
        $this->storage->method('holds')->willReturn(false);
        $written = [];
        $this->storage->method('put')->willReturnCallback(function (int $pool, string $name, string $bytes) use (&$written) {
            $written[$name] = $bytes;
        });
        $rows = [];
        $this->tracks->method('insert')->willReturnCallback(function (QuestionTrack $t) use (&$rows) {
            $rows[$t->getKind() . '-' . $t->getLang()] = $t;
            return $t;
        });

        $report = $this->service()->importBundle(self::POOL, QuestionMediaBundle::open(self::FIXTURE_V2), false);

        $this->assertSame(QuestionMediaBundle::FORMAT_V2, $report['format']);
        $this->assertSame(5, $report['option_tracks_written'], $this->dump($report));
        $this->assertSame(0, $report['tracks_written']);
        $this->assertSame(['opt10-en', 'opt20-de', 'opt20-en', 'opt30-en', 'opt40-en'], $this->sorted(array_keys($rows)));
        $this->assertSame(
            (string)file_get_contents(self::FIXTURE_V2 . '/demo-q1/opt-1-de.mp3'),
            $written[QuestionMediaStorage::fileName(self::QUESTION, 'opt20', 'de')]
        );
        $this->assertSame('q11-opt20-de.mp3', $rows['opt20-de']->getFileName());
        $this->assertSame(200, $rows['opt20-de']->getDurationMs());
        $timeline = json_decode((string)$rows['opt20-de']->getTimeline(), true);
        $this->assertSame('Option B, Grün, ist richtig: Blau und Gelb ergeben Grün.', $timeline[0]['text']);
        $this->assertSame([], $report['refused_files']);
        $this->assertSame([], $report['missing_files']);
    }

    /** A /2 entry is the truth for the option tracks of its question: an option it no longer voices loses its track. */
    public function testAFormatTwoBundleRemovesAnOptionTrackItNoLongerLists(): void {
        $this->answers = $this->answerRows([0 => false, 1 => true, 2 => false, 3 => false]);
        $this->tracks->method('findByQuestion')->willReturn([
            $this->storedTrack(1, 'intro', 'en', null),
            $this->storedTrack(2, 'opt1', 'fr', null),
        ]);
        $this->storage->method('holds')->willReturn(false);
        $deleted = [];
        $this->tracks->method('delete')->willReturnCallback(function (QuestionTrack $t) use (&$deleted) {
            $deleted[] = $t->getKind() . '-' . $t->getLang();
            return $t;
        });

        $report = $this->service()->importBundle(self::POOL, QuestionMediaBundle::open(self::FIXTURE_V2), false);
        $this->assertSame(['opt1-fr'], $deleted, 'the intro is not the option list\'s to remove');
        $this->assertSame(1, $report['option_tracks_removed']);
    }

    public function testAudioInAFormatOneBundleIsReportedAndIgnored(): void {
        $this->answers = $this->answerRows([0 => false, 1 => true, 2 => false, 3 => false]);
        $this->tmp = sys_get_temp_dir() . '/qmedia-unit-' . bin2hex(random_bytes(4));
        exec('cp -r ' . escapeshellarg(self::FIXTURE_V2) . ' ' . escapeshellarg($this->tmp));
        $manifest = json_decode((string)file_get_contents($this->tmp . '/manifest.json'), true);
        $manifest['format'] = QuestionMediaBundle::FORMAT;
        file_put_contents($this->tmp . '/manifest.json', json_encode($manifest));
        $this->tracks->method('findByQuestion')->willReturn([]);
        $this->storage->expects($this->never())->method('put');

        $report = $this->service()->importBundle(self::POOL, QuestionMediaBundle::open($this->tmp), false);
        $this->assertSame(0, $report['option_tracks_written']);
        $this->assertStringContainsString('"audio" per option needs format ' . QuestionMediaBundle::FORMAT_V2, implode("\n", $report['feedback_warnings']));
    }

    public function testOptionAudioOutsideTheQuestionFolderIsRefused(): void {
        $this->answers = $this->answerRows([0 => false, 1 => true, 2 => false, 3 => false]);
        $this->tmp = sys_get_temp_dir() . '/qmedia-unit-' . bin2hex(random_bytes(4));
        exec('cp -r ' . escapeshellarg(self::FIXTURE_V2) . ' ' . escapeshellarg($this->tmp));
        $manifest = json_decode((string)file_get_contents($this->tmp . '/manifest.json'), true);
        $manifest['questions'][0]['feedback_per_option'][0]['audio'] = ['en' => '../demo-q2/opt-0-en.mp3'];
        file_put_contents($this->tmp . '/manifest.json', json_encode($manifest));
        $this->tracks->method('findByQuestion')->willReturn([]);
        $this->storage->method('holds')->willReturn(false);

        $report = $this->service()->importBundle(self::POOL, QuestionMediaBundle::open($this->tmp), true);
        $this->assertSame(['demo-q1: ../demo-q2/opt-0-en.mp3 (invalid_path)'], $report['refused_files']);
        $this->assertSame(4, $report['option_tracks_written'], 'the other options are imported all the same');
    }

    /**
     * Delivery: before answering a learner learns nothing about the option voices (here only the
     * right answer has one — its presence alone would give it away); the answer response lists
     * them per answer with a grant; the audio endpoint refuses them without it. A question with
     * option voices only (no dialog, no cues) still gets them in the answer response.
     */
    public function testTheOptionVoicesComeOnlyWithTheAnswer(): void {
        $this->answers = $this->answerRows([0 => true, 1 => false, 2 => false]);
        $voice = $this->storedTrack(9, 'opt0', 'en', null);
        $intro = $this->storedTrack(8, 'intro', 'en', null);
        $this->tracks->method('findByQuestion')->willReturn([$intro, $voice]);
        $this->tracks->method('findById')->willReturn($voice);
        $this->cues->method('findByQuestion')->willReturn(null);
        $this->questionService->method('hasPoolAccess')->willReturn(true);
        $this->questionService->method('mustWithholdSolutionForQuestion')->willReturnCallback(fn (int $q, string $user) => $user !== 'owner');

        $before = $this->service()->metadata(self::QUESTION, 'learner');
        $this->assertSame(['intro'], array_column($before['tracks'], 'kind'));
        $this->assertArrayNotHasKey('option_tracks', $before);
        $this->assertStringNotContainsString('opt', (string)json_encode($before));

        $editor = $this->service()->metadata(self::QUESTION, 'owner');
        $this->assertSame(['intro'], array_column($editor['tracks'], 'kind'), 'option voices are not mixed into the player\'s tracks');
        $this->assertSame([['answer_id' => 100, 'position' => 0]], array_map(fn ($o) => ['answer_id' => $o['answer_id'], 'position' => $o['position']], $editor['option_tracks']));

        $after = $this->service()->revealFor(self::QUESTION, 'learner');
        $this->assertSame([], $after['question_media']['tracks']);
        $option = $after['question_media']['option_tracks'][0];
        $this->assertSame(100, $option['answer_id']);
        $this->assertSame('opt0', $option['tracks'][0]['kind']);
        $this->assertStringContainsString('grant=', $option['tracks'][0]['url']);

        $this->expectException(ForbiddenException::class);
        $this->service()->openTrack(9, 'learner', null, null);
    }

    /**
     * deleteForQuestion() removes the files questionOf() attributes to the question: an option
     * voice's file must be one of them (with the old pattern it stayed until the daily sweep).
     */
    public function testAnOptionVoiceFileBelongsToItsQuestion(): void {
        $this->assertSame(11, QuestionMediaStorage::questionOf('q11-opt3-en.mp3'));
        $this->assertSame(11, QuestionMediaStorage::questionOf(QuestionMediaStorage::fileName(11, QuestionMediaStorage::optionKind(12), 'pt-br')));
        $this->assertSame(12, QuestionMediaStorage::optionPosition('opt12'));
        $this->assertNull(QuestionMediaStorage::optionPosition('dialog'));
        $this->assertNull(QuestionMediaStorage::questionOf('q11-opt-en.mp3'));
        $this->assertNull(QuestionMediaStorage::questionOf('q11-option3-en.mp3'));
    }

    /** An option voice whose answer is gone (answers edited after the import) is not offered. */
    public function testAnOptionVoiceWithoutItsAnswerIsLeftOut(): void {
        $this->answers = $this->answerRows([0 => true, 1 => false]);
        $this->tracks->method('findByQuestion')->willReturn([$this->storedTrack(9, 'opt3', 'en', null)]);
        $this->cues->method('findByQuestion')->willReturn(null);
        $this->assertSame([], $this->service()->revealFor(self::QUESTION, 'learner')['question_media']['option_tracks']);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Answers with the given stored positions (position => is_correct), ids 100 + their order.
     *
     * @param array<int, bool> $positions
     * @return list<Answer>
     */
    private function answerRows(array $positions): array {
        $rows = [];
        $n = 0;
        foreach ($positions as $position => $correct) {
            $a = new Answer();
            $a->setId(100 + $n++);
            $a->setQuestionId(self::QUESTION);
            $a->setPosition($position);
            $a->setIsCorrect($correct);
            $a->setText('option ' . $position);
            $rows[] = $a;
        }
        return $rows;
    }

    /** A database in which no exam runs (ExamLock reads learning_sessions and finds nothing). */
    private function noExamDb(): IDBConnection {
        $result = $this->createMock(IResult::class);
        $result->method('fetch')->willReturn(false);
        $qb = $this->createMock(IQueryBuilder::class);
        foreach (['select', 'from', 'where', 'andWhere'] as $method) {
            $qb->method($method)->willReturnSelf();
        }
        $qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
        $qb->method('executeQuery')->willReturn($result);
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);
        return $db;
    }

    private function crypto(): ICrypto {
        $crypto = $this->createMock(ICrypto::class);
        $crypto->method('calculateHMAC')->willReturnCallback(fn (string $message) => hash_hmac('sha256', $message, 'unit', true));
        return $crypto;
    }

    /** @param list<string> $list */
    private function sorted(array $list): array {
        sort($list);
        return $list;
    }

    /** @param array<string, mixed> $report */
    private function dump(array $report): string {
        return (string)json_encode($report, JSON_PRETTY_PRINT);
    }

    private function service(): QuestionMediaService {
        $pools = $this->createMock(PoolMapper::class);
        $answers = $this->createMock(AnswerMapper::class);
        $answers->method('findByQuestion')->willReturnCallback(fn () => $this->answers);
        $url = $this->createMock(IURLGenerator::class);
        $url->method('linkToRoute')->willReturn('/track');
        return new QuestionMediaService(
            $this->storage, $this->tracks, $this->cues, $this->questions, $answers, $pools,
            $this->questionService, $this->noExamDb(), $this->crypto(),
            $url, $this->createMock(LoggerInterface::class),
        );
    }

    /**
     * A bundle with demo-q1 and the given tracks of the fixture (copied, so a test can never touch it).
     *
     * @param list<string> $slots e.g. ['intro-en', 'dialog-de']
     */
    private function bundle(array $slots, bool $withCuesAndHint = false): string {
        $this->tmp = sys_get_temp_dir() . '/qmedia-unit-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/demo-q1', 0700, true);
        $tracks = [];
        foreach ($slots as $slot) {
            [$kind, $lang] = explode('-', $slot);
            copy(self::FIXTURE . "/demo-q1/$slot.mp3", $this->tmp . "/demo-q1/$slot.mp3");
            copy(self::FIXTURE . "/demo-q1/$slot.json", $this->tmp . "/demo-q1/$slot.json");
            $tracks[] = ['kind' => $kind, 'lang' => $lang, 'file' => "demo-q1/$slot.mp3", 'timeline' => "demo-q1/$slot.json"];
        }
        $entry = ['exam_key' => 'demo-q1', 'tracks' => $tracks];
        if ($withCuesAndHint) {
            copy(self::FIXTURE . '/demo-q1/cues.json', $this->tmp . '/demo-q1/cues.json');
            $entry['cues'] = 'demo-q1/cues.json';
            $entry['hint'] = ['en' => 'Think of the colour of grass.'];
        }
        file_put_contents($this->tmp . '/manifest.json', json_encode(['format' => QuestionMediaBundle::FORMAT, 'questions' => [$entry]]));
        return $this->tmp;
    }

    /** A stored row exactly as the import would have written it from the bundle (or a plain one). */
    private function storedTrack(int $id, string $kind, string $lang, ?string $bundle): QuestionTrack {
        $track = new QuestionTrack();
        $track->setId($id);
        $track->setQuestionId(self::QUESTION);
        $track->setPoolId(self::POOL);
        $track->setKind($kind);
        $track->setLang($lang);
        $track->setFileName(QuestionMediaStorage::fileName(self::QUESTION, $kind, $lang));
        if ($bundle !== null) {
            $open = QuestionMediaBundle::open($bundle);
            $bytes = $open->readMp3("demo-q1/$kind-$lang.mp3");
            $lines = $open->readTimeline("demo-q1/$kind-$lang.json");
            $track->setSize(strlen($bytes));
            $track->setSha256(hash('sha256', $bytes));
            $track->setTimeline((string)json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        } else {
            $track->setSize(10);
        }
        return $track;
    }
}
