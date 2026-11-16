<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

/**
 * Rich feedback of a question (GitHub #66): a hint shown before answering, feedback per answer
 * option (why it is right or wrong, the misconception behind a wrong choice), a background fact
 * and a key takeaway shown after answering.
 *
 * Storage (learning_questions and learning_qst_translations, all nullable TEXT):
 *  - hint, background_fact, key_takeaway: plain text in the question's own language, line breaks
 *    kept; translations go into learning_qst_translations like text, explanation and scenario.
 *  - option_feedback: JSON list [{position, why, misconception}], keyed by the answer's position
 *    (0-based, the order of the answers in the editor and the export). Answer rows are recreated
 *    on every save of the question editor, so their ids are no key; the position is. The verdict
 *    is never stored: it is the answer's is_correct, one source of truth for the answer key.
 *
 * Pool JSON (import and export, docs/import-pool-workflow.md):
 *  - "hint" (also "hinweis"), "background_fact" ("background", "hintergrund"),
 *    "key_takeaway" ("takeaway", "merksatz"): a string, or an object of languages {en, de, …}
 *    from which one language is taken (the item's "lang", else en, else de, else the first).
 *  - "feedback_per_option" (also "option_feedback"): [{option_index, letter, verdict, why,
 *    misconception}]; option_index counts from 0 in the order of "answers", letter A = first
 *    answer is the fallback, then the list order. "why" and "misconception" may be language
 *    objects as above. A verdict that contradicts the answer key is reported, the key wins.
 *  - On an answer object: "why" / "feedback" and "misconception" / "trap".
 *
 * Shared by the interface import (ImportController), occ learning:import-pool-json and the pool
 * import in the Pools tab (PoolBulkImportService), the export (DataMobilityService) and the
 * question editor (QuestionService), so a pool reads and writes the same everywhere.
 */
final class RichFeedbackFormat {
    public const HINT_MAX_LENGTH = 2000;
    public const BACKGROUND_MAX_LENGTH = 5000;
    public const TAKEAWAY_MAX_LENGTH = 500;
    public const WHY_MAX_LENGTH = 5000;
    public const MISCONCEPTION_MAX_LENGTH = 1000;
    /** Fixed text of the \InvalidArgumentException; PoolBulkImportService localizes it. */
    public const TOO_LONG_MESSAGE = 'Feedback field exceeds maximum length';

    /** Question-level text fields → accepted keys, canonical first. */
    private const TEXT_ALIASES = [
        'hint' => ['hint', 'hinweis'],
        'background_fact' => ['background_fact', 'backgroundFact', 'background', 'hintergrund'],
        'key_takeaway' => ['key_takeaway', 'keyTakeaway', 'takeaway', 'merksatz'],
    ];

    private const TEXT_MAX = [
        'hint' => self::HINT_MAX_LENGTH,
        'background_fact' => self::BACKGROUND_MAX_LENGTH,
        'key_takeaway' => self::TAKEAWAY_MAX_LENGTH,
    ];

    /** Language order for a {lang: text} value without a matching "lang". */
    private const LANGUAGE_ORDER = ['en', 'de'];

