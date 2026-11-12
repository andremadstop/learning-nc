<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Db\Question;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Db\Answer;
use OCA\Learning\Db\AnswerMapper;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\PoolShareMapper;
use OCA\Learning\Service\PoolJsonFormat;
use OCA\Learning\Service\QuestionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IRequest;

class ImportController extends Controller {
    /** Questions per import (CSV records after the header, JSON items). */
    private const MAX_ITEMS = 500;
    /** Records parseCsv() reads at most: a header plus MAX_ITEMS, plus one to see "too many". */
    private const CSV_MAX_RECORDS = self::MAX_ITEMS + 2;
    /** Answers a question keeps; more are cut off (JSON) or refused (CSV). */
    private const MAX_ANSWERS = 8;
    /** Answers a JSON item may list at all before it is refused instead of cut to MAX_ANSWERS. */
    private const MAX_RAW_ANSWERS = 50;
    private const MAX_ANSWER_LENGTH = 2000;

    private QuestionMapper $questionMapper;
    private AnswerMapper $answerMapper;
    private PoolMapper $poolMapper;
    private PoolShareMapper $shareMapper;
    private IConfig $config;
    private IDBConnection $db;
    private ?string $userId;
    private IL10N $l10n;

    public function __construct(
        string $appName,
        IRequest $request,
        QuestionMapper $questionMapper,
        AnswerMapper $answerMapper,
        PoolMapper $poolMapper,
        PoolShareMapper $shareMapper,
        IConfig $config,
        IDBConnection $db,
        ?string $userId,
        IL10N $l10n
    ) {
        parent::__construct($appName, $request);
        $this->questionMapper = $questionMapper;
        $this->answerMapper = $answerMapper;
        $this->poolMapper = $poolMapper;
        $this->shareMapper = $shareMapper;
        $this->config = $config;
        $this->db = $db;
        $this->userId = $userId;
        $this->l10n = $l10n;
    }

    private function getMaxImportBytes(): int {
        $maxMb = (int)$this->config->getAppValue('learning', 'max_import_size_mb', '2');
        $maxMb = max(1, min(10, $maxMb));
        return $maxMb * 1024 * 1024;
    }

    private function canEditPool(int $poolId): bool {
        try {
            $this->poolMapper->find($poolId, $this->userId);
            return true;
        } catch (DoesNotExistException $e) {
            $share = $this->shareMapper->findByPoolAndUser($poolId, $this->userId);
            return $share !== null && $share->getPermission() === 'edit';
        }
    }

    /**
     * Strip UTF-8 BOM and normalize encoding.
     */
    private function normalizeText(string $text): string {
        // Strip UTF-8 BOM
        if (substr($text, 0, 3) === "\xEF\xBB\xBF") {
            $text = substr($text, 3);
        }
        // Try to fix Latin1/Windows-1252 → UTF-8
        if (!mb_check_encoding($text, 'UTF-8')) {
            $converted = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
            if ($converted !== false) {
                $text = $converted;
            }
        }
        return $text;
    }

    /**
     * Split CSV text into records of fields (RFC 4180): a field in double quotes may contain the
     * delimiter, line breaks and doubled quotes (""). Line breaks inside a field arrive as "\n".
     * Blank lines are skipped. There is no backslash escape (escape ''), so a field that ends in a
     * backslash ("C:\") stays intact.
     *
     * The importer used to split the text at every "\n" before parsing, which tore a quoted
     * multi-line question into several broken lines (GitHub #34).
     *
     * Reading stops after $limit records, so an oversized file is refused without first
     * holding every record of it in memory.
     *
     * @return list<list<string>>
     */
    private static function csvRecords(string $text, string $delimiter, int $limit = PHP_INT_MAX): array {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return [];
        }
        fwrite($stream, str_replace(["\r\n", "\r"], "\n", $text));
        rewind($stream);
        $records = [];
        while (count($records) < $limit && ($fields = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            if (count($fields) === 1 && trim((string)$fields[0]) === '') {
                continue; // blank line (fgetcsv returns [null]) or only spaces
            }
            $records[] = array_map(static fn ($f): string => (string)$f, $fields);
        }
        fclose($stream);
        return $records;
    }

    /** First record that names the columns (question/answer or Frage/Antwort), not a question. */
    private static function isCsvHeader(array $fields): bool {
        $line = strtolower(implode(' ', $fields));
        return (str_contains($line, 'question') && str_contains($line, 'answer'))
            || (str_contains($line, 'frage') && str_contains($line, 'antwort'));
    }

    /**
     * Parse CSV with semicolon or comma, whichever splits the first data record into more fields
     * (a tie means comma) — the old rule "more ; than , in the first data line", but counted
     * outside quotes, so commas inside a quoted question no longer decide.
     *
     * The delimiter is decided on the first two records only, and the text is then parsed once,
     * up to CSV_MAX_RECORDS records: more than that is refused anyway (importCsv()).
     *
     * @return list<list<string>>
     */
    private static function parseCsv(string $text): array {
        $comma = self::csvRecords($text, ',', 2);
        $semicolon = self::csvRecords($text, ';', 2);
        $first = isset($comma[0]) && self::isCsvHeader($comma[0]) ? 1 : 0;
        $delimiter = count($semicolon[$first] ?? []) > count($comma[$first] ?? []) ? ';' : ',';
        return self::csvRecords($text, $delimiter, self::CSV_MAX_RECORDS);
    }

