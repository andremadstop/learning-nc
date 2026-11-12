<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCP\IDBConnection;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Imports whole pools with their questions from a pool JSON document — the one implementation
 * behind `occ learning:import-pool-json` and the pool import in the Pools tab
 * (PoolImportController). Format: docs/import-pool-workflow.md.
 *
 * A document is {_meta?, pools: [{name, description?, chapter_*, …, questions: [...]}, ...]};
 * asPoolsDocument() also turns a bare question list or an export ({name?, questions: [...]})
 * into such a document with one pool.
 *
 * Rules:
 *  - The dry run goes through exactly the same checks as the real import, including the
 *    question checks of QuestionService::create() (QuestionService::validateForImport()) and the
 *    pool metadata checks of PoolService::create() (PoolService::validateForImport()), and
 *    writes nothing. Its `importable` is what the real import would store.
 *  - The real import writes each pool in its own transaction. A question whose input is refused
 *    (\InvalidArgumentException) is skipped and reported; the pool keeps its other questions.
 *    Any other failure while the pool is open — the pool row or a question insert fails — rolls
 *    the whole pool back and reports it; the other pools stay.
 *  - Messages go through the IL10N the caller passes. Text of unexpected exceptions (SQL
 *    details) is logged, never returned.
 */
class PoolBulkImportService {
    /** Pool name length, as PoolController::create() accepts it. */
    public const NAME_MAX_LENGTH = 200;
    /** Pool description length, as PoolController::create() accepts it. */
    public const DESCRIPTION_MAX_LENGTH = 2000;

