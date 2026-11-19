<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\Answer;
use OCA\Learning\Db\AnswerMapper;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\Question;
use OCA\Learning\Db\QuestionCues;
use OCA\Learning\Db\QuestionCuesMapper;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Db\QuestionTrack;
use OCA\Learning\Db\QuestionTrackMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Media per question (GitHub #66): the import of a learning-question-media/1 or /2 bundle and the rules
 * for who gets which part of it.
 *
 * WHO GETS WHAT. The dialog track (its audio and its subtitles) and the cues explain the right
 * answer; the intro only sets the scene, like the hint. So:
 * - The pool's editors and course instructors (QuestionService::mustWithholdSolutionForQuestion
 *   false) get everything, always — they hold the answer key anyway.
 * - Everyone else needs read access to the pool (QuestionService::hasPoolAccess; otherwise the
 *   question "does not exist", 404) and gets nothing while a counting exam runs for them on the
 *   pool (ExamLock).
 * - Outside that, the intro is free; the dialog and the cues come only with a REVEAL: the answer
 *   responses of training and Leitner / Smart Queue — the same places that hand out the rich
 *   feedback (RichFeedbackService) — carry revealFor(): the cues, the dialog timelines and audio
 *   URLs with a grant, an HMAC over (user, question, expiry) that is valid for GRANT_TTL. The
 *   audio endpoint serves a dialog track to a learner only with a valid grant of their own. A
 *   learner can therefore not fetch the explanations of a whole pool before answering — the answer
 *   key the 2026-11 audit closed for the question read APIs.
 * - The spoken explanation per answer option (kind opt<position>, bundle format /2) says which
 *   option is right, so it follows the dialog: before answering a learner learns nothing about
 *   it — not even which options have one (if only the right option had a voice, its presence
 *   alone would give the answer away). The answer response lists them per answer
 *   (option_tracks: answer_id, position, tracks with the same grant), and the audio endpoint
 *   serves them only with that grant. Editors get them in metadata() without a grant.
 *
 * IMPORT (importBundle()). Questions are matched by exam_key inside the pool, never by text. Per
 * matched question the manifest is the truth for its media: tracks it lists are written (a track
 * with the same sha256 and the same timeline whose file is still in the app data is left alone,
 * so a second run changes nothing and a lost file comes back), tracks it no longer lists are
 * removed, its cues replace the stored ones. A listed file that is missing or refused, or whose
 * write fails (reported as write_failed, the run goes on), keeps whatever was stored before. The
 * text fields (hint, background_fact, key_takeaway/merksatz, feedback_per_option) go through
 * RichFeedbackFormat like every other import; a field the entry does not carry keeps its stored value.
 */
class QuestionMediaService {
    public const GRANT_TTL = 43200; // 12 h: a learner who answered can listen to the dialog the same day
    private const GRANT_CONTEXT = 'learning-qmedia-grant-v1';
    private const LANG_PATTERN = '/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/D';
    private const MANIFEST = 'manifest.json';

    public function __construct(
        private QuestionMediaStorage $storage,
        private QuestionTrackMapper $trackMapper,
        private QuestionCuesMapper $cuesMapper,
        private QuestionMapper $questionMapper,
        private AnswerMapper $answerMapper,
        private PoolMapper $poolMapper,
        private QuestionService $questionService,
        private IDBConnection $db,
        private ICrypto $crypto,
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
    ) {
    }

    // ------------------------------------------------------------------ delivery

    /**
     * What the player may load for a question before (or without) answering. Editors get every
     * track (option tracks under option_tracks) and the cues; learners the intro tracks only.
     *
     * @return array{question_id: int, tracks: list<array<string, mixed>>, cues?: array<string, mixed>|null, option_tracks?: list<array{answer_id: int, position: int, tracks: list<array<string, mixed>>}>}
     * @throws NotFoundException no access, or no such question
     * @throws ForbiddenException a counting exam runs for the user on the pool
     */
    public function metadata(int $questionId, string $userId): array {
        $question = $this->visibleQuestion($questionId, $userId);
        $editor = !$this->questionService->mustWithholdSolutionForQuestion($questionId, $userId);
        if (!$editor && ExamLock::isActiveOnPool($this->db, $question->getPoolId(), $userId)) {
            throw new ForbiddenException('Media are not available during an exam');
        }
        $tracks = [];
        $options = [];
        foreach ($this->trackMapper->findByQuestion($questionId) as $track) {
            if (QuestionMediaStorage::optionPosition($track->getKind()) !== null) {
                $options[] = $track;
            } elseif ($editor || $track->getKind() === 'intro') {
                $tracks[] = $this->trackPayload($track, null);
            }
        }
        $out = ['question_id' => $questionId, 'tracks' => $tracks];
        if ($editor) {
            $out['cues'] = $this->cuesOf($questionId);
            $out['option_tracks'] = $this->optionTracksPayload($questionId, $options, null);
        }
        return $out;
    }

    /**
     * The post-answer media of a question for an answer response: the cues, the dialog tracks
     * and the spoken explanation per answer option (option_tracks), all with grant URLs, or []
     * when it has none of them. Only for responses that already reveal the solution (the
     * callers' rule, as for the rich feedback).
     *
     * @return array<string, mixed>
     */
    public function revealFor(int $questionId, string $userId): array {
        $dialog = [];
        $options = [];
        foreach ($this->trackMapper->findByQuestion($questionId) as $track) {
            if ($track->getKind() === 'dialog') {
                $dialog[] = $track;
            } elseif (QuestionMediaStorage::optionPosition($track->getKind()) !== null) {
                $options[] = $track;
            }
        }
        $cues = $this->cuesOf($questionId);
        if ($dialog === [] && $cues === null && $options === []) {
            return [];
        }
        $expires = time() + self::GRANT_TTL;
        $grant = ['expires' => $expires, 'grant' => $this->grant($userId, $questionId, $expires)];
        return ['question_media' => [
            'tracks' => array_map(fn (QuestionTrack $t): array => $this->trackPayload($t, $grant), $dialog),
            'cues' => $cues,
            'option_tracks' => $this->optionTracksPayload($questionId, $options, $grant),
        ]];
    }