    /**
     * Read the rich feedback of one question item.
     *
     * @param array<mixed> $item the question item of the file
     * @param array<int, int> $positionMap index of an answer in the file's "answers" list → its
     *        stored position (answers that are dropped, e.g. blank ones, are missing); null = the
     *        same index
     * @param list<bool> $isCorrect is_correct of the stored answers, by position (for the verdict
     *        cross-check); [] = no check
     * @return array{hint: ?string, background_fact: ?string, key_takeaway: ?string,
     *         option_feedback: list<array{position: int, why: ?string, misconception: ?string}>,
     *         dropped_languages: list<string>, verdict_mismatches: int, unmatched: int}
     */
    public static function fromItem(array $item, ?array $positionMap = null, array $isCorrect = []): array {
        $lang = is_string($item['lang'] ?? null) ? strtolower(trim($item['lang'])) : null;
        $dropped = [];
        $result = [
            'hint' => null,
            'background_fact' => null,
            'key_takeaway' => null,
            'option_feedback' => [],
            'dropped_languages' => [],
            'verdict_mismatches' => 0,
            'unmatched' => 0,
        ];
        foreach (self::TEXT_ALIASES as $field => $keys) {
            foreach ($keys as $key) {
                if (array_key_exists($key, $item)) {
                    $result[$field] = self::text($item[$key], $lang, $dropped);
                    if ($result[$field] !== null) {
                        break;
                    }
                }
            }
        }

        $answers = is_array($item['answers'] ?? null) ? array_values($item['answers']) : [];
        $map = static function (int $fileIndex) use ($positionMap, $answers): ?int {
            if ($positionMap === null) {
                return ($answers === [] || $fileIndex < count($answers)) ? $fileIndex : null;
            }
            return $positionMap[$fileIndex] ?? null;
        };

        /** @var array<int, array{position: int, why: ?string, misconception: ?string}> $byPosition */
        $byPosition = [];
        // On the answer objects themselves.
        foreach ($answers as $index => $answer) {
            if (!is_array($answer)) {
                continue;
            }
            $why = self::text($answer['why'] ?? $answer['feedback'] ?? null, $lang, $dropped);
            $misconception = self::text($answer['misconception'] ?? $answer['trap'] ?? null, $lang, $dropped);
            if ($why === null && $misconception === null) {
                continue;
            }
            $position = $map($index);
            if ($position === null) {
                $result['unmatched']++;
                continue;
            }
            $byPosition[$position] = ['position' => $position, 'why' => $why, 'misconception' => $misconception];
        }

        // The question-level list; it wins over a value on the answer object.
        $list = $item['feedback_per_option'] ?? $item['option_feedback'] ?? null;
        if (is_array($list)) {
            foreach (array_values($list) as $listIndex => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $why = self::text($entry['why'] ?? $entry['feedback'] ?? null, $lang, $dropped);
                $misconception = self::text($entry['misconception'] ?? $entry['trap'] ?? null, $lang, $dropped);
                $fileIndex = self::optionIndex($entry, $listIndex);
                $position = $map($fileIndex);
                if ($position === null) {
                    if ($why !== null || $misconception !== null) {
                        $result['unmatched']++;
                    }
                    continue;
                }
                $verdict = is_string($entry['verdict'] ?? null) ? strtolower(trim($entry['verdict'])) : '';
                if (isset($isCorrect[$position]) && in_array($verdict, ['correct', 'wrong'], true)
                    && ($verdict === 'correct') !== $isCorrect[$position]) {
                    $result['verdict_mismatches']++;
                }
                if ($why === null && $misconception === null) {
                    continue;
                }
                $byPosition[$position] = ['position' => $position, 'why' => $why, 'misconception' => $misconception];
            }
        }

        ksort($byPosition);
        $result['option_feedback'] = array_values($byPosition);
        $result['dropped_languages'] = array_values(array_unique($dropped));
        return $result;
    }

    /**
     * Trim, drop blank entries and check lengths. Throws \InvalidArgumentException
     * (TOO_LONG_MESSAGE) when a field is too long — the same check for the editor and all
     * importers. Only the keys present in $rich are returned (an absent key = keep the stored
     * value on update).
     *
     * @param array<string, mixed> $rich hint, background_fact, key_takeaway (string|null),
     *        option_feedback (list of {position, why, misconception})
     * @return array<string, mixed>
     */
    public static function normalize(array $rich): array {
        $out = [];
        foreach (self::TEXT_MAX as $field => $max) {
            if (!array_key_exists($field, $rich)) {
                continue;
            }
            $out[$field] = self::checked(self::plain($rich[$field]), $max);
        }
        if (array_key_exists('option_feedback', $rich)) {
            $byPosition = [];
            foreach (is_array($rich['option_feedback']) ? $rich['option_feedback'] : [] as $entry) {
                if (!is_array($entry) || !is_numeric($entry['position'] ?? null)) {
                    continue;
                }
                $position = (int)$entry['position'];
                if ($position < 0 || $position > 63) {
                    continue;
                }
                $why = self::checked(self::plain($entry['why'] ?? null), self::WHY_MAX_LENGTH);
                $misconception = self::checked(self::plain($entry['misconception'] ?? null), self::MISCONCEPTION_MAX_LENGTH);
                if ($why === null && $misconception === null) {
                    continue;
                }
                $byPosition[$position] = ['position' => $position, 'why' => $why, 'misconception' => $misconception];
            }
            ksort($byPosition);
            $out['option_feedback'] = array_values($byPosition);
        }
        return $out;
    }

    /**
     * option_feedback as stored (JSON) — null when empty.
     *
     * @param list<array{position: int, why: ?string, misconception: ?string}> $list
     */
    public static function encodeOptions(array $list): ?string {
        return $list === [] ? null : (string)json_encode(array_values($list), JSON_UNESCAPED_UNICODE);
    }