    public function __construct(
        private PoolService $poolService,
        private QuestionService $questionService,
        private IDBConnection $db,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The document as {_meta?, pools: [...]}, or null when it is none of the accepted shapes.
     * A bare list of questions, or an object with a "questions" list, becomes one pool named
     * $poolName if given, else by the document's own "name" or "pool_name", else $fallbackName.
     *
     * @param array<mixed> $data
     * @return array<mixed>|null
     */
    public static function asPoolsDocument(array $data, string $fallbackName, ?string $poolName = null): ?array {
        if (isset($data['pools'])) {
            return is_array($data['pools']) ? $data : null;
        }
        if ($data !== [] && array_is_list($data)) {
            return ['pools' => [['name' => $poolName ?? $fallbackName, 'questions' => $data]]];
        }
        if (isset($data['questions']) && is_array($data['questions'])) {
            $name = PoolJsonFormat::poolName($data);
            $pool = ['name' => $poolName ?? ($name !== '' ? $name : $fallbackName), 'questions' => $data['questions']];
            if (isset($data['description']) && is_string($data['description'])) {
                $pool['description'] = $data['description'];
            }
            return ['_meta' => $data['_meta'] ?? [], 'pools' => [$pool]];
        }
        return null;
    }

    /**
     * Pool objects of a document from asPoolsDocument(), in file order.
     *
     * @param array<mixed> $document
     * @return list<mixed>
     */
    public static function poolsOf(array $document): array {
        return is_array($document['pools'] ?? null) ? array_values($document['pools']) : [];
    }

    /**
     * Question items of all pools of a document, counted before any check.
     *
     * @param array<mixed> $document
     */
    public static function questionCount(array $document): int {
        $count = 0;
        foreach (self::poolsOf($document) as $pool) {
            $count += is_array($pool) ? count(self::questionsOf($pool)) : 0;
        }
        return $count;
    }

    /**
     * Import (or, with $dryRun, only check) every pool of a document.
     *
     * One entry per pool object, in file order:
     *  - index: 1-based position of the pool in the file
     *  - name: pool name ('' when the pool has none)
     *  - id: id of the new pool; null in a dry run and for a pool that was not created
     *  - chapter_key: the pool's chapter key, or null
     *  - questions_total: question items of the pool
     *  - importable: questions that pass every check (a dry run stores none)
     *  - imported: questions stored (always 0 in a dry run)
     *  - errors: why the pool itself was not imported (or rolled back)
     *  - question_errors: questions that were not imported, and why ("Q<n>: …")
     *  - warnings: what was imported but deserves a look
     *
     * @param array<mixed> $document from asPoolsDocument()
     * @return list<array{index: int, name: string, id: int|null, chapter_key: string|null, questions_total: int, importable: int, imported: int, errors: list<string>, question_errors: list<string>, warnings: list<string>}>
     */
    public function import(array $document, string $userId, bool $dryRun, IL10N $l): array {
        $meta = isset($document['_meta']) && is_array($document['_meta']) ? $document['_meta'] : [];
        $defaultType = PoolJsonFormat::defaultQuestionType($meta) ?? 'single';
        $defaultHandbookKey = PoolJsonFormat::fieldText($meta, 'handbook_key');
        $defaultHandbookTitle = PoolJsonFormat::fieldText($meta, 'handbook_title');

        $results = [];
        foreach (self::poolsOf($document) as $i => $poolData) {
            $index = $i + 1;
            $poolData = is_array($poolData) ? $poolData : [];
            $name = PoolJsonFormat::poolName($poolData);
            $questions = self::questionsOf($poolData);
            $result = [
                'index' => $index,
                'name' => $name,
                'id' => null,
                'chapter_key' => PoolJsonFormat::fieldText($poolData, 'chapter_key'),
                'questions_total' => count($questions),
                'importable' => 0,
                'imported' => 0,
                'errors' => [],
                'question_errors' => [],
                'warnings' => [],
            ];

            if ($name === '') {
                $result['errors'][] = $l->t('Pool #%s missing name — skipped', [$index]);
                $results[] = $result;
                continue;
            }
            if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
                $result['errors'][] = $l->t('Pool #%1$s: name longer than %2$s characters — skipped', [$index, self::NAME_MAX_LENGTH]);
                $results[] = $result;
                continue;
            }
            $description = isset($poolData['description']) && is_scalar($poolData['description']) ? (string)$poolData['description'] : null;
            if ($description !== null && mb_strlen($description) > self::DESCRIPTION_MAX_LENGTH) {
                $result['errors'][] = $l->t('Pool \'%1$s\': description longer than %2$s characters — skipped', [$name, self::DESCRIPTION_MAX_LENGTH]);
                $results[] = $result;
                continue;
            }
            // The pool's own handbook/chapter fields go through the checks of PoolService::create()
            // before the dry-run branch: otherwise the preview promises a pool whose create() then
            // throws in the real import and rolls it back.
            $handbookKey = PoolJsonFormat::fieldText($poolData, 'handbook_key') ?? $defaultHandbookKey;
            $handbookTitle = PoolJsonFormat::fieldText($poolData, 'handbook_title') ?? $defaultHandbookTitle;
            $chapterTitle = PoolJsonFormat::fieldText($poolData, 'chapter_title');
            $chapterOrder = PoolJsonFormat::chapterOrder($poolData);
            try {
                $this->poolService->validateForImport($handbookKey, $handbookTitle, $result['chapter_key'], $chapterTitle, $chapterOrder);
            } catch (\InvalidArgumentException $e) {
                $result['errors'][] = self::poolRefusal($name, $e, $l);
                $results[] = $result;
                continue;
            }
            if ($questions === []) {
                $result['warnings'][] = $l->t('Pool \'%s\' contains no questions', [$name]);
            }

            // Each question once through every check; the real import stores what passes.
            $prepared = [];
            foreach ($questions as $qIndex => $q) {
                $args = $this->prepareQuestion($q, $qIndex, $poolData, $meta, $defaultType, $l, $result['question_errors']);
                if ($args !== null) {
                    $prepared[] = $args;
                }
            }
            $result['importable'] = count($prepared);

            if ($dryRun) {
                $results[] = $result;
                continue;
            }

            $questionErrors = [];
            $this->db->beginTransaction();
            try {
                $pool = $this->poolService->create(
                    $name,
                    $description,
                    $userId,
                    $handbookKey,
                    $handbookTitle,
                    $result['chapter_key'],
                    $chapterTitle,
                    $chapterOrder
                );
                $poolId = (int)$pool->getId();
                $imported = 0;
                foreach ($prepared as [$number, $q]) {
                    try {
                        $this->questionService->create(
                            $poolId,
                            $userId,
                            $q['text'],
                            $q['explanation'],
                            $q['difficulty'],
                            $q['answers'],
                            $q['type'],
                            $q['pbq_subtype'],
                            $q['pbq_config'],
                            null, // instructorNote
                            false, // noteVisible
                            $q['handbook_key'],
                            $q['handbook_title'],
                            $q['exam_key'],
                            $q['chapter_key'],
                            $q['chapter_title'],
                            $q['chapter_order'],
                            $q['scenario']
                        );
                        $imported++;
                    } catch (\InvalidArgumentException $e) {
                        // A refused input, thrown before any statement or inside create()'s own
                        // savepoint: the pool's transaction is intact, the question is skipped.
                        // Anything else (a failed statement) may have poisoned the transaction —
                        // on PostgreSQL a COMMIT would then roll back silently — so it fails the
                        // whole pool in the catch below.
                        $questionErrors[] = $l->t('Q%1$s: %2$s', [$number, self::localizedReason($e->getMessage(), $l) ?? $e->getMessage()]);
                    }
                }
                $this->db->commit();
                $result['id'] = $poolId;
                $result['imported'] = $imported;
                array_push($result['question_errors'], ...$questionErrors);
            } catch (\Throwable $e) {
                $this->db->rollBack();
                $this->logger->error('Pool import: pool could not be saved', ['exception' => $e, 'app' => 'learning']);
                // A refused input with a known reason says why; anything else only that the pool
                // failed — the details are in the log.
                $result['errors'][] = $e instanceof \InvalidArgumentException
                    ? self::poolRefusal($name, $e, $l)
                    : $l->t('Pool \'%s\' create failed — skipped', [$name]);
            }
            $results[] = $result;
        }

        return $results;
    }

