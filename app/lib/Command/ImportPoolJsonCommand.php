<?php
declare(strict_types=1);

namespace OCA\Learning\Command;

use OCA\Learning\Service\PoolJsonFormat;
use OCA\Learning\Service\PoolService;
use OCA\Learning\Service\QuestionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Bulk-import pools + questions from a JSON file produced manually or by an
 * external parser. Symmetric to ExportPoolCommand. See
 * scripts/klr342-domain1-pool.json for the expected schema.
 *
 * Also accepts a bare list of questions, or {"name"?, "description"?, "_meta"?, "questions": [...]},
 * as ONE pool — that is what `occ learning:export-pool` and the export in the interface write.
 * Its name comes from --pool-name, else the file's own "name", else the file name without
 * extension (an export is called pool-<id>.json).
 *
 * Usage:
 *   php occ learning:import-pool-json <file> --user=<uid> [--pool-name=<name>] [--dry-run]
 */
class ImportPoolJsonCommand extends Command {
    private PoolService $poolService;
    private QuestionService $questionService;

    public function __construct(PoolService $poolService, QuestionService $questionService) {
        parent::__construct();
        $this->poolService = $poolService;
        $this->questionService = $questionService;
    }

    protected function configure(): void {
        $this
            ->setName('learning:import-pool-json')
            ->setDescription('Bulk-import question pools from a JSON file')
            ->addArgument('file', InputArgument::REQUIRED, 'Path to JSON file inside the container')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Owner user ID for new pools')
            ->addOption('pool-name', null, InputOption::VALUE_REQUIRED, 'Pool name for a file that is a bare question list (e.g. an export); default: the file name')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Parse and validate without writing to DB');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $file = $input->getArgument('file');
        $userId = $input->getOption('user');
        $dryRun = (bool)$input->getOption('dry-run');

        if (!is_string($userId) || $userId === '') {
            $output->writeln('<error>--user is required</error>');
            return Command::INVALID;
        }
        if (!is_string($file) || !is_file($file)) {
            $output->writeln('<error>File not found: ' . $file . '</error>');
            return Command::INVALID;
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            $output->writeln('<error>Cannot read file: ' . $file . '</error>');
            return Command::FAILURE;
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $output->writeln('<error>Invalid JSON: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $poolName = $input->getOption('pool-name');
        $poolName = is_string($poolName) && trim($poolName) !== '' ? trim($poolName) : null;
        if (is_array($data) && isset($data['pools']) && $poolName !== null) {
            $output->writeln('<error>--pool-name is only for a file with one unnamed pool (a question list or an export); this file names its pools itself</error>');
            return Command::INVALID;
        }
        $data = is_array($data) ? self::asPoolsDocument($data, pathinfo($file, PATHINFO_FILENAME), $poolName) : null;
        if ($data === null) {
            $output->writeln('<error>JSON must contain a "pools" array, a "questions" array, or be a list of questions</error>');
            return Command::FAILURE;
        }

        $meta = isset($data['_meta']) && is_array($data['_meta']) ? $data['_meta'] : [];
        $defaultHandbookKey = PoolJsonFormat::fieldText($meta, 'handbook_key');
        $defaultHandbookTitle = PoolJsonFormat::fieldText($meta, 'handbook_title');
        $defaultType = PoolJsonFormat::defaultQuestionType($meta) ?? 'single';

        $totalPools = 0;
        $totalQuestions = 0;
        $errors = [];

        foreach ($data['pools'] as $poolIndex => $poolData) {
            if (!is_array($poolData) || empty($poolData['name'])) {
                $errors[] = "Pool #$poolIndex missing name — skipped";
                continue;
            }
            $name = (string)$poolData['name'];
            $description = isset($poolData['description']) ? (string)$poolData['description'] : null;
            // Pool-level fields of the new pool itself; questions get theirs per question below.
            $handbookKey = PoolJsonFormat::fieldText($poolData, 'handbook_key') ?? $defaultHandbookKey;
            $handbookTitle = PoolJsonFormat::fieldText($poolData, 'handbook_title') ?? $defaultHandbookTitle;
            $chapterKey = PoolJsonFormat::fieldText($poolData, 'chapter_key');
            $chapterTitle = PoolJsonFormat::fieldText($poolData, 'chapter_title');
            $chapterOrder = PoolJsonFormat::chapterOrder($poolData);

            $questions = isset($poolData['questions']) && is_array($poolData['questions']) ? $poolData['questions'] : [];

            $output->writeln(sprintf(
                '<info>Pool %d/%d:</info> %s (%d Fragen, chapter %s)',
                $poolIndex + 1,
                count($data['pools']),
                $name,
                count($questions),
                $chapterKey ?? '—'
            ));

            if ($dryRun) {
                $totalPools++;
                $totalQuestions += count($questions);
                continue;
            }

            try {
                $pool = $this->poolService->create(
                    $name,
                    $description,
                    $userId,
                    $handbookKey,
                    $handbookTitle,
                    $chapterKey,
                    $chapterTitle,
                    $chapterOrder
                );
            } catch (\Throwable $e) {
                $errors[] = "Pool '$name' create failed: " . $e->getMessage();
                continue;
            }

            $poolId = $pool->getId();
            $output->writeln("  → Pool ID: $poolId");
            $totalPools++;

            foreach (array_values($questions) as $qIndex => $q) {
                // Flags are read first: several correct answers mean multi, also under a pbq default.
                $isPbq = is_array($q) && self::resolveQuestionType($q, $defaultType, self::answersFrom($q)) === 'pbq';
                if (!is_array($q) || PoolJsonFormat::scalarText($q['text'] ?? null) === ''
                    || (!$isPbq && (empty($q['answers']) || !is_array($q['answers'])))) {
                    $errors[] = "Pool $poolId Q$qIndex: missing text or answers — skipped";
                    continue;
                }

                // Question > pool > _meta, the same rule as the import in the interface.
                $q = PoolJsonFormat::applyPoolDefaults($q, $poolData, $meta, $qIndex);

                $text = PoolJsonFormat::scalarText($q['text']);
                $explanation = isset($q['explanation']) ? (string)$q['explanation'] : null;
                $difficulty = PoolJsonFormat::difficulty($q) ?? 'medium';
                $originalNumber = $q['original_number'] ?? ($qIndex + 1);

                $pbqSubtype = null;
                $pbqConfig = null;
                if ($isPbq) {
                    $pbqSubtype = PoolJsonFormat::scalarText($q['subtype'] ?? $q['pbq_subtype'] ?? null);
                    if (!in_array($pbqSubtype, PoolJsonFormat::PBQ_SUBTYPES, true)) {
                        $errors[] = "Pool $poolId Q$originalNumber: invalid PBQ subtype '$pbqSubtype' — skipped";
                        continue;
                    }
                    $config = PoolJsonFormat::pbqConfig($q);
                    if ($config === null) {
                        $errors[] = "Pool $poolId Q$originalNumber: PBQ needs a pbq_config object — skipped";
                        continue;
                    }
                    $pbqConfig = (string)json_encode($config);
                }

                $answers = $isPbq ? [] : self::answersFrom($q);

                $questionType = $isPbq ? 'pbq' : self::resolveQuestionType($q, $defaultType, $answers);

                // 'open' (fill-in / free-text) questions carry exactly ONE model answer;
                // MCQ (single/multi) need at least two options. QuestionService::create enforces
                // the same rule — mirror it here so the pre-check does not drop valid open questions.
                if ($questionType === 'open') {
                    if (count($answers) !== 1) {
                        $errors[] = "Pool $poolId Q$originalNumber: open question needs exactly 1 model answer — skipped";
                        continue;
                    }
                } elseif ($questionType !== 'pbq' && count($answers) < 2) {
                    $errors[] = "Pool $poolId Q$originalNumber: needs at least 2 answers — skipped";
                    continue;
                }

                try {
                    $this->questionService->create(
                        $poolId,
                        $userId,
                        $text,
                        $explanation,
                        $difficulty,
                        $answers,
                        $questionType,
                        $pbqSubtype,
                        $pbqConfig,
                        null, // instructorNote
                        false, // noteVisible
                        PoolJsonFormat::fieldText($q, 'handbook_key'),
                        PoolJsonFormat::fieldText($q, 'handbook_title'),
                        PoolJsonFormat::fieldText($q, 'exam_key'),
                        PoolJsonFormat::fieldText($q, 'chapter_key'),
                        PoolJsonFormat::fieldText($q, 'chapter_title'),
                        PoolJsonFormat::chapterOrder($q),
                        isset($q['scenario']) && is_string($q['scenario']) ? $q['scenario'] : null
                    );
                    $totalQuestions++;
                } catch (\Throwable $e) {
                    $errors[] = "Pool $poolId Q$originalNumber: " . $e->getMessage();
                }
            }
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>%s: %d Pools, %d Fragen.</info>',
            $dryRun ? 'Dry-run' : 'Imported',
            $totalPools,
            $totalQuestions
        ));

        if (!empty($errors)) {
            $output->writeln('<comment>Warnings/Errors:</comment>');
            foreach ($errors as $err) {
                $output->writeln('  - ' . $err);
            }
            return Command::SUCCESS;
        }

        return Command::SUCCESS;
    }

    /**
     * The file as a {_meta?, pools: [...]} document, or null when it is none of the accepted shapes.
     * A bare list of questions, or an object with a "questions" list, becomes one pool named
     * $poolName (--pool-name) if given, else by the file's own "name", else $fallbackName.
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
            $name = PoolJsonFormat::scalarText($data['name'] ?? null);
            $pool = ['name' => $poolName ?? ($name !== '' ? $name : $fallbackName), 'questions' => $data['questions']];
            if (isset($data['description']) && is_string($data['description'])) {
                $pool['description'] = $data['description'];
            }
            return ['_meta' => $data['_meta'] ?? [], 'pools' => [$pool]];
        }
        return null;
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
     * Reads `question_type` (this command's own format) and also `type` / `typ`, the keys
     * that `occ learning:export-pool` and the pool import in the interface use — otherwise
     * an exported pool loses its multi and open questions on the way back in. Without any
     * of these keys, several correct answers mean `multi`, as the import documentation says.
     *
     * @param array<mixed> $question
     * @param array<array{text: string, is_correct: bool}> $answers
     */
    public static function resolveQuestionType(array $question, string $defaultType, array $answers): string {
        foreach (['question_type', 'type', 'typ'] as $key) {
            if (isset($question[$key]) && is_string($question[$key]) && trim($question[$key]) !== '') {
                $type = trim($question[$key]);
                // true_false was removed in v3.7.0; the interface import maps it the same way.
                return $type === 'true_false' ? 'single' : $type;
            }
        }

        $correct = count(array_filter($answers, static fn (array $a): bool => $a['is_correct']));
        if ($correct > 1) {
            return 'multi';
        }

        return $defaultType;
    }
}
