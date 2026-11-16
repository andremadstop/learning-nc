<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\AnswerMapper;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\Question;
use OCA\Learning\Db\QuestionCues;
use OCA\Learning\Db\QuestionCuesMapper;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Db\QuestionTrack;
use OCA\Learning\Db\QuestionTrackMapper;
use OCA\Learning\Service\QuestionMediaBundle;
use OCA\Learning\Service\QuestionMediaService;
use OCA\Learning\Service\QuestionMediaStorage;
use OCA\Learning\Service\QuestionMediaUnavailableException;
use OCA\Learning\Service\QuestionService;
use OCP\Files\GenericFileException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFile;
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
    private const POOL = 5;
    private const QUESTION = 11;

    private QuestionMediaStorage&MockObject $storage;
    private QuestionTrackMapper&MockObject $tracks;
    private QuestionCuesMapper&MockObject $cues;
    private QuestionMapper&MockObject $questions;
    private QuestionService&MockObject $questionService;
    private string $tmp = '';

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

    // ------------------------------------------------------------------ helpers

    private function service(): QuestionMediaService {
        $pools = $this->createMock(PoolMapper::class);
        $answers = $this->createMock(AnswerMapper::class);
        $answers->method('findByQuestion')->willReturn([]);
        $url = $this->createMock(IURLGenerator::class);
        $url->method('linkToRoute')->willReturn('/track');
        return new QuestionMediaService(
            $this->storage, $this->tracks, $this->cues, $this->questions, $answers, $pools,
            $this->questionService, $this->createMock(IDBConnection::class), $this->createMock(ICrypto::class),
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