    /**
     * Check one question item and return [number, arguments for QuestionService::create()], or
     * null after adding the reason to $errors. Question > pool > _meta, the same rule as the
     * import of questions into an open pool.
     *
     * @param array<mixed> $poolData
     * @param array<mixed> $meta
     * @param list<string> $errors
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function prepareQuestion(mixed $q, int $qIndex, array $poolData, array $meta, string $defaultType, IL10N $l, array &$errors): ?array {
        $number = is_array($q) ? PoolJsonFormat::scalarText($q['original_number'] ?? null) : '';
        $number = $number !== '' ? $number : (string)($qIndex + 1);

        // Flags are read first: several correct answers mean multi, also under a pbq default.
        $isPbq = is_array($q) && self::resolveQuestionType($q, $defaultType, self::answersFrom($q)) === 'pbq';
        if (!is_array($q) || PoolJsonFormat::scalarText($q['text'] ?? null) === ''
            || (!$isPbq && (empty($q['answers']) || !is_array($q['answers'])))) {
            $errors[] = $l->t('Q%s: missing text or answers — skipped', [$number]);
            return null;
        }

        $q = PoolJsonFormat::applyPoolDefaults($q, $poolData, $meta, $qIndex);

        $pbqSubtype = null;
        $pbqConfig = null;
        if ($isPbq) {
            $pbqSubtype = PoolJsonFormat::scalarText($q['subtype'] ?? $q['pbq_subtype'] ?? null);
            if (!in_array($pbqSubtype, PoolJsonFormat::PBQ_SUBTYPES, true)) {
                $errors[] = $l->t('Q%1$s: invalid PBQ subtype \'%2$s\' — skipped', [$number, $pbqSubtype]);
                return null;
            }
            $config = PoolJsonFormat::pbqConfig($q);
            if ($config === null) {
                $errors[] = $l->t('Q%s: PBQ needs a pbq_config object — skipped', [$number]);
                return null;
            }
            $pbqConfig = (string)json_encode($config);
        }

        $answers = $isPbq ? [] : self::answersFrom($q);
        $questionType = $isPbq ? 'pbq' : self::resolveQuestionType($q, $defaultType, $answers);

        // A type the app does not know skips this question — in the dry run and the import alike —
        // instead of being stored as it is or failing the pool on insert (GitHub #34).
        if (!in_array($questionType, QuestionService::QUESTION_TYPES, true)) {
            $errors[] = $l->t('Q%1$s: unknown question type \'%2$s\' (valid: %3$s) — skipped', [
                $number, mb_substr($questionType, 0, 80), implode(', ', QuestionService::QUESTION_TYPES),
            ]);
            return null;
        }

        // 'open' (fill-in / free-text) questions carry exactly ONE model answer;
        // MCQ (single/multi) need at least two options.
        if ($questionType === 'open') {
            if (count($answers) !== 1) {
                $errors[] = $l->t('Q%s: open question needs exactly 1 model answer — skipped', [$number]);
                return null;
            }
        } elseif ($questionType !== 'pbq' && count($answers) < 2) {
            $errors[] = $l->t('Q%s: needs at least 2 answers — skipped', [$number]);
            return null;
        }

        $args = [
            'text' => PoolJsonFormat::scalarText($q['text']),
            'explanation' => isset($q['explanation']) && is_scalar($q['explanation']) ? (string)$q['explanation'] : null,
            'difficulty' => PoolJsonFormat::difficulty($q) ?? 'medium',
            'answers' => $answers,
            'type' => $questionType,
            'pbq_subtype' => $pbqSubtype,
            'pbq_config' => $pbqConfig,
            'handbook_key' => PoolJsonFormat::fieldText($q, 'handbook_key'),
            'handbook_title' => PoolJsonFormat::fieldText($q, 'handbook_title'),
            'exam_key' => PoolJsonFormat::fieldText($q, 'exam_key'),
            'chapter_key' => PoolJsonFormat::fieldText($q, 'chapter_key'),
            'chapter_title' => PoolJsonFormat::fieldText($q, 'chapter_title'),
            'chapter_order' => PoolJsonFormat::chapterOrder($q),
            'scenario' => isset($q['scenario']) && is_string($q['scenario']) ? $q['scenario'] : null,
        ];

        // The rest of what QuestionService::create() refuses (lengths, answer counts, scenario,
        // metadata), so the dry run does not promise questions that the real import then drops.
        try {
            $this->questionService->validateForImport(
                $args['text'], $args['answers'], $args['type'], $args['scenario'],
                $args['handbook_key'], $args['handbook_title'], $args['exam_key'],
                $args['chapter_key'], $args['chapter_title'], $args['chapter_order']
            );
        } catch (\InvalidArgumentException $e) {
            $errors[] = $l->t('Q%1$s: %2$s', [$number, self::localizedReason($e->getMessage(), $l) ?? $e->getMessage()]);
            return null;
        }

        return [$number, $args];
    }

    /**
     * Why a pool was refused: the known reason of PoolService in the caller's language, or —
     * for any other text, which may come from deeper layers — only that the pool failed.
     */
    private static function poolRefusal(string $name, \InvalidArgumentException $e, IL10N $l): string {
        $reason = self::localizedReason($e->getMessage(), $l);
        return $reason !== null
            ? $l->t('Pool \'%1$s\': %2$s — skipped', [$name, $reason])
            : $l->t('Pool \'%s\' create failed — skipped', [$name]);
    }

