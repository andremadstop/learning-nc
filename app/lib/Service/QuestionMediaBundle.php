<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

/**
 * A media bundle on disk in the format learning-question-media/1 or /2 (GitHub #66), read for
 * `occ learning:import-question-media`:
 *
 *   <bundle>/manifest.json                    {"format": "learning-question-media/1", "questions": [...]}
 *   <bundle>/<dir>/intro-<lang>.mp3 + .json   audio and its timeline [{role, text, start, end}] (seconds)
 *   <bundle>/<dir>/dialog-<lang>.mp3 + .json
 *   <bundle>/<dir>/cues.json                  keywords per line, contrast wrong/right, key takeaway
 *
 * Format /2 is /1 plus a spoken explanation per answer option: an entry of feedback_per_option
 * may carry "audio": {"<lang>": "opt-<option_index>-<lang>.mp3"}, a path relative to the
 * question's folder <exam_key>/, with a sidecar of the same name ending in .json:
 * {duration, role, text} (seconds; readOptionSidecar()). Everything else is the same as /1, so a
 * /1 bundle is read exactly as before.
 *
 * Every file is named by the manifest with a path relative to the bundle. This class is the one
 * place that turns such a path into bytes, and it refuses:
 * - a path outside [a-z0-9._/-], an absolute path, an empty segment, "." or "..";
 * - a symlink anywhere on the way (each segment is checked, and the resolved path must still be
 *   inside the bundle — hard links are ordinary files and are accepted);
 * - a file over the size limit of its type, an mp3 that does not start like one (ID3 tag or an
 *   MPEG audio frame), a JSON file that does not parse to the expected shape.
 *
 * Pure file reading, no database: QuestionMediaService decides what is stored.
 */
final class QuestionMediaBundle {
    public const FORMAT = 'learning-question-media/1';
    public const FORMAT_V2 = 'learning-question-media/2';
    public const FORMATS = [self::FORMAT, self::FORMAT_V2];
    public const MANIFEST_MAX_BYTES = 33554432;   // 32 MiB (a real security-analyst bundle's manifest: 1.4 MB for 538 questions)
    public const MP3_MAX_BYTES = 8388608;         // 8 MiB per track (64 kbit/s ≈ 17 minutes)
    public const JSON_MAX_BYTES = 262144;         // 256 KiB per timeline or cues file
    public const TIMELINE_MAX_LINES = 500;
    public const TIMELINE_TEXT_MAX = 4000;
    public const ROLE_MAX = 64;
    public const PATH_MAX = 255;
    public const KINDS = ['intro', 'dialog'];

    /** Reasons a file is refused; the import report groups by them. */
    public const E_INVALID_PATH = 'invalid_path';
    public const E_SYMLINK = 'symlink';
    public const E_MISSING = 'missing';
    public const E_TOO_LARGE = 'too_large';
    public const E_WRONG_TYPE = 'wrong_type';
    public const E_INVALID_JSON = 'invalid_json';

    /** @param list<mixed> $questions */
    private function __construct(
        private string $root,
        private array $questions,
        private string $format = self::FORMAT,
    ) {
    }

    /**
     * Open a bundle directory and read its manifest.
     *
     * @throws \InvalidArgumentException with a message for the operator
     */
    public static function open(string $dir): self {
        if ($dir === '' || !is_dir($dir)) {
            throw new \InvalidArgumentException('Bundle directory not found: ' . $dir);
        }
        $root = realpath($dir);
        if ($root === false) {
            throw new \InvalidArgumentException('Bundle directory not readable: ' . $dir);
        }
        $bundle = new self(rtrim($root, '/'), []);
        try {
            $raw = $bundle->read('manifest.json', 'json', self::MANIFEST_MAX_BYTES);
        } catch (QuestionMediaFileException $e) {
            throw new \InvalidArgumentException('manifest.json: ' . $e->reason);
        }
        try {
            $manifest = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('manifest.json is not valid JSON: ' . $e->getMessage());
        }
        if (!is_array($manifest) || !in_array($manifest['format'] ?? null, self::FORMATS, true)) {
            throw new \InvalidArgumentException('manifest.json: "format" must be "' . implode('" or "', self::FORMATS) . '"');
        }
        if (!is_array($manifest['questions'] ?? null) || !array_is_list($manifest['questions'])) {
            throw new \InvalidArgumentException('manifest.json: "questions" must be a list');
        }
        return new self($bundle->root, $manifest['questions'], (string)$manifest['format']);
    }