    /**
     * Option tracks grouped by the answer they explain, in answer order. A track whose position
     * no answer of the question has any more (the answers were edited after the import) is left
     * out: it would explain an option that is not there.
     *
     * @param list<QuestionTrack> $tracks option tracks of the question
     * @param array{expires: int, grant: string}|null $grant
     * @return list<array{answer_id: int, position: int, tracks: list<array<string, mixed>>}>
     */
    private function optionTracksPayload(int $questionId, array $tracks, ?array $grant): array {
        if ($tracks === []) {
            return [];
        }
        $answers = $this->answerMapper->findByQuestion($questionId);
        usort($answers, static fn (Answer $a, Answer $b): int => [(int)$a->getPosition(), (int)$a->getId()] <=> [(int)$b->getPosition(), (int)$b->getId()]);
        $answerByPosition = [];
        foreach ($answers as $index => $answer) {
            // A row without a position (older imports) counts by its order, as RichFeedbackService does.
            $answerByPosition[$answer->getPosition() !== null ? (int)$answer->getPosition() : $index] = (int)$answer->getId();
        }
        $byPosition = [];
        foreach ($tracks as $track) {
            $position = QuestionMediaStorage::optionPosition($track->getKind());
            if ($position === null || !isset($answerByPosition[$position])) {
                continue;
            }
            $byPosition[$position][] = $this->trackPayload($track, $grant);
        }
        ksort($byPosition);
        $out = [];
        foreach ($byPosition as $position => $payloads) {
            $out[] = ['answer_id' => $answerByPosition[$position], 'position' => $position, 'tracks' => $payloads];
        }
        return $out;
    }

    /**
     * A track and its bytes for the audio endpoint, after every check.
     *
     * @return array{track: QuestionTrack, bytes: string}
     * @throws NotFoundException unknown track, no access to its pool, or the file is gone
     * @throws ForbiddenException exam running, or a dialog or option track without a valid grant
     * @throws QuestionMediaUnavailableException the file is there but cannot be read right now
     */
    public function openTrack(int $trackId, string $userId, ?string $expires, ?string $grant): array {
        $track = $this->trackMapper->findById($trackId);
        if ($track === null) {
            throw new NotFoundException('Track not found');
        }
        $question = $this->visibleQuestion($track->getQuestionId(), $userId);
        $editor = !$this->questionService->mustWithholdSolutionForQuestion($track->getQuestionId(), $userId);
        if (!$editor) {
            if (ExamLock::isActiveOnPool($this->db, $question->getPoolId(), $userId)) {
                throw new ForbiddenException('Media are not available during an exam');
            }
            if ($track->getKind() !== 'intro' && !$this->grantIsValid($userId, $track->getQuestionId(), $expires, $grant)) {
                throw new ForbiddenException('The dialog is available after answering');
            }
        }
        try {
            $bytes = $this->storage->get($track->getPoolId(), $track->getFileName())->getContent();
        } catch (\OCP\Files\NotFoundException | \OCP\Files\NotPermittedException $e) {
            throw new NotFoundException('Track file not found');
        } catch (\OCP\Files\GenericFileException | \OCP\Lock\LockedException $e) {
            // A read error of the storage backend or a file locked by a running import: temporary,
            // so 503 (the player may retry), not a 500 with a stack trace.
            $this->logger->warning('Question media: track {track} could not be read: {error}', [
                'app' => 'learning', 'track' => $trackId, 'error' => $e->getMessage(),
            ]);
            throw new QuestionMediaUnavailableException('Track file cannot be read', 0, $e);
        }
        return ['track' => $track, 'bytes' => $bytes];
    }

    public function grant(string $userId, int $questionId, int $expires): string {
        return bin2hex($this->crypto->calculateHMAC(self::GRANT_CONTEXT . "\n" . $userId . "\n" . $questionId . "\n" . $expires));
    }

    public function grantIsValid(string $userId, int $questionId, ?string $expires, ?string $grant): bool {
        if ($expires === null || $grant === null || !ctype_digit($expires) || $grant === '') {
            return false;
        }
        $until = (int)$expires;
        $now = time();
        if ($until < $now || $until > $now + self::GRANT_TTL + 60) {
            return false;
        }
        return hash_equals($this->grant($userId, $questionId, $until), $grant);
    }