    /**
     * The stored option_feedback JSON as a list; anything unreadable is an empty list.
     *
     * @return list<array{position: int, why: ?string, misconception: ?string}>
     */
    public static function decodeOptions(?string $json): array {
        if ($json === null || trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $list = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry) || !is_numeric($entry['position'] ?? null)) {
                continue;
            }
            $list[] = [
                'position' => (int)$entry['position'],
                'why' => is_string($entry['why'] ?? null) && $entry['why'] !== '' ? $entry['why'] : null,
                'misconception' => is_string($entry['misconception'] ?? null) && $entry['misconception'] !== '' ? $entry['misconception'] : null,
            ];
        }
        return $list;
    }

    /**
     * The fields of the pool JSON export, left out when empty. $answers are the exported answers
     * in export order (each with its stored position and is_correct), so option_index is the
     * index in the export's "answers" list.
     *
     * @param list<array{position: ?int, is_correct: bool}> $answers
     * @return array<string, mixed>
     */
    public static function toExport(?string $hint, ?string $backgroundFact, ?string $keyTakeaway, ?string $optionFeedback, array $answers): array {
        $out = [];
        foreach (['hint' => $hint, 'background_fact' => $backgroundFact, 'key_takeaway' => $keyTakeaway] as $key => $value) {
            if ($value !== null && trim($value) !== '') {
                $out[$key] = $value;
            }
        }
        $indexByPosition = [];
        foreach (array_values($answers) as $index => $answer) {
            $indexByPosition[$answer['position'] ?? $index] = [$index, (bool)$answer['is_correct']];
        }
        $options = [];
        foreach (self::decodeOptions($optionFeedback) as $entry) {
            if (!isset($indexByPosition[$entry['position']])) {
                continue;
            }
            [$index, $correct] = $indexByPosition[$entry['position']];
            $option = [
                'option_index' => $index,
                'letter' => $index < 26 ? chr(65 + $index) : null,
                'verdict' => $correct ? 'correct' : 'wrong',
            ];
            if ($entry['why'] !== null) {
                $option['why'] = $entry['why'];
            }
            if ($entry['misconception'] !== null) {
                $option['misconception'] = $entry['misconception'];
            }
            if ($option['letter'] === null) {
                unset($option['letter']);
            }
            $options[] = $option;
        }
        if ($options !== []) {
            usort($options, static fn (array $a, array $b): int => $a['option_index'] <=> $b['option_index']);
            $out['feedback_per_option'] = $options;
        }
        return $out;
    }

    /** 0-based answer index named by an entry: option_index, else letter, else its list order. */
    private static function optionIndex(array $entry, int $listIndex): int {
        $index = $entry['option_index'] ?? $entry['index'] ?? null;
        if ((is_int($index) && $index >= 0) || (is_string($index) && ctype_digit(trim($index)))) {
            return (int)$index;
        }
        $letter = is_string($entry['letter'] ?? null) ? strtoupper(trim($entry['letter'])) : '';
        if (strlen($letter) === 1 && $letter >= 'A' && $letter <= 'Z') {
            return ord($letter) - 65;
        }
        return $listIndex;
    }

    /**
     * Text of a string or {lang: text} value, trimmed; null when blank. Languages of an object
     * that are not taken are added to $dropped.
     *
     * @param list<string> $dropped
     */
    private static function text(mixed $value, ?string $lang, array &$dropped): ?string {
        if (is_string($value) || is_int($value) || is_float($value)) {
            $text = trim((string)$value);
            return $text === '' ? null : $text;
        }
        if (!is_array($value) || $value === [] || array_is_list($value)) {
            return null;
        }
        $available = [];
        foreach ($value as $key => $text) {
            if (is_string($text) && trim($text) !== '') {
                $available[strtolower((string)$key)] = trim($text);
            }
        }
        if ($available === []) {
            return null;
        }
        $chosen = null;
        foreach (array_merge($lang !== null ? [$lang] : [], self::LANGUAGE_ORDER) as $candidate) {
            if (isset($available[$candidate])) {
                $chosen = $candidate;
                break;
            }
        }
        $chosen ??= (string)array_key_first($available);
        foreach (array_keys($available) as $key) {
            if ($key !== $chosen) {
                $dropped[] = (string)$key;
            }
        }
        return $available[$chosen];
    }

    private static function plain(mixed $value): ?string {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }
        $text = trim((string)$value);
        return $text === '' ? null : $text;
    }

    private static function checked(?string $text, int $max): ?string {
        if ($text !== null && mb_strlen($text) > $max) {
            throw new \InvalidArgumentException(self::TOO_LONG_MESSAGE);
        }
        return $text;
    }
}