    /** The manifest's format: self::FORMAT or self::FORMAT_V2. */
    public function format(): string {
        return $this->format;
    }

    /** Whether the bundle may carry spoken explanations per answer option (format /2). */
    public function hasOptionAudio(): bool {
        return $this->format === self::FORMAT_V2;
    }

    /**
     * The bundle path of an option track: $file relative to the question's folder <exam_key>/.
     * Null when either part is not a safe path (the caller reports it as refused).
     */
    public static function optionAudioPath(string $examKey, mixed $file): ?string {
        if (!is_string($file) || !self::isSafePath($examKey) || str_contains($examKey, '/')) {
            return null;
        }
        $path = $examKey . '/' . $file;
        return self::isSafePath($path) ? $path : null;
    }

    /** @return list<mixed> the manifest's question entries as written */
    public function questions(): array {
        return $this->questions;
    }

    /**
     * Whether a manifest path is acceptable at all (before touching the disk).
     */
    public static function isSafePath(mixed $path): bool {
        if (!is_string($path) || $path === '' || strlen($path) > self::PATH_MAX) {
            return false;
        }
        if (preg_match('#^[a-z0-9._-]+(?:/[a-z0-9._-]+)*$#D', $path) !== 1) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    /**
     * MPEG audio by its first bytes: an ID3v2 tag followed by a frame sync, or a frame sync
     * (11 set bits) at the start. Enough to refuse a renamed HTML/JSON/ZIP file; the browser
     * decodes the audio itself.
     */
    public static function looksLikeMp3(string $bytes): bool {
        $offset = 0;
        if (strncmp($bytes, 'ID3', 3) === 0) {
            if (strlen($bytes) < 10) {
                return false;
            }
            $sizeBytes = array_values(unpack('C4', substr($bytes, 6, 4)) ?: []);
            if (count($sizeBytes) !== 4) {
                return false;
            }
            foreach ($sizeBytes as $b) {
                if ($b > 0x7F) {
                    return false; // not a syncsafe integer
                }
            }
            $offset = 10 + (($sizeBytes[0] << 21) | ($sizeBytes[1] << 14) | ($sizeBytes[2] << 7) | $sizeBytes[3]);
            if ((ord($bytes[5]) & 0x10) !== 0) {
                $offset += 10; // footer present
            }
        }
        if (strlen($bytes) < $offset + 4) {
            return false;
        }
        $b0 = ord($bytes[$offset]);
        $b1 = ord($bytes[$offset + 1]);
        $b2 = ord($bytes[$offset + 2]);
        if ($b0 !== 0xFF || ($b1 & 0xE0) !== 0xE0) {
            return false;
        }
        // Version 01 and layer 00 are reserved; bitrate 1111 and sample rate 11 are invalid.
        return (($b1 >> 3) & 0x03) !== 0x01 && (($b1 >> 1) & 0x03) !== 0x00
            && (($b2 >> 4) & 0x0F) !== 0x0F && (($b2 >> 2) & 0x03) !== 0x03;
    }

    /**
     * The bytes of an mp3 named by the manifest.
     *
     * @throws QuestionMediaFileException
     */
    public function readMp3(mixed $path): string {
        $bytes = $this->read($path, 'mp3', self::MP3_MAX_BYTES);
        if (!self::looksLikeMp3($bytes)) {
            throw new QuestionMediaFileException(self::E_WRONG_TYPE, (string)$path);
        }
        return $bytes;
    }

    /**
     * A timeline file, checked and normalized to [{role, text, start, end}] (seconds, start ≤ end).
     *
     * @return list<array{role: ?string, text: string, start: float, end: float}>
     * @throws QuestionMediaFileException
     */
    public function readTimeline(mixed $path): array {
        $data = $this->readJson($path);
        return self::normalizeTimeline($data, (string)$path);
    }

    /**
     * The sidecar of an option track, {duration, role, text} (format /2), as a timeline of one
     * line [{role, text, start: 0, end: duration}] so the player and the export treat it like
     * every other track.
     *
     * @return list<array{role: ?string, text: string, start: float, end: float}>
     * @throws QuestionMediaFileException
     */
    public function readOptionSidecar(mixed $path): array {
        $data = $this->readJson($path);
        if (!is_array($data) || array_is_list($data)) {
            throw new QuestionMediaFileException(self::E_INVALID_JSON, (string)$path);
        }
        return self::normalizeTimeline([[
            'role' => $data['role'] ?? null,
            'text' => $data['text'] ?? null,
            'start' => 0,
            'end' => $data['duration'] ?? null,
        ]], (string)$path);
    }

    /** Whether a file exists in the bundle at a safe path (a symlink counts as there: read() refuses it). */
    public function has(string $path): bool {
        if (!self::isSafePath($path)) {
            return false;
        }
        $full = $this->root . '/' . $path;
        return is_link($full) || file_exists($full);
    }

    /**
     * A cues file: a JSON object, re-encoded (no other shape check — the player reads it as
     * plain text only).
     *
     * @return array<string, mixed>
     * @throws QuestionMediaFileException
     */
    public function readCues(mixed $path): array {
        $data = $this->readJson($path);
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new QuestionMediaFileException(self::E_INVALID_JSON, (string)$path);
        }
        return $data;
    }