    /**
     * The reason of a refused input in the caller's language, or null for a text that is not
     * one of the known fixed messages. QuestionService and PoolService throw fixed English
     * texts; each known one is passed through IL10N under the same English key (so occ, which
     * gets the English IL10N, prints exactly what it did).
     */
    private static function localizedReason(string $message, IL10N $l): ?string {
        return match ($message) {
            'Unknown question type' => $l->t('Unknown question type'),
            'Question text must be 1-5000 characters' => $l->t('Question text must be 1-5000 characters'),
            'Open questions need exactly 1 model answer' => $l->t('Open questions need exactly 1 model answer'),
            'Model answer text required' => $l->t('Model answer text required'),
            'Model answer max 2000 characters' => $l->t('Model answer max 2000 characters'),
            'Must have 2-8 answers' => $l->t('Must have 2-8 answers'),
            'Each answer needs non-empty text' => $l->t('Each answer needs non-empty text'),
            'Answer text must be max 2000 characters' => $l->t('Answer text must be max 2000 characters'),
            'At least one correct answer required' => $l->t('At least one correct answer required'),
            'Exactly one correct answer required' => $l->t('Exactly one correct answer required'),
            'Question metadata field exceeds maximum length' => $l->t('Question metadata field exceeds maximum length'),
            'Chapter order must be between 1 and 9999' => $l->t('Chapter order must be between 1 and 9999'),
            'Field exceeds maximum length' => $l->t('Field exceeds maximum length'),
            'Scenario must be max ' . QuestionService::SCENARIO_MAX_LENGTH . ' characters'
                => $l->t('Scenario must be max %s characters', [QuestionService::SCENARIO_MAX_LENGTH]),
            default => null,
        };
    }

    /**
     * @param array<mixed> $poolData
     * @return list<mixed>
     */
    private static function questionsOf(array $poolData): array {
        return isset($poolData['questions']) && is_array($poolData['questions']) ? array_values($poolData['questions']) : [];
    }

    /**
     * The answers of a question item as QuestionService::create() takes them.
     *
     * @param array<mixed> $question
     * @return list<array{text: string, is_correct: bool}>
     */
    private static function answersFrom(array $question): array {
        $answers = [];
        foreach (is_array($question['answers'] ?? null) ? $question['answers'] : [] as $a) {
            if (!is_array($a) || !isset($a['text'])) {
                continue;
            }
            $answers[] = [
                'text' => (string)$a['text'],
                'is_correct' => !empty($a['is_correct']),
            ];
        }
        return $answers;
    }

    /**
     * Question type of one imported question.
     *
     * Reads `question_type` (the pool JSON format's own key) and also `type` / `typ`, the keys
     * that `occ learning:export-pool` and the import in the interface use — otherwise an
     * exported pool loses its multi and open questions on the way back in. Without any of these
     * keys, several correct answers mean `multi`, as the import documentation says.
     *
     * The type is lower-cased (as ImportController::typeFrom() does) but not checked: a type
     * outside QuestionService::QUESTION_TYPES is returned as it is, and prepareQuestion() refuses
     * the question with it.
     *
     * @param array<mixed> $question
     * @param array<array{text: string, is_correct: bool}> $answers
     */
    public static function resolveQuestionType(array $question, string $defaultType, array $answers): string {
        foreach (['question_type', 'type', 'typ'] as $key) {
            if (isset($question[$key]) && is_string($question[$key]) && trim($question[$key]) !== '') {
                $type = strtolower(trim($question[$key]));
                // Historical names (true_false, multiple); the interface import maps them the same way.
                return QuestionService::TYPE_ALIASES[$type] ?? $type;
            }
        }

        $correct = count(array_filter($answers, static fn (array $a): bool => $a['is_correct']));
        if ($correct > 1) {
            return 'multi';
        }

        return $defaultType;
    }
}
