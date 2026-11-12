<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

/**
 * Rules of the pool JSON format that both importers share — the pool import in the interface
 * (ImportController) and occ learning:import-pool-json — so a file reads the same in both.
 *
 * Format: docs/import-pool-workflow.md. The export (DataMobilityService::exportPoolJson) writes
 * question items in the same field names, so an export comes back through either importer.
 */
final class PoolJsonFormat {
    /**
     * PBQ subtypes the app can render and score. One list for every place that accepts a subtype
     * from outside (both importers, the VirtuProf question context): `ranking` (PbqRanking.vue,
     * scored in TrainingService) was missing from all of them, so a ranking PBQ could be
     * exported but not imported again.
     */
    public const PBQ_SUBTYPES = ['dropdown', 'placement', 'cli', 'cable', 'multi_panel', 'switch_config', 'routing_config', 'diagnostic', 'ranking'];

    /** Field → accepted keys on a question item, canonical key first. */
    private const ALIASES = [
        'handbook_key' => ['handbook_key', 'handbookKey', 'buch_key'],
        'handbook_title' => ['handbook_title', 'handbookTitle', 'buch_titel'],
        'exam_key' => ['exam_key', 'examKey', 'pruefung_key'],
        'chapter_key' => ['chapter_key', 'chapterKey', 'kapitel_key'],
        'chapter_title' => ['chapter_title', 'chapterTitle', 'kapitel_titel'],
        'chapter_order' => ['chapter_order', 'chapterOrder', 'kapitel_nummer'],
        'difficulty' => ['difficulty', 'schwierigkeit'],
    ];

    /**
     * Text of a scalar JSON value: strings, integers and floats become trimmed text; null,
     * booleans, arrays and objects become ''.
     */
    public static function scalarText(mixed $value): string {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return trim((string)$value);
        }
        return '';
    }

    /**
     * Value of a metadata field on a question item under any of its accepted keys: the first
     * key that holds a non-empty scalar wins. Returns null when the item has none.
     */
    public static function field(array $item, string $field): string|int|float|null {
        foreach (self::ALIASES[$field] ?? [$field] as $key) {
            $value = $item[$key] ?? null;
            if ((is_string($value) || is_int($value) || is_float($value)) && self::scalarText($value) !== '') {
                return $value;
            }
        }
        return null;
    }

    public static function fieldText(array $item, string $field): ?string {
        $value = self::field($item, $field);
        return $value === null ? null : self::scalarText($value);
    }

    public static function fieldInt(array $item, string $field): ?int {
        $value = self::field($item, $field);
        return is_numeric($value) ? (int)$value : null;
    }

    /** Length of the difficulty column (oc_learning_questions.difficulty). */
    public const DIFFICULTY_MAX_LENGTH = 20;

    /**
     * Difficulty of a question item, cut to the column length — as applyQuestionMetadata()
     * cuts the other text fields. A longer value used to pass the preview and then fail the
     * whole import transaction with a 500 (GitHub #34).
     */
    public static function difficulty(array $item): ?string {
        $text = self::fieldText($item, 'difficulty');
        return $text === null ? null : mb_substr($text, 0, self::DIFFICULTY_MAX_LENGTH);
    }

    /** Valid chapter_order range, as QuestionService/PoolService::normalizeChapterOrder() enforce it. */
    public const CHAPTER_ORDER_MIN = 1;
    public const CHAPTER_ORDER_MAX = 9999;

    /**
     * chapter_order of an item or pool, valid only from 1 to 9999 (the rule of
     * QuestionService/PoolService::normalizeChapterOrder()). A value outside is dropped (null),
     * silently like the cut difficulty: the services would throw, so occ skipped the question
     * (or failed the whole pool) while the interface stored 0 or 10000, and a value past the
     * column's range failed the insert.
     */
    public static function chapterOrder(array $item): ?int {
        $order = self::fieldInt($item, 'chapter_order');
        return $order === null || $order < self::CHAPTER_ORDER_MIN || $order > self::CHAPTER_ORDER_MAX ? null : $order;
    }

    /**
     * `_meta.default_question_type`, or null. Applied by the importers only to a question
     * without its own type, and — as in ImportPoolJsonCommand::resolveQuestionType() — only
     * after "several correct answers means multi".
     */
    public static function defaultQuestionType(array $meta): ?string {
        $type = strtolower(self::scalarText($meta['default_question_type'] ?? null));
        if ($type === '') {
            return null;
        }
        return $type === 'true_false' ? 'single' : $type;
    }

    /**
     * Fill a question's missing metadata from its pool and from the file's `_meta`, in the order
     * question > pool > _meta. A value the question carries itself (under any accepted key) is
     * never overwritten; a value missing everywhere stays missing (no invented default).
     *
     *  - handbook_key / handbook_title: pool, else _meta
     *  - chapter_key / chapter_title / chapter_order: pool
     *  - exam_key: "<pool.exam_key_prefix>-q<original_number>" (original_number defaults to the
     *    question's 1-based position in the pool)
     *  - difficulty: _meta.default_difficulty
     *
     * Pool name and description are not question fields and are never touched here.
     *
     * @param array<mixed> $question
     * @param array<mixed> $pool the pool object the question came from ([] for none)
     * @param array<mixed> $meta the file's _meta object ([] for none)
     * @param int $index 0-based position of the question in its list
     * @return array<mixed>
     */
    public static function applyPoolDefaults(array $question, array $pool, array $meta, int $index): array {
        $defaults = [
            'handbook_key' => self::fieldText($pool, 'handbook_key') ?? self::fieldText($meta, 'handbook_key'),
            'handbook_title' => self::fieldText($pool, 'handbook_title') ?? self::fieldText($meta, 'handbook_title'),
            'chapter_key' => self::fieldText($pool, 'chapter_key'),
            'chapter_title' => self::fieldText($pool, 'chapter_title'),
            'chapter_order' => self::chapterOrder($pool),
            'difficulty' => self::scalarText($meta['default_difficulty'] ?? null) ?: null,
            'exam_key' => null,
        ];
        $prefix = self::scalarText($pool['exam_key_prefix'] ?? null);
        if ($prefix !== '') {
            $number = self::scalarText($question['original_number'] ?? null);
            $defaults['exam_key'] = sprintf('%s-q%s', $prefix, $number !== '' ? $number : (string)($index + 1));
        }
        foreach ($defaults as $field => $value) {
            if ($value !== null && self::field($question, $field) === null) {
                $question[$field] = $value;
            }
        }
        return $question;
    }

    /** `pbq_config` as an array, or null when it is missing or no JSON object/list. */
    public static function pbqConfig(array $item): ?array {
        $config = $item['pbq_config'] ?? null;
        return is_array($config) ? $config : null;
    }
}