    /**
     * @return list<array{role: ?string, text: string, start: float, end: float}>
     * @throws QuestionMediaFileException
     */
    public static function normalizeTimeline(mixed $data, string $path): array {
        if (!is_array($data) || !array_is_list($data) || count($data) > self::TIMELINE_MAX_LINES) {
            throw new QuestionMediaFileException(self::E_INVALID_JSON, $path);
        }
        $lines = [];
        foreach ($data as $line) {
            if (!is_array($line)) {
                throw new QuestionMediaFileException(self::E_INVALID_JSON, $path);
            }
            $text = $line['text'] ?? null;
            $start = $line['start'] ?? null;
            $end = $line['end'] ?? null;
            $role = $line['role'] ?? null;
            if (!is_string($text) || mb_strlen($text) > self::TIMELINE_TEXT_MAX
                || !(is_int($start) || is_float($start)) || !(is_int($end) || is_float($end))
                || $start < 0 || $end < $start || $end > 86400
                || ($role !== null && (!is_string($role) || mb_strlen($role) > self::ROLE_MAX))) {
                throw new QuestionMediaFileException(self::E_INVALID_JSON, $path);
            }
            $lines[] = ['role' => $role, 'text' => $text, 'start' => (float)$start, 'end' => (float)$end];
        }
        return $lines;
    }

    /**
     * @throws QuestionMediaFileException
     */
    private function readJson(mixed $path): mixed {
        $raw = $this->read($path, 'json', self::JSON_MAX_BYTES);
        try {
            return json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new QuestionMediaFileException(self::E_INVALID_JSON, (string)$path);
        }
    }

    /**
     * @throws QuestionMediaFileException
     */
    private function read(mixed $path, string $extension, int $maxBytes): string {
        if (!self::isSafePath($path) || !str_ends_with((string)$path, '.' . $extension)) {
            throw new QuestionMediaFileException(self::E_INVALID_PATH, is_string($path) ? $path : '');
        }
        /** @var string $path */
        $current = $this->root;
        foreach (explode('/', $path) as $segment) {
            $current .= '/' . $segment;
            if (is_link($current)) {
                throw new QuestionMediaFileException(self::E_SYMLINK, $path);
            }
        }
        clearstatcache(true, $current);
        if (!is_file($current)) {
            throw new QuestionMediaFileException(self::E_MISSING, $path);
        }
        $real = realpath($current);
        if ($real === false || !str_starts_with($real, $this->root . '/')) {
            throw new QuestionMediaFileException(self::E_SYMLINK, $path);
        }
        $size = filesize($real);
        if ($size === false || $size > $maxBytes) {
            throw new QuestionMediaFileException(self::E_TOO_LARGE, $path);
        }
        $bytes = @file_get_contents($real, false, null, 0, $maxBytes + 1);
        if ($bytes === false) {
            throw new QuestionMediaFileException(self::E_MISSING, $path);
        }
        if (strlen($bytes) > $maxBytes) {
            throw new QuestionMediaFileException(self::E_TOO_LARGE, $path);
        }
        return $bytes;
    }
}
