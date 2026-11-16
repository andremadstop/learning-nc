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

/**
 * Media per question (GitHub #66): the import of a learning-question-media/1 bundle and the rules
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
 *
 * IMPORT (importBundle()). Questions are matched by exam_key inside the pool, never by text. Per
 * matched question the manifest is the truth for its media: tracks it lists are written (a file
 * with the same sha256 and the same timeline is left alone, so a second run changes nothing),
 * tracks it no longer lists are removed, its cues replace the stored ones. A listed file that is
 * missing or refused is reported and keeps whatever was stored before. The text fields (hint,
 * background_fact, key_takeaway/merksatz, feedback_per_option) go through RichFeedbackFormat like
 * every other import; a field the entry does not carry keeps its stored value.
 */
class QuestionMediaService {
    public const GRANT_TTL = 43200; // 12 h: a learner who answered can listen to the dialog the same day
    private const GRANT_CONTEXT = 'learning-qmedia-grant-v1';
    private const LANG_PATTERN = '/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/D';

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
    ) {
    }

    // ------------------------------------------------------------------ delivery

    /**
     * What the player may load for a question before (or without) answering. Editors get every
     * track and the cues; learners the intro tracks.
     *
     * @return array{question_id: int, tracks: list<array<string, mixed>>, cues?: array<string, mixed>|null}
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
        foreach ($this->trackMapper->findByQuestion($questionId) as $track) {
            if ($editor || $track->getKind() === 'intro') {
                $tracks[] = $this->trackPayload($track, null);
            }
        }
        $out = ['question_id' => $questionId, 'tracks' => $tracks];
        if ($editor) {
            $out['cues'] = $this->cuesOf($questionId);
        }
        return $out;
    }

    /**
     * The post-answer media of a question for an answer response: the cues and the dialog
     * tracks with grant URLs, or [] when it has none. Only for responses that already reveal the
     * solution (the callers' rule, as for the rich feedback).
     *
     * @return array<string, mixed>
     */
    public function revealFor(int $questionId, string $userId): array {
        $dialog = array_values(array_filter(
            $this->trackMapper->findByQuestion($questionId),
            static fn (QuestionTrack $t): bool => $t->getKind() === 'dialog'
        ));
        $cues = $this->cuesOf($questionId);
        if ($dialog === [] && $cues === null) {
            return [];
        }
        $expires = time() + self::GRANT_TTL;
        $grant = $this->grant($userId, $questionId, $expires);
        return ['question_media' => [
            'tracks' => array_map(fn (QuestionTrack $t): array => $this->trackPayload($t, ['expires' => $expires, 'grant' => $grant]), $dialog),
            'cues' => $cues,
        ]];
    }

    /**
     * A track and its bytes for the audio endpoint, after every check.
     *
     * @return array{track: QuestionTrack, bytes: string}
     * @throws NotFoundException unknown track, no access to its pool, or the file is gone
     * @throws ForbiddenException exam running, or a dialog without a valid grant
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
            'tracks_written' => 0,
            'tracks_unchanged' => 0,
            'tracks_removed' => 0,
            'cues_written' => 0,
            'cues_unchanged' => 0,
            'cues_removed' => 0,
            'feedback_updated' => 0,
            'feedback_unchanged' => 0,
            'feedback_warnings' => [],
            'dropped_languages' => [],
            'bytes_written' => 0,
            'pool_questions' => array_sum(array_map('count', $byKey)),
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
            $this->importCues($question, $entry, $bundle, $dryRun, $label, $report);
            $this->importFeedback($question, $entry, $dryRun, $label, $report);
        }

        $report['dropped_languages'] = array_count_values($report['dropped_languages']);
        ksort($report['dropped_languages']);
        return $report;
    }

    /**
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
        $questionId = (int)$question->getId();
        $poolId = $question->getPoolId();
        $stored = [];
        foreach ($this->trackMapper->findByQuestion($questionId) as $track) {
            $stored[$track->getKind() . '-' . $track->getLang()] = $track;
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
            $timeline = null;
            $duration = null;
            if (array_key_exists('timeline', $t) && $t['timeline'] !== null) {
                try {
                    $lines = $bundle->readTimeline($t['timeline']);
                    $timeline = json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
                    $ends = array_column($lines, 'end');
                    $duration = $ends === [] ? null : (int)round(max($ends) * 1000);
                } catch (QuestionMediaFileException $e) {
                    $this->fileProblem($report, $label, $e);
                    continue;
                }
            }
            $sha = hash('sha256', $bytes);
            $existing = $stored[$slot] ?? null;
            if ($existing !== null && $existing->getSha256() === $sha && $existing->getTimeline() === $timeline) {
                $report['tracks_unchanged']++;
                continue;
            }
            $report['tracks_written']++;
            $report['bytes_written'] += strlen($bytes);
            if ($dryRun) {
                continue;
            }
            $fileName = QuestionMediaStorage::fileName($questionId, $kind, $lang);
            $this->storage->put($poolId, $fileName, $bytes);
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
            if ($existing === null) {
                $this->trackMapper->insert($track);
            } else {
                $this->trackMapper->update($track);
            }
        }

        foreach ($stored as $slot => $track) {
            if (isset($listed[$slot])) {
                continue;
            }
            $report['tracks_removed']++;
            if (!$dryRun) {
                $this->trackMapper->delete($track);
                $this->storage->remove($poolId, $track->getFileName());
            }
        }
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
                $report['cues_removed']++;
                if (!$dryRun) {
                    $this->cuesMapper->delete($stored);
                }
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
        $report['cues_written']++;
        if ($dryRun) {
            return;
        }
        $row = $stored ?? new QuestionCues();
        $row->setQuestionId($questionId);
        $row->setPoolId($question->getPoolId());
        $row->setCues($json);
        $row->setUpdatedAt(time());
        if ($stored === null) {
            $this->cuesMapper->insert($row);
        } else {
            $this->cuesMapper->update($row);
        }
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
        $answers = $this->answerMapper->findByQuestion((int)$question->getId());
        usort($answers, static fn (Answer $a, Answer $b): int => [(int)$a->getPosition(), (int)$a->getId()] <=> [(int)$b->getPosition(), (int)$b->getId()]);
        $positionMap = [];
        $isCorrect = [];
        foreach ($answers as $index => $answer) {
            $position = $answer->getPosition() !== null ? (int)$answer->getPosition() : $index;
            $positionMap[$index] = $position;
            $isCorrect[$position] = (bool)$answer->getIsCorrect();
        }

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
                if ($optionIndex >= count($answers)) {
                    $report['feedback_warnings'][] = $label . ': option_index ' . $optionIndex . ' but the question has ' . count($answers) . ' options, skipped';
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
        $report['feedback_updated']++;
        if ($dryRun) {
            return;
        }
        $question->setHint($next['hint']);
        $question->setBackgroundFact($next['background_fact']);
        $question->setKeyTakeaway($next['key_takeaway']);
        $question->setOptionFeedback($next['option_feedback']);
        $question->setUpdatedAt(time());
        $this->questionMapper->update($question);
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
