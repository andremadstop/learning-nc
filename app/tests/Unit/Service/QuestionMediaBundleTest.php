<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\QuestionMediaBundle;
use OCA\Learning\Service\QuestionMediaFileException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #66: the bundle reader of occ learning:import-question-media is the only place a manifest
 * path becomes bytes. Path traversal, symlinks (to outside and inside the bundle, as file and as
 * directory), size limits and file types are refused; the generic fixture reads cleanly.
 */
final class QuestionMediaBundleTest extends TestCase {
    private const FIXTURE = __DIR__ . '/../../fixtures/question-media/bundle';

    private string $dir = '';

    protected function setUp(): void {
        $this->dir = sys_get_temp_dir() . '/qmedia-bundle-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/b/q1', 0700, true);
        copy(self::FIXTURE . '/demo-q1/intro-en.mp3', $this->dir . '/b/q1/intro-en.mp3');
        copy(self::FIXTURE . '/demo-q1/intro-en.json', $this->dir . '/b/q1/intro-en.json');
        file_put_contents($this->dir . '/b/manifest.json', json_encode(['format' => QuestionMediaBundle::FORMAT, 'questions' => []]));
        file_put_contents($this->dir . '/secret.mp3', (string)file_get_contents(self::FIXTURE . '/demo-q1/intro-en.mp3'));
    }

    protected function tearDown(): void {
        exec('rm -r -- ' . escapeshellarg($this->dir));
    }

    public function testTheFixtureReads(): void {
        $bundle = QuestionMediaBundle::open(self::FIXTURE);
        $this->assertCount(2, $bundle->questions());
        $this->assertTrue(QuestionMediaBundle::looksLikeMp3($bundle->readMp3('demo-q1/dialog-en.mp3')));
        $timeline = $bundle->readTimeline('demo-q1/dialog-en.json');
        $this->assertSame(['role' => 'BEN', 'text' => 'Mixed together they make green.', 'start' => 0.4, 'end' => 0.8], $timeline[1]);
        $this->assertSame('🎨', $bundle->readCues('demo-q1/cues.json')['icon']);
    }

    /**
     * Format /2 (spoken explanation per answer option): opened next to /1, its option audio
     * resolved inside <exam_key>/, the sidecar {duration, role, text} read as a one-line timeline.
     */
    public function testFormatTwoReadsAndOneStaysOne(): void {
        $v1 = QuestionMediaBundle::open(self::FIXTURE);
        $this->assertSame(QuestionMediaBundle::FORMAT, $v1->format());
        $this->assertFalse($v1->hasOptionAudio());

        $v2 = QuestionMediaBundle::open(self::FIXTURE . '-v2');
        $this->assertSame(QuestionMediaBundle::FORMAT_V2, $v2->format());
        $this->assertTrue($v2->hasOptionAudio());
        $path = QuestionMediaBundle::optionAudioPath('demo-q1', 'opt-1-de.mp3');
        $this->assertSame('demo-q1/opt-1-de.mp3', $path);
        $this->assertTrue(QuestionMediaBundle::looksLikeMp3($v2->readMp3($path)));
        $this->assertTrue($v2->has('demo-q1/opt-1-de.json'));
        $this->assertFalse($v2->has('demo-q1/opt-9-de.json'));
        $this->assertSame(
            [['role' => 'NARRATOR', 'text' => 'Option B, Grün, ist richtig: Blau und Gelb ergeben Grün.', 'start' => 0.0, 'end' => 0.2]],
            $v2->readOptionSidecar('demo-q1/opt-1-de.json')
        );
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function unsafeOptionAudio(): array {
        return [
            'parent' => ['demo-q1', '../demo-q2/opt-0-en.mp3'],
            'absolute' => ['demo-q1', '/etc/passwd.mp3'],
            'not a string' => ['demo-q1', ['opt-0-en.mp3']],
            'key with a slash' => ['demo/q1', 'opt-0-en.mp3'],
            'key upper case' => ['Demo-Q1', 'opt-0-en.mp3'],
            'key is a parent' => ['..', 'opt-0-en.mp3'],
        ];
    }

    #[DataProvider('unsafeOptionAudio')]
    public function testOptionAudioOutsideTheQuestionFolderIsRefused(string $examKey, mixed $file): void {
        $this->assertNull(QuestionMediaBundle::optionAudioPath($examKey, $file));
    }

    /** @return array<string, array{0: string}> */
    public static function badSidecars(): array {
        return [
            'a list' => ['[{"duration": 1, "text": "a"}]'],
            'no text' => ['{"duration": 1}'],
            'negative duration' => ['{"duration": -1, "text": "a"}'],
            'duration a string' => ['{"duration": "1", "text": "a"}'],
        ];
    }

    #[DataProvider('badSidecars')]
    public function testABadSidecarIsRefused(string $json): void {
        file_put_contents($this->dir . '/b/q1/opt-0-en.json', $json);
        $this->assertRefused(fn () => QuestionMediaBundle::open($this->dir . '/b')->readOptionSidecar('q1/opt-0-en.json'), QuestionMediaBundle::E_INVALID_JSON);
    }

    /** @return array<string, array{0: mixed}> */
    public static function unsafePaths(): array {
        return [
            'parent' => ['../secret.mp3'],
            'parent inside' => ['q1/../../secret.mp3'],
            'dot segment' => ['q1/./intro-en.mp3'],
            'absolute' => ['/etc/passwd.mp3'],
            'double slash' => ['q1//intro-en.mp3'],
            'upper case' => ['Q1/intro-en.mp3'],
            'backslash' => ['q1\\intro-en.mp3'],
            'space' => ['q1/intro en.mp3'],
            'null byte' => ["q1/intro-en.mp3\0.mp3"],
            'url encoded' => ['q1/%2e%2e/secret.mp3'],
            'empty' => [''],
            'not a string' => [['q1/intro-en.mp3']],
            'too long' => [str_repeat('a', 252) . '.mp3'],
        ];
    }

    #[DataProvider('unsafePaths')]
    public function testUnsafePathsAreRefusedBeforeTheDiskIsTouched(mixed $path): void {
        $this->assertFalse(QuestionMediaBundle::isSafePath($path));
        $this->assertRefused(fn () => QuestionMediaBundle::open($this->dir . '/b')->readMp3($path), QuestionMediaBundle::E_INVALID_PATH);
    }

    public function testASymlinkedFileIsRefusedEvenWhenItPointsInsideTheBundle(): void {
        symlink($this->dir . '/secret.mp3', $this->dir . '/b/q1/out.mp3');
        symlink($this->dir . '/b/q1/intro-en.mp3', $this->dir . '/b/q1/in.mp3');
        $bundle = QuestionMediaBundle::open($this->dir . '/b');
        $this->assertRefused(fn () => $bundle->readMp3('q1/out.mp3'), QuestionMediaBundle::E_SYMLINK);
        $this->assertRefused(fn () => $bundle->readMp3('q1/in.mp3'), QuestionMediaBundle::E_SYMLINK);
    }

    public function testASymlinkedDirectoryIsRefused(): void {
        mkdir($this->dir . '/outside');
        copy(self::FIXTURE . '/demo-q1/intro-en.mp3', $this->dir . '/outside/x.mp3');
        symlink($this->dir . '/outside', $this->dir . '/b/linked');
        $this->assertRefused(fn () => QuestionMediaBundle::open($this->dir . '/b')->readMp3('linked/x.mp3'), QuestionMediaBundle::E_SYMLINK);
    }

    public function testAHardLinkIsAnOrdinaryFile(): void {
        link($this->dir . '/b/q1/intro-en.mp3', $this->dir . '/b/q1/hard.mp3');
        $this->assertNotSame('', QuestionMediaBundle::open($this->dir . '/b')->readMp3('q1/hard.mp3'));
    }

    public function testMissingWrongTypeAndTooLargeFiles(): void {
        $bundle = QuestionMediaBundle::open($this->dir . '/b');
        $this->assertRefused(fn () => $bundle->readMp3('q1/none.mp3'), QuestionMediaBundle::E_MISSING);
        $this->assertRefused(fn () => $bundle->readMp3('q1/intro-en.json'), QuestionMediaBundle::E_INVALID_PATH); // extension
        mkdir($this->dir . '/b/q1/dir.mp3');
        $this->assertRefused(fn () => $bundle->readMp3('q1/dir.mp3'), QuestionMediaBundle::E_MISSING);

        file_put_contents($this->dir . '/b/q1/html.mp3', '<html><script>alert(1)</script></html>');
        $this->assertRefused(fn () => $bundle->readMp3('q1/html.mp3'), QuestionMediaBundle::E_WRONG_TYPE);
        file_put_contents($this->dir . '/b/q1/id3only.mp3', "ID3\x04\x00\x00\x00\x00\x00\x00");
        $this->assertRefused(fn () => $bundle->readMp3('q1/id3only.mp3'), QuestionMediaBundle::E_WRONG_TYPE);

        $big = fopen($this->dir . '/b/q1/big.mp3', 'w');
        fwrite($big, (string)file_get_contents(self::FIXTURE . '/demo-q1/intro-en.mp3'));
        ftruncate($big, QuestionMediaBundle::MP3_MAX_BYTES + 1);
        fclose($big);
        $this->assertRefused(fn () => $bundle->readMp3('q1/big.mp3'), QuestionMediaBundle::E_TOO_LARGE);

        file_put_contents($this->dir . '/b/q1/big.json', '[' . str_repeat(' ', QuestionMediaBundle::JSON_MAX_BYTES) . ']');
        $this->assertRefused(fn () => $bundle->readTimeline('q1/big.json'), QuestionMediaBundle::E_TOO_LARGE);
    }

    /** @return array<string, array{0: string}> */
    public static function badTimelines(): array {
        return [
            'not json' => ['{nope'],
            'object' => ['{"text": "x", "start": 0, "end": 1}'],
            'no text' => ['[{"start": 0, "end": 1}]'],
            'string times' => ['[{"text": "x", "start": "0", "end": "1"}]'],
            'end before start' => ['[{"text": "x", "start": 2, "end": 1}]'],
            'negative' => ['[{"text": "x", "start": -1, "end": 1}]'],
            'role not a string' => ['[{"role": 5, "text": "x", "start": 0, "end": 1}]'],
        ];
    }

    #[DataProvider('badTimelines')]
    public function testABadTimelineIsRefused(string $json): void {
        file_put_contents($this->dir . '/b/q1/t.json', $json);
        $this->assertRefused(fn () => QuestionMediaBundle::open($this->dir . '/b')->readTimeline('q1/t.json'), QuestionMediaBundle::E_INVALID_JSON);
    }

    public function testCuesMustBeAnObject(): void {
        file_put_contents($this->dir . '/b/q1/cues.json', '["a", "b"]');
        $this->assertRefused(fn () => QuestionMediaBundle::open($this->dir . '/b')->readCues('q1/cues.json'), QuestionMediaBundle::E_INVALID_JSON);
    }

    /** @return array<string, array{0: ?string}> */
    public static function badManifests(): array {
        return [
            'missing' => [null],
            'not json' => ['{'],
            'unknown format' => ['{"format": "learning-question-media/3", "questions": []}'],
            'questions not a list' => ['{"format": "learning-question-media/1", "questions": {"a": 1}}'],
        ];
    }

    #[DataProvider('badManifests')]
    public function testABadManifestStopsTheImport(?string $json): void {
        unlink($this->dir . '/b/manifest.json');
        if ($json !== null) {
            file_put_contents($this->dir . '/b/manifest.json', $json);
        }
        $this->expectException(\InvalidArgumentException::class);
        QuestionMediaBundle::open($this->dir . '/b');
    }

    public function testASymlinkedManifestIsRefused(): void {
        unlink($this->dir . '/b/manifest.json');
        file_put_contents($this->dir . '/m.json', json_encode(['format' => QuestionMediaBundle::FORMAT, 'questions' => []]));
        symlink($this->dir . '/m.json', $this->dir . '/b/manifest.json');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('symlink');
        QuestionMediaBundle::open($this->dir . '/b');
    }

    public function testMp3Detection(): void {
        $this->assertTrue(QuestionMediaBundle::looksLikeMp3((string)file_get_contents(self::FIXTURE . '/demo-q2/intro-en.mp3')));
        // ID3v2 tag of 4 bytes, then a frame header (MPEG-1 layer III, 128 kbit/s, 44.1 kHz).
        $this->assertTrue(QuestionMediaBundle::looksLikeMp3("ID3\x04\x00\x00\x00\x00\x00\x04ABCD\xFF\xFB\x90\x00"));
        $this->assertFalse(QuestionMediaBundle::looksLikeMp3("ID3\x04\x00\x00\x00\x00\x00\x04ABCD<htm"));
        $this->assertFalse(QuestionMediaBundle::looksLikeMp3("\xFF\xFB\xF0\x00"), 'bitrate index 1111 is invalid');
        $this->assertFalse(QuestionMediaBundle::looksLikeMp3('PK' . "\x03\x04"));
        $this->assertFalse(QuestionMediaBundle::looksLikeMp3(''));
    }

    private function assertRefused(callable $fn, string $reason): void {
        try {
            $fn();
            $this->fail('expected the file to be refused with ' . $reason);
        } catch (QuestionMediaFileException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }
}