    /**
     * Text of a scalar JSON value: strings, integers and floats become trimmed text
     * ("difficulty": 3 → "3"); null, booleans, arrays and objects become ''. The file is
     * declare(strict_types=1), so trim() on an int threw a TypeError that turned a whole file
     * into a 500 (GitHub #34).
     */
    private static function scalarText(mixed $value): string {
        return PoolJsonFormat::scalarText($value);
    }

    private static function optionalText(mixed $value): ?string {
        $text = self::scalarText($value);
        return $text === '' ? null : $text;
    }

    /**
     * Question type: type > question_type > typ, lower-cased. `question_type` is the key of the
     * documented pool format (docs/import-pool-workflow.md) and of the dialog's own help text.
     */
    private static function typeFrom(array $item): ?string {
        $type = strtolower(self::scalarText($item['type'] ?? $item['question_type'] ?? $item['typ'] ?? null));
        if ($type === '') {
            return null;
        }
        return $type === 'true_false' ? 'single' : $type; // true_false removed in v3.7.0 — normalize on import
    }

    /**
     * Question text of a JSON item: text > frage > question > question_text for a multiple-choice
     * or open question; a PBQ reads question_text first (its own documented key), then the same
     * keys. Both paths accept the same set, so a key that works for one works for the other.
     */
    private static function questionTextFrom(array $item, bool $pbq = false): string {
        $keys = $pbq ? ['question_text', 'text', 'frage', 'question'] : ['text', 'frage', 'question', 'question_text'];
        foreach ($keys as $key) {
            if (isset($item[$key])) {
                return self::scalarText($item[$key]);
            }
        }
        return '';
    }

    /**
     * The item's own answer list under any accepted key (answers > antworten > options > choices),
     * as given in the file; null when there is none.
     */
    private static function rawAnswersFrom(array $item): mixed {
        return $item['answers'] ?? $item['antworten'] ?? $item['options'] ?? $item['choices'] ?? null;
    }