    /**
     * @throws NotFoundException
     */
    private function visibleQuestion(int $questionId, string $userId): Question {
        try {
            $question = $this->questionMapper->findById($questionId);
        } catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
            throw new NotFoundException('Question not found');
        }
        if (!$this->questionService->hasPoolAccess($question->getPoolId(), $userId)) {
            throw new NotFoundException('Question not found');
        }
        return $question;
    }

    /**
     * @param array{expires: int, grant: string}|null $grant
     * @return array<string, mixed>
     */
    private function trackPayload(QuestionTrack $track, ?array $grant): array {
        $url = $this->urlGenerator->linkToRoute('learning.questionMedia.track', ['trackId' => $track->getId()]);
        if ($grant !== null) {
            $url .= '?' . http_build_query(['expires' => $grant['expires'], 'grant' => $grant['grant']]);
        }
        $timeline = $track->getTimeline();
        $decoded = $timeline !== null ? json_decode($timeline, true) : null;
        return [
            'id' => $track->getId(),
            'kind' => $track->getKind(),
            'lang' => $track->getLang(),
            'duration_ms' => $track->getDurationMs(),
            'timeline' => is_array($decoded) ? $decoded : [],
            'url' => $url,
        ];
    }

    /** @return array<string, mixed>|null */
    private function cuesOf(int $questionId): ?array {
        $row = $this->cuesMapper->findByQuestion($questionId);
        if ($row === null) {
            return null;
        }
        $decoded = json_decode($row->getCues(), true);
        return is_array($decoded) ? $decoded : null;
    }

    // ------------------------------------------------------------------ export

    /**
     * Write the media of a pool as a bundle that importBundle() reads back (occ learning:export-pool
     * --media-dir): format /2 when at least one option track is written, /1 otherwise.
     *
     * Every question with a track or cues gets an entry: its tracks, its cues, its rich feedback
     * texts (RichFeedbackFormat::toExport, the same fields as the pool JSON export) and, per
     * option with a stored voice, "audio": {"<lang>": "opt-<option_index>-<lang>.mp3"} with the
     * sidecar {duration, role, text}. option_index is the answer's index in stored order, so the
     * pool JSON export of the same pool and this bundle name the same options.
     *
     * A question needs an exam_key (the import matches by it; questions without are reported and
     * left out). Its files go into the folder <exam_key>/ when the key is a plain folder name
     * ([a-z0-9._-], not manifest.json), else into media-q<question id>/ (with a suffix -2, -3 … when
     * another question of the pool has that name as its exam_key) — but format /2 resolves option
     * audio inside <exam_key>/, so option tracks of such a question cannot be written and are reported.
     * The target must not exist or be an empty directory — nothing is overwritten.
     *
     * Two questions of the pool with the same exam_key cannot be told apart by the import
     * (it reports them as ambiguous); only the first gets an entry, the key is reported.
     *
     * @return array{format: string, questions: int, tracks: int, option_tracks: int, bytes: int, without_key: list<int>, duplicate_key: list<string>, unusable_key: list<string>, unreadable: list<string>}
     * @throws NotFoundException the pool does not exist
     * @throws \InvalidArgumentException the target cannot be used
     * @throws \RuntimeException a file cannot be written
     */
    public function exportBundle(int $poolId, string $dir): array {
        try {
            $this->poolMapper->findById($poolId);
        } catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
            throw new NotFoundException('Pool not found: ' . $poolId);
        }
        $dir = rtrim($dir, '/');
        if ($dir === '' || is_link($dir) || (file_exists($dir) && !is_dir($dir))) {
            throw new \InvalidArgumentException('Not a directory: ' . $dir);
        }
        if (is_dir($dir) && (scandir($dir) ?: []) !== ['.', '..']) {
            throw new \InvalidArgumentException('Directory is not empty: ' . $dir);
        }
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            throw new \InvalidArgumentException('Cannot create directory: ' . $dir);
        }

        $report = [
            'format' => QuestionMediaBundle::FORMAT,
            'questions' => 0, 'tracks' => 0, 'option_tracks' => 0, 'bytes' => 0,
            'without_key' => [], 'duplicate_key' => [], 'unusable_key' => [], 'unreadable' => [],
        ];
        $entries = [];
        $seenKeys = [];
        $questions = $this->questionMapper->findByPoolId($poolId);
        // Folder names nobody else may take: every exam_key of the pool that is a folder name
        // (also of questions without media, in any order), and the manifest's own name.
        $takenFolders = [self::MANIFEST => true];
        foreach ($questions as $question) {
            $key = trim((string)$question->getExamKey());
            if (self::isFolderKey($key)) {
                $takenFolders[$key] = true;
            }
        }
        foreach ($questions as $question) {
            $questionId = (int)$question->getId();
            $tracks = $this->trackMapper->findByQuestion($questionId);
            $cues = $this->cuesMapper->findByQuestion($questionId);
            if ($tracks === [] && $cues === null) {
                continue;
            }
            $key = $question->getExamKey();
            if (!is_string($key) || trim($key) === '') {
                $report['without_key'][] = $questionId;
                continue;
            }
            $key = trim($key);
            if (isset($seenKeys[$key])) {
                $report['duplicate_key'][] = $key;
                continue;
            }
            $seenKeys[$key] = true;
            $keyIsFolder = self::isFolderKey($key);
            if ($keyIsFolder) {
                $folder = $key;
            } else {
                // The fallback must not be the folder of another question's real exam_key (or
                // another fallback): both would write the same files, the last one winning.
                $folder = 'media-q' . $questionId;
                for ($n = 2; isset($takenFolders[$folder]); $n++) {
                    $folder = 'media-q' . $questionId . '-' . $n;
                }
                $takenFolders[$folder] = true;
            }

            $answers = $this->answerMapper->findByQuestion($questionId);
            usort($answers, static fn (Answer $a, Answer $b): int => [(int)$a->getPosition(), (int)$a->getId()] <=> [(int)$b->getPosition(), (int)$b->getId()]);
            $indexByPosition = [];
            $exportAnswers = [];
            foreach ($answers as $index => $answer) {
                $position = $answer->getPosition() !== null ? (int)$answer->getPosition() : $index;
                $indexByPosition[$position] = $index;
                $exportAnswers[] = ['position' => $position, 'is_correct' => (bool)$answer->getIsCorrect()];
            }

            $entry = ['exam_key' => $key] + RichFeedbackFormat::toExport(
                $question->getHint(), $question->getBackgroundFact(), $question->getKeyTakeaway(),
                $question->getOptionFeedback(), $exportAnswers
            );
            $entry['tracks'] = [];
            $optionAudio = [];
            foreach ($tracks as $track) {
                $lang = $track->getLang();
                $lines = json_decode((string)$track->getTimeline(), true);
                $lines = is_array($lines) ? $lines : null;
                $position = QuestionMediaStorage::optionPosition($track->getKind());
                if ($position === null) {
                    $file = $folder . '/' . $track->getKind() . '-' . $lang . '.mp3';
                    if (!$this->exportFile($track, $dir, $file, $report)) {
                        continue;
                    }
                    $item = ['kind' => $track->getKind(), 'lang' => $lang, 'file' => $file];
                    if ($lines !== null) {
                        $item['timeline'] = substr($file, 0, -4) . '.json';
                        $this->writeBundleFile($dir, $item['timeline'], $this->exportJson($lines));
                    }
                    $entry['tracks'][] = $item;
                    $report['tracks']++;
                    continue;
                }
                if (!isset($indexByPosition[$position])) {
                    continue; // explains an answer the question no longer has
                }
                if (!$keyIsFolder) {
                    $report['unusable_key'][] = $key;
                    continue;
                }
                $index = $indexByPosition[$position];
                $name = 'opt-' . $index . '-' . $lang . '.mp3';
                if (!$this->exportFile($track, $dir, $key . '/' . $name, $report)) {
                    continue;
                }
                if ($lines !== null && $lines !== []) {
                    $first = $lines[0];
                    $this->writeBundleFile($dir, $key . '/' . substr($name, 0, -4) . '.json', $this->exportJson([
                        'duration' => $track->getDurationMs() !== null ? $track->getDurationMs() / 1000 : (float)($first['end'] ?? 0),
                        'role' => $first['role'] ?? null,
                        'text' => (string)($first['text'] ?? ''),
                    ]));
                }
                $optionAudio[$index][$lang] = $name;
                $report['option_tracks']++;
            }
            if ($optionAudio !== []) {
                $options = [];
                foreach ($entry['feedback_per_option'] ?? [] as $option) {
                    $options[$option['option_index']] = $option;
                }
                foreach ($optionAudio as $index => $audio) {
                    $options[$index] ??= [
                        'option_index' => $index,
                        'letter' => $index < 26 ? chr(65 + $index) : null,
                        'verdict' => $exportAnswers[$index]['is_correct'] ? 'correct' : 'wrong',
                    ];
                    if ($options[$index]['letter'] === null) {
                        unset($options[$index]['letter']);
                    }
                    ksort($audio);
                    $options[$index]['audio'] = $audio;
                }
                ksort($options);
                $entry['feedback_per_option'] = array_values($options);
            }
            if ($cues !== null) {
                $entry['cues'] = $folder . '/cues.json';
                $this->writeBundleFile($dir, $entry['cues'], $this->exportJson(json_decode($cues->getCues(), true)));
            }
            $entries[] = $entry;
            $report['questions']++;
        }
        $report['unusable_key'] = array_values(array_unique($report['unusable_key']));
        if ($report['option_tracks'] > 0) {
            $report['format'] = QuestionMediaBundle::FORMAT_V2;
        }
        $this->writeBundleFile($dir, self::MANIFEST, $this->exportJson(['format' => $report['format'], 'questions' => $entries]));
        return $report;
    }

    /** Whether an exam_key can be the folder of its question in a bundle (format /2 resolves option audio there). */
    private static function isFolderKey(string $key): bool {
        return $key !== self::MANIFEST && QuestionMediaBundle::optionAudioPath($key, 'x.mp3') !== null;
    }

    /** @param array<string, mixed> $report */
    private function exportFile(QuestionTrack $track, string $dir, string $file, array &$report): bool {
        try {
            $bytes = $this->storage->get($track->getPoolId(), $track->getFileName())->getContent();
        } catch (\Throwable $e) {
            $report['unreadable'][] = $file . ' (' . (new \ReflectionClass($e))->getShortName() . ')';
            return false;
        }
        $this->writeBundleFile($dir, $file, $bytes);
        $report['bytes'] += strlen($bytes);
        return true;
    }

    private function exportJson(mixed $data): string {
        return (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n";
    }

    /** @throws \RuntimeException */
    private function writeBundleFile(string $dir, string $file, string $bytes): void {
        $path = $dir . '/' . $file;
        $parent = dirname($path);
        if (!is_dir($parent) && !@mkdir($parent, 0750, true)) {
            throw new \RuntimeException('Cannot create directory: ' . $parent);
        }
        if (@file_put_contents($path, $bytes) !== strlen($bytes)) {
            throw new \RuntimeException('Cannot write: ' . $path);
        }
    }

    // ------------------------------------------------------------------ import

    /**
     * Import (or, with $dryRun, only check) a bundle into the questions of a pool.
     *
     * @return array<string, mixed> the report (see ImportQuestionMediaCommand)
     * @throws NotFoundException the pool does not exist
     */
    public function importBundle(int $poolId, QuestionMediaBundle $bundle, bool $dryRun): array {
        try {
            $this->poolMapper->findById($poolId);
        } catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
            throw new NotFoundException('Pool not found: ' . $poolId);
        }

        $byKey = [];
        foreach ($this->questionMapper->findByPoolId($poolId) as $question) {
            $key = $question->getExamKey();
            if (is_string($key) && $key !== '') {
                $byKey[$key][] = $question;
            }
        }

        $report = [
            'entries' => 0,
            'matched' => 0,
            'unknown_keys' => [],
            'ambiguous_keys' => [],
            'invalid_entries' => [],
            'missing_files' => [],
            'refused_files' => [],
            'write_failed' => [],
            'tracks_written' => 0,
            'tracks_unchanged' => 0,
            'tracks_removed' => 0,
            'option_tracks_written' => 0,
            'option_tracks_unchanged' => 0,
            'option_tracks_removed' => 0,
            'cues_written' => 0,
            'cues_unchanged' => 0,
            'cues_removed' => 0,
            'feedback_updated' => 0,
            'feedback_unchanged' => 0,
            'feedback_warnings' => [],
            'dropped_languages' => [],
            'bytes_written' => 0,
            'pool_questions' => array_sum(array_map('count', $byKey)),
            'format' => $bundle->format(),
        ];
        $seen = [];

        foreach ($bundle->questions() as $index => $entry) {
            $report['entries']++;
            $label = '#' . ($index + 1);
            if (!is_array($entry) || !is_string($entry['exam_key'] ?? null) || trim($entry['exam_key']) === '') {
                $report['invalid_entries'][] = $label . ': no exam_key';
                continue;
            }
            $key = trim($entry['exam_key']);
            $label = $key;
            if (isset($seen[$key])) {
                $report['invalid_entries'][] = $key . ': listed twice in the manifest, the second entry is ignored';
                continue;
            }
            $seen[$key] = true;
            $candidates = $byKey[$key] ?? [];
            if ($candidates === []) {
                $report['unknown_keys'][] = $key;
                continue;
            }
            if (count($candidates) > 1) {
                $report['ambiguous_keys'][] = $key;
                continue;
            }
            $question = $candidates[0];
            $report['matched']++;

            $this->importTracks($question, $entry, $bundle, $dryRun, $label, $report);
            $this->importOptionTracks($question, $entry, $bundle, $dryRun, $label, $report);
            $this->importCues($question, $entry, $bundle, $dryRun, $label, $report);
            $this->importFeedback($question, $entry, $dryRun, $label, $report);
        }

        $report['dropped_languages'] = array_count_values($report['dropped_languages']);
        ksort($report['dropped_languages']);
        return $report;
    }

    /**
     * The intro and dialog tracks of an entry. Option tracks (opt<n>) are not this list's: a
     * bundle that lists tracks says nothing about them, so they are neither written nor removed
     * here (importOptionTracks()).
     *
     * @param array<mixed> $entry
     * @param array<string, mixed> $report
     */
    private function importTracks(Question $question, array $entry, QuestionMediaBundle $bundle, bool $dryRun, string $label, array &$report): void {
        if (!array_key_exists('tracks', $entry)) {
            return; // nothing said about tracks: keep what is stored
        }
        $list = is_array($entry['tracks']) && array_is_list($entry['tracks']) ? $entry['tracks'] : null;
        if ($list === null) {
            $report['invalid_entries'][] = $label . ': "tracks" must be a list';
            return;
        }
        $stored = [];
        foreach ($this->trackMapper->findByQuestion((int)$question->getId()) as $track) {
            if (in_array($track->getKind(), QuestionMediaBundle::KINDS, true)) {
                $stored[$track->getKind() . '-' . $track->getLang()] = $track;
            }
        }

        $listed = [];
        foreach ($list as $t) {
            $kind = is_array($t) && is_string($t['kind'] ?? null) ? $t['kind'] : '';
            $lang = is_array($t) && is_string($t['lang'] ?? null) ? strtolower($t['lang']) : '';
            if (!in_array($kind, QuestionMediaBundle::KINDS, true) || preg_match(self::LANG_PATTERN, $lang) !== 1) {
                $report['invalid_entries'][] = $label . ': a track needs kind intro|dialog and a language code';
                continue;
            }
            $slot = $kind . '-' . $lang;
            if (isset($listed[$slot])) {
                $report['invalid_entries'][] = $label . ': track ' . $slot . ' listed twice';
                continue;
            }
            $listed[$slot] = true;

            try {
                $bytes = $bundle->readMp3($t['file'] ?? null);
            } catch (QuestionMediaFileException $e) {
                $this->fileProblem($report, $label, $e);
                continue; // keeps a stored copy of this track
            }
            $lines = null;
            if (array_key_exists('timeline', $t) && $t['timeline'] !== null) {
                try {
                    $lines = $bundle->readTimeline($t['timeline']);
                } catch (QuestionMediaFileException $e) {
                    $this->fileProblem($report, $label, $e);
                    continue;
                }
            }
            $this->storeTrack($question, $kind, $lang, $bytes, $lines, $stored[$slot] ?? null, $dryRun, $label, 'tracks', $report);
        }

        $this->removeUnlisted($question, $stored, $listed, $dryRun, $label, 'tracks', $report);
    }

    /**
     * The spoken explanations per answer option of a format /2 entry (feedback_per_option[].audio,
     * {"<lang>": "<file relative to <exam_key>/>"}). Stored as kind opt<position>, the position of
     * the answer the entry's option_index names in the stored order — the same mapping as the
     * option feedback texts (importFeedback()), so text and voice of an option always agree.
     *
     * Only a /2 entry that carries feedback_per_option speaks about option tracks: then its list is
     * the truth for them (a stored option track it no longer lists is removed). A /1 bundle, or an
     * entry without feedback_per_option, leaves them as they are; "audio" inside a /1 bundle is
     * reported and ignored.
     *
     * @param array<mixed> $entry
     * @param array<string, mixed> $report
     */
    private function importOptionTracks(Question $question, array $entry, QuestionMediaBundle $bundle, bool $dryRun, string $label, array &$report): void {
        $list = $entry['feedback_per_option'] ?? $entry['option_feedback'] ?? null;
        if (!is_array($list)) {
            return;
        }
        if (!$bundle->hasOptionAudio()) {
            foreach ($list as $option) {
                if (is_array($option) && array_key_exists('audio', $option)) {
                    $report['feedback_warnings'][] = $label . ': "audio" per option needs format ' . QuestionMediaBundle::FORMAT_V2 . ', ignored';
                    break;
                }
            }
            return;
        }
        [$positionMap] = $this->answerPositions($question);
        $examKey = (string)$question->getExamKey();

        $stored = [];
        foreach ($this->trackMapper->findByQuestion((int)$question->getId()) as $track) {
            if (QuestionMediaStorage::optionPosition($track->getKind()) !== null) {
                $stored[$track->getKind() . '-' . $track->getLang()] = $track;
            }
        }

        $listed = [];
        // stored slots an invalid "audio" of a known option protects (not "listed": a valid key of
        // the same option may still name one of them, and that is no duplicate)
        $kept = [];
        foreach (array_values($list) as $option) {
            if (!is_array($option) || !array_key_exists('audio', $option)) {
                continue;
            }
            $optionIndex = $option['option_index'] ?? null;
            $letter = $option['letter'] ?? null;
            // The same rules as the texts: an entry importFeedback() skips (and reports) has no voice either.
            if (!is_int($optionIndex) || $optionIndex < 0 || !isset($positionMap[$optionIndex])
                || ($letter !== null && (!is_string($letter) || strtoupper(trim($letter)) !== ($optionIndex < 26 ? chr(65 + $optionIndex) : '')))) {
                continue;
            }
            $position = $positionMap[$optionIndex];
            $audio = $option['audio'];
            if (!is_array($audio) || ($audio !== [] && array_is_list($audio)) || $position > 999) {
                $report['invalid_entries'][] = $label . ': option ' . $optionIndex . ': "audio" must be {"<lang>": "<file>"}';
                // A typo in the bundle keeps the stored voices of this option, as a bad path does.
                $kept += $this->optionSlots($stored, $position);
                continue;
            }
            $kind = QuestionMediaStorage::optionKind($position);
            foreach ($audio as $langKey => $file) {
                $lang = strtolower((string)$langKey);
                if (preg_match(self::LANG_PATTERN, $lang) !== 1) {
                    $report['invalid_entries'][] = $label . ': option ' . $optionIndex . ': "' . $langKey . '" is not a language code';
                    // Which language was meant is unknown ("english" for en?): every stored voice of
                    // this option stays, none is removed because of a misspelt key.
                    $kept += $this->optionSlots($stored, $position);
                    continue;
                }
                $slot = $kind . '-' . $lang;
                if (isset($listed[$slot])) {
                    $report['invalid_entries'][] = $label . ': option ' . $optionIndex . ' has audio ' . $lang . ' twice';
                    continue;
                }
                $listed[$slot] = true;

                $path = QuestionMediaBundle::optionAudioPath($examKey, $file);
                if ($path === null) {
                    $report['refused_files'][] = $label . ': ' . (is_string($file) ? $file : '?') . ' (' . QuestionMediaBundle::E_INVALID_PATH . ')';
                    continue; // keeps a stored copy of this track
                }
                try {
                    $bytes = $bundle->readMp3($path);
                    $sidecar = substr($path, 0, -4) . '.json';
                    $lines = str_ends_with($path, '.mp3') && $bundle->has($sidecar) ? $bundle->readOptionSidecar($sidecar) : null;
                } catch (QuestionMediaFileException $e) {
                    $this->fileProblem($report, $label, $e);
                    continue;
                }
                $this->storeTrack($question, $kind, $lang, $bytes, $lines, $stored[$slot] ?? null, $dryRun, $label, 'option_tracks', $report);
            }
        }

        $this->removeUnlisted($question, $stored, $listed + $kept, $dryRun, $label, 'option_tracks', $report);
    }

    /**
     * The stored option slots (opt<position>-<lang>) of one answer position.
     *
     * @param array<string, QuestionTrack> $stored by slot
     * @return array<string, true>
     */
    private function optionSlots(array $stored, int $position): array {
        $slots = [];
        foreach ($stored as $slot => $track) {
            if (QuestionMediaStorage::optionPosition($track->getKind()) === $position) {
                $slots[$slot] = true;
            }
        }
        return $slots;
    }

    /**
     * Write one track (file first, then its row) unless it is stored unchanged.
     *
     * @param list<array{role: ?string, text: string, start: float, end: float}>|null $lines
     * @param string $counter report prefix: tracks | option_tracks
     * @param array<string, mixed> $report
     */
    private function storeTrack(Question $question, string $kind, string $lang, string $bytes, ?array $lines, ?QuestionTrack $existing, bool $dryRun, string $label, string $counter, array &$report): void {
        $questionId = (int)$question->getId();
        $poolId = $question->getPoolId();
        $slot = $kind . '-' . $lang;
        $timeline = null;
        $duration = null;
        if ($lines !== null) {
            $timeline = json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) ?: null;
            $ends = array_column($lines, 'end');
            $duration = $ends === [] ? null : (int)round(max($ends) * 1000);
        }
        $sha = hash('sha256', $bytes);
        // Unchanged only when the row matches AND its file is still there: a database restored
        // or moved without the app data keeps its rows, and a rerun must bring the files back.
        if ($existing !== null && $existing->getSha256() === $sha && $existing->getTimeline() === $timeline
            && $this->storage->holds($poolId, $existing->getFileName(), $existing->getSize())) {
            $report[$counter . '_unchanged']++;
            return;
        }
        if ($dryRun) {
            $report[$counter . '_written']++;
            $report['bytes_written'] += strlen($bytes);
            return;
        }
        // An existing row writes into its own file; a new one never into a file another row of
        // the question names (a voice moved with its answer keeps its old name).
        $fileName = $existing?->getFileName() ?? $this->freeFileName($questionId, $kind, $lang);
        try {
            $this->storage->put($poolId, $fileName, $bytes);
        } catch (\Throwable $e) {
            $this->writeFailed($report, $label, 'track ' . $slot, $e);
            return; // keeps the stored row and whatever file it had
        }
        $track = $existing ?? new QuestionTrack();
        $track->setQuestionId($questionId);
        $track->setPoolId($poolId);
        $track->setKind($kind);
        $track->setLang($lang);
        $track->setFileName($fileName);
        $track->setSize(strlen($bytes));
        $track->setSha256($sha);
        $track->setTimeline($timeline);
        $track->setDurationMs($duration);
        $track->setUpdatedAt(time());
        try {
            if ($existing === null) {
                $this->trackMapper->insert($track);
            } else {
                $this->trackMapper->update($track);
            }
        } catch (\Throwable $e) {
            // An existing row keeps its old sha256, so the next run writes the track again.
            // A new file without a row is left to the orphan sweep (it may belong to a
            // parallel run whose row is about to land).
            $this->writeFailed($report, $label, 'track ' . $slot, $e);
            return;
        }
        $report[$counter . '_written']++;
        $report['bytes_written'] += strlen($bytes);
    }

    private function freeFileName(int $questionId, string $kind, string $lang): string {
        $taken = [];
        foreach ($this->trackMapper->findByQuestion($questionId) as $track) {
            $taken[$track->getFileName()] = true;
        }
        $variant = 1;
        while (isset($taken[QuestionMediaStorage::fileName($questionId, $kind, $lang, $variant)])) {
            $variant++;
        }
        return QuestionMediaStorage::fileName($questionId, $kind, $lang, $variant);
    }

    /**
     * Remove the stored tracks of one family (intro/dialog or options) the entry no longer lists.
     *
     * @param array<string, QuestionTrack> $stored by slot
     * @param array<string, true> $listed slots the entry lists
     * @param array<string, mixed> $report
     */
    private function removeUnlisted(Question $question, array $stored, array $listed, bool $dryRun, string $label, string $counter, array &$report): void {
        foreach ($stored as $slot => $track) {
            if (isset($listed[$slot])) {
                continue;
            }
            if (!$dryRun) {
                try {
                    $this->trackMapper->delete($track);
                } catch (\Throwable $e) {
                    $this->writeFailed($report, $label, 'removing track ' . $slot, $e);
                    continue;
                }
                // The row is gone; a file left behind is the sweep's.
                try {
                    $this->storage->remove($question->getPoolId(), $track->getFileName());
                } catch (\Throwable $e) {
                    $this->logger->warning('Question media: file {file} of a dropped track not removed, the sweep will: {error}', [
                        'app' => 'learning', 'file' => $track->getFileName(), 'error' => $e->getMessage(),
                    ]);
                }
            }
            $report[$counter . '_removed']++;
        }
    }

    /**
     * The answers of a question in their stored order (position, then id): option_index n of a
     * bundle is the n-th of them. Returns the stored position per index and the correctness per
     * position.
     *
     * @return array{0: array<int, int>, 1: array<int, bool>}
     */
    private function answerPositions(Question $question): array {
        $answers = $this->answerMapper->findByQuestion((int)$question->getId());
        usort($answers, static fn (Answer $a, Answer $b): int => [(int)$a->getPosition(), (int)$a->getId()] <=> [(int)$b->getPosition(), (int)$b->getId()]);
        $positionMap = [];
        $isCorrect = [];
        foreach ($answers as $index => $answer) {
            $position = $answer->getPosition() !== null ? (int)$answer->getPosition() : $index;
            $positionMap[$index] = $position;
            $isCorrect[$position] = (bool)$answer->getIsCorrect();
        }
        return [$positionMap, $isCorrect];
    }

    /**
     * @param array<mixed> $entry
     * @param array<string, mixed> $report
     */
    private function importCues(Question $question, array $entry, QuestionMediaBundle $bundle, bool $dryRun, string $label, array &$report): void {
        if (!array_key_exists('cues', $entry)) {
            return;
        }
        $questionId = (int)$question->getId();
        $stored = $this->cuesMapper->findByQuestion($questionId);
        if ($entry['cues'] === null) {
            if ($stored !== null) {
                if (!$dryRun) {
                    try {
                        $this->cuesMapper->delete($stored);
                    } catch (\Throwable $e) {
                        $this->writeFailed($report, $label, 'removing cues', $e);
                        return;
                    }
                }
                $report['cues_removed']++;
            }
            return;
        }
        try {
            $cues = $bundle->readCues($entry['cues']);
        } catch (QuestionMediaFileException $e) {
            $this->fileProblem($report, $label, $e);
            return;
        }
        $json = (string)json_encode($cues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($stored !== null && $stored->getCues() === $json) {
            $report['cues_unchanged']++;
            return;
        }
        if ($dryRun) {
            $report['cues_written']++;
            return;
        }
        $row = $stored ?? new QuestionCues();
        $row->setQuestionId($questionId);
        $row->setPoolId($question->getPoolId());
        $row->setCues($json);
        $row->setUpdatedAt(time());
        try {
            if ($stored === null) {
                $this->cuesMapper->insert($row);
            } else {
                $this->cuesMapper->update($row);
            }
        } catch (\Throwable $e) {
            $this->writeFailed($report, $label, 'cues', $e);
            return;
        }
        $report['cues_written']++;
    }

    /**
     * hint, background_fact, key_takeaway and feedback_per_option of the entry onto the question.
     * The answer options are taken in their stored order (position): option_index 0 is the first
     * of them, and a letter given beside it must be the matching one (A = 0). Duplicate option
     * texts and fewer than four options are therefore no problem; an entry that names an option
     * the question does not have, or whose letter contradicts its index, is reported and skipped.
     *
     * @param array<mixed> $entry
     * @param array<string, mixed> $report
     */
    private function importFeedback(Question $question, array $entry, bool $dryRun, string $label, array &$report): void {
        [$positionMap, $isCorrect] = $this->answerPositions($question);
        $optionCount = count($positionMap);

        $item = ['lang' => $question->getLang() ?: null];
        foreach (['hint', 'hinweis', 'background_fact', 'backgroundFact', 'background', 'hintergrund', 'key_takeaway', 'keyTakeaway', 'takeaway', 'merksatz'] as $key) {
            if (array_key_exists($key, $entry)) {
                $item[$key] = $entry[$key];
            }
        }
        $list = $entry['feedback_per_option'] ?? $entry['option_feedback'] ?? null;
        if (is_array($list)) {
            $kept = [];
            foreach (array_values($list) as $n => $option) {
                if (!is_array($option)) {
                    continue;
                }
                $optionIndex = $option['option_index'] ?? null;
                if (!is_int($optionIndex) || $optionIndex < 0) {
                    $report['feedback_warnings'][] = $label . ': feedback entry ' . ($n + 1) . ' has no option_index';
                    continue;
                }
                $letter = $option['letter'] ?? null;
                if ($letter !== null && (!is_string($letter) || strtoupper(trim($letter)) !== ($optionIndex < 26 ? chr(65 + $optionIndex) : ''))) {
                    $report['feedback_warnings'][] = $label . ': option_index ' . $optionIndex . ' does not match letter ' . (is_string($letter) ? $letter : '?') . ', skipped';
                    continue;
                }
                if ($optionIndex >= $optionCount) {
                    $report['feedback_warnings'][] = $label . ': option_index ' . $optionIndex . ' but the question has ' . $optionCount . ' options, skipped';
                    continue;
                }
                $kept[] = $option;
            }
            $item['feedback_per_option'] = $kept;
        }

        // fromItem() reads is_correct by position; positions are 0..n-1 unless an old import left gaps.
        $correctByPosition = [];
        if ($isCorrect !== []) {
            for ($p = 0, $max = max(array_keys($isCorrect)); $p <= $max; $p++) {
                $correctByPosition[] = $isCorrect[$p] ?? false;
            }
        }
        $parsed = RichFeedbackFormat::fromItem($item, $positionMap, $correctByPosition);
        array_push($report['dropped_languages'], ...$parsed['dropped_languages']);
        if ($parsed['verdict_mismatches'] > 0) {
            $report['feedback_warnings'][] = $label . ': ' . $parsed['verdict_mismatches'] . ' verdict(s) contradict the answer key; the answer key wins';
        }

        $changes = [];
        foreach (['hint', 'background_fact', 'key_takeaway'] as $field) {
            if ($parsed[$field] !== null) {
                $changes[$field] = $parsed[$field];
            }
        }
        if (is_array($list) && $parsed['option_feedback'] !== []) {
            $changes['option_feedback'] = $parsed['option_feedback'];
        }
        if ($changes === []) {
            return;
        }
        try {
            $changes = RichFeedbackFormat::normalize($changes);
        } catch (\InvalidArgumentException $e) {
            $report['feedback_warnings'][] = $label . ': a feedback text is too long, the texts of this question are not imported';
            return;
        }

        $current = [
            'hint' => $question->getHint(),
            'background_fact' => $question->getBackgroundFact(),
            'key_takeaway' => $question->getKeyTakeaway(),
            'option_feedback' => $question->getOptionFeedback(),
        ];
        $next = $current;
        foreach ($changes as $field => $value) {
            $next[$field] = $field === 'option_feedback' ? RichFeedbackFormat::encodeOptions($value) : $value;
        }
        if ($next === $current) {
            $report['feedback_unchanged']++;
            return;
        }
        if ($dryRun) {
            $report['feedback_updated']++;
            return;
        }
        $question->setHint($next['hint']);
        $question->setBackgroundFact($next['background_fact']);
        $question->setKeyTakeaway($next['key_takeaway']);
        $question->setOptionFeedback($next['option_feedback']);
        $question->setUpdatedAt(time());
        try {
            $this->questionMapper->update($question);
        } catch (\Throwable $e) {
            $this->writeFailed($report, $label, 'feedback texts', $e);
            return;
        }
        $report['feedback_updated']++;
    }

    /**
     * A storage or database write that failed for one entry (disk full, quota, a unique violation
     * from a parallel run): reported, and the import goes on with the next part — so the report
     * says what was written and what was not, instead of an occ run that ends in a stack trace.
     *
     * @param array<string, mixed> $report
     */
    private function writeFailed(array &$report, string $label, string $what, \Throwable $e): void {
        $report['write_failed'][] = $label . ': ' . $what . ' (' . (new \ReflectionClass($e))->getShortName() . ': ' . $e->getMessage() . ')';
    }

    /** @param array<string, mixed> $report */
    private function fileProblem(array &$report, string $label, QuestionMediaFileException $e): void {
        if ($e->reason === QuestionMediaBundle::E_MISSING) {
            $report['missing_files'][] = $label . ': ' . $e->path;
        } else {
            $report['refused_files'][] = $label . ': ' . $e->path . ' (' . $e->reason . ')';
        }
    }
}