    /** Correct flag of one answer object (is_correct > korrekt > correct > richtig). */
    private static function answerFlag(array $answer): bool {
        return filter_var($answer['is_correct'] ?? $answer['korrekt'] ?? $answer['correct'] ?? $answer['richtig'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Whether the item marks several answers correct — more than one answer object flagged true
     * (blank answers do not count), or a question-level marker list with more than one distinct
     * value. That is a multiple-choice question, so `_meta.default_question_type` does not
     * apply to it — whichever type the default names, pbq included (the same rule as
     * ImportPoolJsonCommand::resolveQuestionType()).
     */
    private static function marksSeveralCorrect(array $item): bool {
        $raw = self::rawAnswersFrom($item);
        $flagged = 0;
        foreach (is_array($raw) ? $raw : [] as $a) {
            if (is_array($a) && self::scalarText($a['text'] ?? null) !== '' && self::answerFlag($a)) {
                $flagged++;
            }
        }
        if ($flagged > 1) {
            return true;
        }
        [$marker] = self::correctMarkerFrom($item);
        return is_array($marker) && count(array_unique(array_map(static fn ($v): string => self::scalarText($v), $marker))) > 1;
    }

    /**
     * Normalize a JSON question item from various formats to our canonical format.
     *
     * Question text: see questionTextFrom(). Explanation: explanation > erklaerung.
     * Answers: answers > antworten > options > choices — each entry either an object
     * ({"text": …, "is_correct"|"korrekt"|"correct"|"richtig": bool}) or a plain string/number.
     * A keyed object ({"A": "…", "B": "…"}) is read in order; its keys become answer labels.
     * A list of more than MAX_RAW_ANSWERS entries is refused (it would only be cut to
     * MAX_ANSWERS anyway, and matching markers against it is work per entry).
     *
     * Which answers are correct, when no answer object carries a true flag, comes from the
     * question itself (see resolveCorrectMarker()): correct > correct_answer > answer >
     * correct_index (0-based) or correct_number (1-based). Per-answer flags win: if any
     * answer is flagged true, the question-level marker is not read.
     *
     * $defaultType is the file's `_meta.default_question_type`: it applies only to an item
     * without its own type, and not when the item marks several answers correct (that is a
     * multiple-choice question) — the same order as ImportPoolJsonCommand::resolveQuestionType().
     *
     * Returns null and appends a message to $errors when the item cannot be imported.
     */
    private function normalizeJsonItem(array $item, int $num, array &$errors, ?string $defaultType = null): ?array {
        $text = self::questionTextFrom($item);
        if ($text === '') {
            $errors[] = $this->l10n->t('Item %s: Missing question text', [$num]);
            return null;
        }

        $explanation = self::optionalText($item['explanation'] ?? $item['erklaerung'] ?? null);
        $difficulty = PoolJsonFormat::difficulty($item);
        $type = self::typeFrom($item);

        $rawAnswers = self::rawAnswersFrom($item) ?? [];
        if (!is_array($rawAnswers)) {
            $errors[] = $this->l10n->t('Item %s: Answers must be a list', [$num]);
            return null;
        }
        if (count($rawAnswers) > self::MAX_RAW_ANSWERS) {
            $errors[] = $this->l10n->t('Item %1$s: More than %2$s answers — question skipped', [$num, self::MAX_RAW_ANSWERS]);
            return null;
        }

        $keyed = !array_is_list($rawAnswers);
        $answers = [];
        $positions = []; // position in the file's list → index in $answers (blank answers are dropped)
        $labels = [];    // upper-cased key of a keyed options object → index in $answers
        $byText = [];    // lower-cased answer text → index of its first occurrence in $answers
        $flagged = false;
        $position = 0;
        foreach ($rawAnswers as $key => $a) {
            $pos = $position++;
            if (is_array($a)) {
                $aText = self::scalarText($a['text'] ?? null);
                $isCorrect = self::answerFlag($a);
            } else {
                $aText = self::scalarText($a);
                $isCorrect = false;
            }
            if ($aText === '') {
                continue;
            }
            $positions[$pos] = count($answers);
            // json_decode turns {"1": …, "2": …} into int keys; they are labels all the same.
            if ($keyed && trim((string)$key) !== '') {
                $labels[mb_strtoupper(trim((string)$key))] = count($answers);
            }
            $byText[mb_strtolower($aText)] ??= count($answers);
            $flagged = $flagged || $isCorrect;
            $answers[] = [
                'text' => $aText,
                'is_correct' => $isCorrect,
            ];
        }

        [$marker, $oneBased] = self::correctMarkerFrom($item);

        if ($type === null && $defaultType !== null && !self::marksSeveralCorrect($item)) {
            $type = $defaultType;
        }

        // A type the app does not know skips the item instead of silently becoming single or
        // multi — the same rule as the pool import (GitHub #34).
        if ($type !== null && !in_array($type, QuestionService::QUESTION_TYPES, true)) {
            $errors[] = $this->l10n->t('Item %1$s: Unknown question type \'%2$s\' (valid: %3$s) — question skipped', [
                $num, mb_substr($type, 0, 80), implode(', ', QuestionService::QUESTION_TYPES),
            ]);
            return null;
        }

        // Open question without an answer list: the model answer may be given as "answer": "…".
        if ($type === 'open' && $answers === [] && self::scalarText($marker) !== '') {
            $answers[] = ['text' => self::scalarText($marker), 'is_correct' => true];
            $marker = null;
        }

        if (!$flagged && $marker !== null && $type !== 'open') {
            $correct = self::resolveCorrectMarker($marker, $oneBased, count($answers), $positions, $labels, $byText);
            if (is_string($correct)) {
                $shown = mb_substr(is_array($marker) ? implode(', ', array_map(static fn ($v): string => self::scalarText($v), $marker)) : self::scalarText($marker), 0, 80);
                $errors[] = match ($correct) {
                    'duplicate' => $this->l10n->t('Item %1$s: Correct answer "%2$s" names the same answer twice — list each correct answer once (a 0/1 list per answer is not supported)', [$num, $shown]),
                    'too_many' => $this->l10n->t('Item %1$s: Correct answer "%2$s" names more answers than the question has', [$num, $shown]),
                    default => $this->l10n->t('Item %1$s: Correct answer "%2$s" matches no answer — numbers count from 0 (0 = first answer, or use correct_number to count from 1), letters from A, or give the exact answer text', [$num, $shown]),
                };
                return null;
            }
            foreach ($correct as $i) {
                $answers[$i]['is_correct'] = true;
            }
        }

        return [
            'text' => $text,
            'answers' => $answers,
            'explanation' => $explanation,
            'difficulty' => $difficulty,
            'type' => $type,
        ] + self::metadataFrom($item);
    }

    /**
     * Metadata fields of a question item under any accepted key (PoolJsonFormat aliases),
     * in the canonical keys applyQuestionMetadata() reads.
     *
     * @return array<string, mixed>
     */
    private static function metadataFrom(array $item): array {
        return [
            'handbook_key' => PoolJsonFormat::fieldText($item, 'handbook_key'),
            'handbook_title' => PoolJsonFormat::fieldText($item, 'handbook_title'),
            'exam_key' => PoolJsonFormat::fieldText($item, 'exam_key'),
            'chapter_key' => PoolJsonFormat::fieldText($item, 'chapter_key'),
            'chapter_title' => PoolJsonFormat::fieldText($item, 'chapter_title'),
            'chapter_order' => PoolJsonFormat::chapterOrder($item),
            'scenario' => self::scenarioFrom($item),
        ];
    }

    /**
     * The question-level "which answer is correct" value, if any, and whether its numbers count
     * from 1. Booleans are ignored ("correct": true on a question names no answer).
     *
     * @return array{0: mixed, 1: bool}
     */
    private static function correctMarkerFrom(array $item): array {
        foreach (['correct', 'correct_answer', 'answer', 'correct_index'] as $key) {
            if (isset($item[$key]) && !is_bool($item[$key])) {
                return [$item[$key], false];
            }
        }
        if (isset($item['correct_number']) && !is_bool($item['correct_number'])) {
            return [$item['correct_number'], true];
        }
        return [null, false];
    }

    /**
     * Resolve a question-level correct marker to answer indices. An array names several correct
     * answers (multiple choice). Returns the reason instead when it cannot be used:
     *  - 'unmatched': a value matches no answer;
     *  - 'too_many':  the list has more entries than the question has answers;
     *  - 'duplicate': two values name the same answer — e.g. a 0/1 mask per answer
     *    ([1, 0, 1, 0]), which used to be read as "answers 1 and 0" and marked a and b.
     *
     * Each value is matched in this order, first hit wins:
     *  1. exact answer text (trimmed, case-insensitive) — first, because "A" or "2" can be a
     *     real answer text, and reading it as a letter or index would mark the wrong answer;
     *  2. the key of a keyed options object ({"A": "…", "B": "…"} or {"1": "…", "2": "…"}) —
     *     a JSON number is matched against these keys before it is read as a position;
     *  3. a single letter A, B, C … (also "B)", "(B)", "B." or "b") — A is the first answer;
     *  4. a whole number (JSON number or digit-only string) as a position in the file's list.
     *
     * Numbers are 0-based (0 = first answer) under correct / correct_answer / answer /
     * correct_index, and 1-based only under correct_number. There is deliberately no guessing
     * (such as "no 0 present and the maximum equals the count, so it must be 1-based"): a guess
     * flips silently between files, and an off-by-one import marks a wrong answer as correct
     * without any error. 0-based is the convention of JSON arrays and of what AI tools emit for
     * "options + answer index"; the name correct_number matches the 1-based correct_number of
     * the CSV format. An index past the end is an error that names the rule.
     *
     * The indices refer to the full answer list; importJson() refuses the item when one of them
     * lies past MAX_ANSWERS, where the list is cut.
     *
     * @param array<int, int> $positions position in the file's list → index in $answers
     * @param array<string, int> $labels upper-cased option key → index in $answers
     * @param array<string, int> $byText lower-cased answer text → index in $answers
     * @return list<int>|'unmatched'|'too_many'|'duplicate'
     */
    private static function resolveCorrectMarker(mixed $marker, bool $oneBased, int $answerCount, array $positions, array $labels, array $byText): array|string {
        $values = is_array($marker) ? array_values($marker) : [$marker];
        if ($values === []) {
            return 'unmatched';
        }
        if (count($values) > $answerCount) {
            return 'too_many';
        }
        $result = [];
        foreach ($values as $value) {
            $index = self::resolveOneMarker($value, $oneBased, $positions, $labels, $byText);
            if ($index === null) {
                return 'unmatched';
            }
            if (in_array($index, $result, true)) {
                return 'duplicate';
            }
            $result[] = $index;
        }
        return $result;
    }

    /**
     * @param array<int, int> $positions
     * @param array<string, int> $labels
     * @param array<string, int> $byText
     */
    private static function resolveOneMarker(mixed $value, bool $oneBased, array $positions, array $labels, array $byText): ?int {
        if (is_float($value) && floor($value) === $value) {
            $value = (int)$value;
        }
        if (is_int($value)) {
            // Keyed options ({"1": "a", "2": "b"}): a number that is a key names that option.
            return $labels[(string)$value] ?? $positions[$oneBased ? $value - 1 : $value] ?? null;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $needle = mb_strtolower(trim($value));
        if (isset($byText[$needle])) {
            return $byText[$needle];
        }
        $upper = mb_strtoupper(trim($value));
        if (isset($labels[$upper])) {
            return $labels[$upper];
        }
        if (preg_match('/^\(?([A-Z])[).:]?$/', $upper, $m) === 1) {
            return $positions[ord($m[1]) - ord('A')] ?? null;
        }
        if (ctype_digit(trim($value))) {
            $n = (int)trim($value);
            return $positions[$oneBased ? $n - 1 : $n] ?? null;
        }
        return null;
    }

    /**
     * Turn a decoded JSON document into the list of question items, or return an error message.
     *
     * Accepted: a bare list; {questions|fragen|items: [...]}; the documented pool format
     * {_meta, pools: [{…, questions: [...]}]} with exactly one pool (the dialog imports into one
     * existing pool, so several pools are refused, never merged); a single question object.
     *
     * Returns the list and the pool object it came from ([] when the file has no pool), whose
     * chapter fields fill the questions (PoolJsonFormat::applyPoolDefaults()).
     *
     * @return array{0: list<mixed>, 1: array<mixed>}|string
     */
    private function questionListFrom(array $data): array|string {
        $pool = [];
        $list = null;
        foreach (['questions', 'fragen', 'items'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $list = $data[$key];
                break;
            }
        }
        if ($list === null && isset($data['pools']) && is_array($data['pools'])) {
            $count = count($data['pools']);
            if ($count === 0) {
                return $this->l10n->t('The file contains no pools — expected a list of questions');
            }
            if ($count > 1) {
                return $this->l10n->t('The file contains %s pools, but this dialog imports into one pool. Import them one at a time, or create all pools at once with "occ learning:import-pool-json"', [$count]);
            }
            $pool = reset($data['pools']);
            if (!is_array($pool) || !isset($pool['questions']) || !is_array($pool['questions'])) {
                return $this->l10n->t('Expected a list of questions');
            }
            $list = $pool['questions'];
        }
        if ($list === null && !array_is_list($data)) {
            foreach (['text', 'question', 'frage', 'question_text'] as $key) {
                if (isset($data[$key])) {
                    $list = [$data];
                    break;
                }
            }
        }
        $list ??= $data;
        if (!array_is_list($list)) {
            return $this->l10n->t('Expected a list of questions');
        }
        if ($list === []) {
            return $this->l10n->t('No questions found in the JSON');
        }
        return [$list, $pool];
    }

    /**
     * Scenario / info text shown before the question (Codeberg #10).
     * Accepts scenario > szenario > context > info; keeps line breaks.
     * Never truncated: callers reject an item whose scenario is too long (scenarioTooLong()),
     * because a cut-off case can drop exactly the fact the question depends on.
     */
    private static function scenarioFrom(array $item): ?string {
        $raw = $item['scenario'] ?? $item['szenario'] ?? $item['context'] ?? $item['info'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        return trim($raw);
    }

    private function scenarioTooLong(array $item, int $num, array &$errors): bool {
        $scenario = self::scenarioFrom($item);
        if ($scenario === null || mb_strlen($scenario) <= QuestionService::SCENARIO_MAX_LENGTH) {
            return false;
        }
        $errors[] = $this->l10n->t('Item %1$s: Scenario longer than %2$s characters — question skipped', [$num, QuestionService::SCENARIO_MAX_LENGTH]);
        return true;
    }

    private function applyQuestionMetadata(Question $question, array $item): void {
        // scalarText, not (string): an array here was "Array to string conversion" plus the text "Array".
        $limited = static function (mixed $value, int $max): ?string {
            $text = self::scalarText($value);
            return $text === '' ? null : mb_substr($text, 0, $max);
        };
        $question->setHandbookKey($limited($item['handbook_key'] ?? null, 64));
        $question->setHandbookTitle($limited($item['handbook_title'] ?? null, 255));
        $question->setExamKey($limited($item['exam_key'] ?? null, 64));
        $question->setChapterKey($limited($item['chapter_key'] ?? null, 64));
        $question->setChapterTitle($limited($item['chapter_title'] ?? null, 255));
        // Valid only from 1 to 9999; outside it is dropped, not a failed insert (one rule for all importers).
        $question->setChapterOrder(PoolJsonFormat::chapterOrder($item));
        $question->setScenario(self::scenarioFrom($item));
    }

    /**
     * Extract optional import metadata from JSON wrapper.
     */
    private function extractImportMeta(array $data): array {
        if (!isset($data['_meta']) || !is_array($data['_meta'])) {
            return [];
        }
        $meta = [];
        if (isset($data['_meta']['source'])) {
            $source = self::scalarText($data['_meta']['source']);
            if ($source !== '') {
                $meta['source'] = mb_substr($source, 0, 255);
            }
        }
        if (isset($data['_meta']['license'])) {
            $license = self::scalarText($data['_meta']['license']);
            if ($license !== '') {
                $meta['license'] = mb_substr($license, 0, 120);
            }
        }
        return $meta;
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 5, period: 60)]
    public function importCsv(int $poolId, string $csvData): DataResponse {
        if (!$this->canEditPool($poolId)) {
            return new DataResponse(['error' => $this->l10n->t('No edit access to this pool')], Http::STATUS_FORBIDDEN);
        }

        $csvData = $this->normalizeText($csvData);

        $maxBytes = $this->getMaxImportBytes();
        if (strlen($csvData) > $maxBytes) {
            return new DataResponse(['error' => $this->l10n->t('CSV data too large (max %s MB)', [(int)($maxBytes / 1024 / 1024)])], Http::STATUS_BAD_REQUEST);
        }

        // Records, not physical lines: "Line N" in the messages and the 500 limit count questions.
        $lines = self::parseCsv($csvData);
        if (empty($lines)) {
            return new DataResponse(['error' => $this->l10n->t('No data to import')], Http::STATUS_BAD_REQUEST);
        }

        // The header is not a question and does not count toward the limit.
        if (self::isCsvHeader($lines[0])) {
            array_shift($lines);
        }

        if (count($lines) > self::MAX_ITEMS) {
            return new DataResponse(['error' => $this->l10n->t('Maximum 500 items per import')], Http::STATUS_BAD_REQUEST);
        }

        $imported = 0;
        $errors = [];
        $warnings = [];

        $this->db->beginTransaction();
        try {
            foreach ($lines as $lineNum => $fields) {
                $line = $lineNum + 1;

                $questionText = trim($fields[0] ?? '');
                if (empty($questionText)) {
                    $errors[] = $this->l10n->t('Line %s: Empty question text', [$line]);
                    continue;
                }

                if (mb_strlen($questionText) > 5000) {
                    $errors[] = $this->l10n->t('Line %s: Question text too long (max 5000)', [$line]);
                    continue;
                }

                // Open-question format: question,model_answer,open (3 fields)
                if (count($fields) === 3 && mb_strtolower(trim($fields[2])) === 'open') {
                    $modelAnswer = trim($fields[1]);
                    if ($modelAnswer === '') {
                        $errors[] = $this->l10n->t('Line %s: Empty model answer for open question', [$line]);
                        continue;
                    }
                    if (mb_strlen($modelAnswer) > self::MAX_ANSWER_LENGTH) {
                        $modelAnswer = mb_substr($modelAnswer, 0, self::MAX_ANSWER_LENGTH);
                        $warnings[] = $this->l10n->t('Line %s: Answer text truncated to 2000 chars', [$line]);
                    }
                    $question = new Question();
                    $question->setPoolId($poolId);
                    $question->setUserId($this->userId);
                    $question->setText($questionText);
                    $question->setQuestionType('open');
                    $question = $this->questionMapper->createOrUpdate($question);

                    $answer = new Answer();
                    $answer->setQuestionId($question->getId());
                    $answer->setText($modelAnswer);
                    $answer->setIsCorrect(true);
                    $answer->setPosition(0);
                    $this->answerMapper->createOrUpdate($answer);

                    $imported++;
                    continue;
                }

                // MC format: minimum question + 2 answers + correct index
                if (count($fields) < 4) {
                    $errors[] = $this->l10n->t('Line %s: Not enough fields (need: question, answers, correct number)', [$line]);
                    continue;
                }

                $answerTexts = [];
                $correctIndex = null;
                $explanation = null;

                $lastIdx = count($fields) - 1;
                $secondLastIdx = $lastIdx - 1;

                if ($lastIdx >= 2) {
                    $possibleCorrect = trim($fields[$lastIdx]);
                    $answerCount = $lastIdx - 1; // fields between question and last field

                    if (is_numeric($possibleCorrect) && (int)$possibleCorrect >= 1 && (int)$possibleCorrect <= 8 && (int)$possibleCorrect <= $answerCount) {
                        $correctIndex = (int)$possibleCorrect - 1;
                        $answerTexts = array_slice($fields, 1, $lastIdx - 1);
                    } else if ($secondLastIdx >= 2) {
                        $possibleCorrect2 = trim($fields[$secondLastIdx]);
                        $answerCount2 = $secondLastIdx - 1;
                        if (is_numeric($possibleCorrect2) && (int)$possibleCorrect2 >= 1 && (int)$possibleCorrect2 <= 8 && (int)$possibleCorrect2 <= $answerCount2) {
                            $correctIndex = (int)$possibleCorrect2 - 1;
                            $answerTexts = array_slice($fields, 1, $secondLastIdx - 1);
                            $explanation = trim($fields[$lastIdx]);
                            if (empty($explanation)) $explanation = null;
                        } else {
                            $answerTexts = array_slice($fields, 1, $lastIdx);
                            $correctText = strtolower(trim($possibleCorrect));
                            foreach ($answerTexts as $i => $at) {
                                if (strtolower(trim($at)) === $correctText) {
                                    $correctIndex = $i;
                                    break;
                                }
                            }
                            if ($correctIndex !== null) {
                                array_pop($answerTexts);
                            }
                        }
                    }
                }

                $answerTexts = array_map('trim', $answerTexts);
                $answerTexts = array_filter($answerTexts, function($t) { return $t !== ''; });
                $answerTexts = array_values($answerTexts);

                if (count($answerTexts) < 2) {
                    $errors[] = $this->l10n->t('Line %s: Need at least 2 answers', [$line]);
                    continue;
                }

                if (count($answerTexts) > 8) {
                    $errors[] = $this->l10n->t('Line %s: Maximum 8 answers', [$line]);
                    continue;
                }

                if ($correctIndex === null || $correctIndex >= count($answerTexts)) {
                    $errors[] = $this->l10n->t('Line %s: Could not determine correct answer — use a number (1-N) to indicate which answer is correct', [$line]);
                    continue;
                }

                // Determine question type based on correct index
                $csvQuestionType = 'single';
                $correctIndices = [$correctIndex];

                $question = new Question();
                $question->setPoolId($poolId);
                $question->setUserId($this->userId);
                $question->setText($questionText);
                $question->setExplanation($explanation);
                $question->setQuestionType($csvQuestionType);
                $question = $this->questionMapper->createOrUpdate($question);

                $truncated = false;
                foreach ($answerTexts as $i => $answerText) {
                    if (mb_strlen($answerText) > self::MAX_ANSWER_LENGTH) {
                        $answerText = mb_substr($answerText, 0, self::MAX_ANSWER_LENGTH);
                        $truncated = true;
                    }
                    $answer = new Answer();
                    $answer->setQuestionId($question->getId());
                    $answer->setText($answerText);
                    $answer->setIsCorrect(in_array($i, $correctIndices));
                    $answer->setPosition($i);
                    $this->answerMapper->createOrUpdate($answer);
                }
                if ($truncated) {
                    $warnings[] = $this->l10n->t('Line %s: Answer text truncated to 2000 chars', [$line]);
                }

                $imported++;
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            \OCP\Server::get(\Psr\Log\LoggerInterface::class)->error('CSV import failed: ' . get_class($e) . ': ' . $e->getMessage());
            return new DataResponse(['error' => $this->l10n->t('Import failed due to a server error')], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $response = [
            'imported' => $imported,
            'errors' => $errors,
            'total_lines' => count($lines)
        ];
        if (!empty($warnings)) {
            $response['warnings'] = $warnings;
        }
        return new DataResponse($response, $imported > 0 ? Http::STATUS_CREATED : Http::STATUS_BAD_REQUEST);
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 5, period: 60)]
    public function importJson(int $poolId, string $jsonData): DataResponse {
        if (!$this->canEditPool($poolId)) {
            return new DataResponse(['error' => $this->l10n->t('No edit access to this pool')], Http::STATUS_FORBIDDEN);
        }

        $jsonData = $this->normalizeText($jsonData);

        $maxBytes = $this->getMaxImportBytes();
        if (strlen($jsonData) > $maxBytes) {
            return new DataResponse(['error' => $this->l10n->t('JSON data too large (max %s MB)', [(int)($maxBytes / 1024 / 1024)])], Http::STATUS_BAD_REQUEST);
        }

        $data = json_decode($jsonData, true);
        if (!is_array($data)) {
            return new DataResponse(['error' => $this->l10n->t('Invalid JSON — check for missing brackets, commas, or quotes')], Http::STATUS_BAD_REQUEST);
        }

        $importMeta = $this->extractImportMeta($data);
        $fileMeta = isset($data['_meta']) && is_array($data['_meta']) ? $data['_meta'] : [];
        $defaultType = PoolJsonFormat::defaultQuestionType($fileMeta);

        // Shape errors are answered with `error` only: the dialog shows `errors` when the key is
        // present, and an empty list there would show the user nothing.
        $shape = $this->questionListFrom($data);
        if (is_string($shape)) {
            return new DataResponse(['error' => $shape], Http::STATUS_BAD_REQUEST);
        }
        [$data, $sourcePool] = $shape;

        if (count($data) > self::MAX_ITEMS) {
            return new DataResponse(['error' => $this->l10n->t('Maximum 500 items per import')], Http::STATUS_BAD_REQUEST);
        }

        $imported = 0;
        $errors = [];
        $warnings = [];

        $this->db->beginTransaction();
        try {
            $num = 0;
            foreach ($data as $index => $rawItem) {
                $num++;

                if (!is_array($rawItem)) {
                    $errors[] = $this->l10n->t('Item %s: Not a valid object', [$num]);
                    continue;
                }

                // Pool chapter fields and _meta defaults, where the question has no own value —
                // the same rule as occ learning:import-pool-json.
                $rawItem = PoolJsonFormat::applyPoolDefaults($rawItem, $sourcePool, $fileMeta, $index);

                // PBQ items have their own import path. A pbq default, like any default type,
                // does not apply to an item that marks several answers correct.
                $pbqByDefault = $defaultType === 'pbq' && !self::marksSeveralCorrect($rawItem);
                if ((self::typeFrom($rawItem) ?? ($pbqByDefault ? 'pbq' : null)) === 'pbq') {
                    $imported += $this->importPbqItem($poolId, $rawItem, $num, $errors, $warnings);
                    continue;
                }

                // Normalize field names from any supported format
                $item = $this->normalizeJsonItem($rawItem, $num, $errors, $defaultType === 'pbq' ? null : $defaultType);
                if ($item === null) {
                    continue;
                }
                if ($this->scenarioTooLong($item, $num, $errors)) {
                    continue;
                }

                if (mb_strlen($item['text']) > 5000) {
                    $item['text'] = mb_substr($item['text'], 0, 5000);
                    $warnings[] = $this->l10n->t('Item %s: Question text truncated to 5000 chars', [$num]);
                }

                // Open-question type
                if ($item['type'] === 'open') {
                    if (empty($item['answers'])) {
                        $errors[] = $this->l10n->t('Item %s: Open question needs at least 1 answer', [$num]);
                        continue;
                    }
                    $modelText = $item['answers'][0]['text'];
                    if (mb_strlen($modelText) > self::MAX_ANSWER_LENGTH) {
                        $modelText = mb_substr($modelText, 0, self::MAX_ANSWER_LENGTH);
                        $warnings[] = $this->l10n->t('Item %s: Answer text truncated to 2000 chars', [$num]);
                    }
                    $question = new Question();
                    $question->setPoolId($poolId);
                    $question->setUserId($this->userId);
                    $question->setText($item['text']);
                    $question->setExplanation($item['explanation']);
                    $question->setDifficulty($item['difficulty']);
                    $question->setQuestionType('open');
                    $this->applyQuestionMetadata($question, $item);
                    $question = $this->questionMapper->createOrUpdate($question);

                    $answer = new Answer();
                    $answer->setQuestionId($question->getId());
                    $answer->setText($modelText);
                    $answer->setIsCorrect(true);
                    $answer->setPosition(0);
                    $this->answerMapper->createOrUpdate($answer);

                    $imported++;
                    continue;
                }

                if (count($item['answers']) < 2) {
                    $errors[] = $this->l10n->t('Item %s: Need at least 2 answers', [$num]);
                    continue;
                }

                if (count($item['answers']) > self::MAX_ANSWERS) {
                    // A correct answer in the part that is cut off: cutting would leave a wrong
                    // answer marked (the first, by default) or turn multi into single.
                    $cutOff = array_slice($item['answers'], self::MAX_ANSWERS);
                    if (array_filter($cutOff, static fn (array $a): bool => $a['is_correct']) !== []) {
                        $errors[] = $this->l10n->t('Item %1$s: A correct answer comes after answer %2$s, but only the first %2$s answers are imported — question skipped', [$num, self::MAX_ANSWERS]);
                        continue;
                    }
                    $item['answers'] = array_slice($item['answers'], 0, self::MAX_ANSWERS);
                    $warnings[] = $this->l10n->t('Item %s: Truncated to 8 answers', [$num]);
                }

                $correctCount = 0;
                foreach ($item['answers'] as $a) {
                    if ($a['is_correct']) $correctCount++;
                }

                // Only reached when the file names no correct answer at all: a named one is
                // either marked by now or the item was refused above / in normalizeJsonItem().
                if ($correctCount === 0) {
                    // Best-effort: mark first answer as correct + warn
                    $item['answers'][0]['is_correct'] = true;
                    $correctCount = 1;
                    $warnings[] = $this->l10n->t('Item %s: No correct answer marked — defaulted to first answer', [$num]);
                }

                // An explicit multi stays multi with one correct answer (an export writes it so).
                $questionType = $correctCount > 1 || $item['type'] === 'multi' ? 'multi' : 'single';

                // Validate ALL answer texts BEFORE creating the question to prevent orphans
                $validatedAnswers = [];
                $hasInvalidAnswer = false;
                $truncated = false;
                foreach ($item['answers'] as $i => $answerData) {
                    $answerText = $answerData['text'];
                    if ($answerText === '') {
                        $errors[] = $this->l10n->t('Item %1$s: Empty answer text at position %2$s', [$num, $i + 1]);
                        $hasInvalidAnswer = true;
                        break;
                    }
                    if (mb_strlen($answerText) > self::MAX_ANSWER_LENGTH) {
                        $answerText = mb_substr($answerText, 0, self::MAX_ANSWER_LENGTH);
                        $truncated = true;
                    }
                    $validatedAnswers[] = [
                        'text' => $answerText,
                        'is_correct' => $answerData['is_correct'],
                        'position' => $i,
                    ];
                }
                if ($hasInvalidAnswer) {
                    continue;
                }
                if ($truncated) {
                    $warnings[] = $this->l10n->t('Item %s: Answer text truncated to 2000 chars', [$num]);
                }

                $question = new Question();
                $question->setPoolId($poolId);
                $question->setUserId($this->userId);
                $question->setText($item['text']);
                $question->setExplanation($item['explanation']);
                $question->setDifficulty($item['difficulty']);
                $question->setQuestionType($questionType);
                $this->applyQuestionMetadata($question, $item);
                $question = $this->questionMapper->createOrUpdate($question);

                foreach ($validatedAnswers as $va) {
                    $answer = new Answer();
                    $answer->setQuestionId($question->getId());
                    $answer->setText($va['text']);
                    $answer->setIsCorrect($va['is_correct']);
                    $answer->setPosition($va['position']);
                    $this->answerMapper->createOrUpdate($answer);
                }

                $imported++;
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            \OCP\Server::get(\Psr\Log\LoggerInterface::class)->error('JSON import failed: ' . get_class($e) . ': ' . $e->getMessage());
            return new DataResponse(['error' => $this->l10n->t('Import failed due to a server error')], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $response = [
            'imported' => $imported,
            'errors' => $errors,
            'total_items' => count($data)
        ];
        if (!empty($importMeta)) {
            $response['meta'] = $importMeta;
        }
        if (!empty($warnings)) {
            $response['warnings'] = $warnings;
        }
        return new DataResponse($response, $imported > 0 ? Http::STATUS_CREATED : Http::STATUS_BAD_REQUEST);
    }

    private function importPbqItem(int $poolId, array $item, int $num, array &$errors, array &$warnings): int {
        $text = self::questionTextFrom($item, true);
        if ($text === '') {
            $errors[] = $this->l10n->t('PBQ #%s: missing question_text', [$num]);
            return 0;
        }
        if ($this->scenarioTooLong($item, $num, $errors)) {
            return 0;
        }

        $subtype = self::scalarText($item['subtype'] ?? $item['pbq_subtype'] ?? null);
        if (!in_array($subtype, PoolJsonFormat::PBQ_SUBTYPES, true)) {
            $errors[] = $this->l10n->t('PBQ #%1$s: invalid subtype \'%2$s\'', [$num, $subtype]);
            return 0;
        }

        $pbqConfig = PoolJsonFormat::pbqConfig($item);
        if ($pbqConfig === null) {
            $errors[] = $this->l10n->t('PBQ #%s: pbq_config must be an object', [$num]);
            return 0;
        }

        $question = new \OCA\Learning\Db\Question();
        $question->setPoolId($poolId);
        $question->setUserId($this->userId);
        $question->setText($text);
        $question->setExplanation(self::optionalText($item['explanation'] ?? null));
        $question->setQuestionType('pbq');
        $question->setPbqSubtype($subtype);
        $question->setPbqConfig(json_encode($pbqConfig));
        $question->setDifficulty(PoolJsonFormat::difficulty($item) ?? 'medium');
        $question->setReviewStatus('published');
        $this->applyQuestionMetadata($question, self::metadataFrom($item));

        $this->questionMapper->createOrUpdate($question);
        return 1;
    }

    /**
     * @NoAdminRequired
     *
     * File upload endpoint — accepts multipart/form-data with a CSV or JSON file.
     * Auto-detects format from file extension and content.
     */
    #[UserRateLimit(limit: 5, period: 60)]
    public function importFile(int $poolId): DataResponse {
        if (!$this->canEditPool($poolId)) {
            return new DataResponse(['error' => $this->l10n->t('No edit access to this pool')], Http::STATUS_FORBIDDEN);
        }

        $file = $this->request->getUploadedFile('file');
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            return new DataResponse(['error' => $this->l10n->t('No file uploaded or upload error')], Http::STATUS_BAD_REQUEST);
        }

        $maxBytes = $this->getMaxImportBytes();
        if ($file['size'] > $maxBytes) {
            return new DataResponse(['error' => $this->l10n->t('File too large (max %s MB)', [(int)($maxBytes / 1024 / 1024)])], Http::STATUS_BAD_REQUEST);
        }

        $content = file_get_contents($file['tmp_name']);
        if ($content === false || $content === '') {
            return new DataResponse(['error' => $this->l10n->t('Could not read uploaded file')], Http::STATUS_BAD_REQUEST);
        }

        $content = $this->normalizeText($content);

        // Format as in the import dialog: the extension .json/.csv decides first. Without one of
        // them, JSON only if the content really decodes to a list or object — a CSV whose first
        // field starts with "[" or "{" ("[Network] Which port…") stays CSV.
        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if ($ext === 'json') {
            $isJson = true;
        } elseif ($ext === 'csv') {
            $isJson = false;
        } else {
            $isJson = is_array(json_decode($content, true));
        }

        if ($isJson) {
            return $this->importJson($poolId, $content);
        } else {
            return $this->importCsv($poolId, $content);
        }
    }
}
