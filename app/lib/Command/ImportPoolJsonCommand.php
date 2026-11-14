<?php
declare(strict_types=1);

namespace OCA\Learning\Command;

use OCA\Learning\Service\PoolBulkImportService;
use OCP\IL10N;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Bulk-import pools + questions from a JSON file produced manually or by an
 * external parser. Symmetric to ExportPoolCommand. See
 * scripts/klr342-domain1-pool.json for the expected schema. The import itself is
 * PoolBulkImportService — the same one the pool import in the Pools tab uses.
 *
 * Also accepts a bare list of questions, or {"name"?, "description"?, "_meta"?, "questions": [...]},
 * as ONE pool — that is what `occ learning:export-pool` and the export in the interface write.
 * Its name comes from --pool-name, else the file's own "name" (or "pool_name"), else the file name without
 * extension (an export is called pool-<id>.json).
 *
 * Usage:
 *   php occ learning:import-pool-json <file> --user=<uid> [--pool-name=<name>] [--dry-run]
 */
class ImportPoolJsonCommand extends Command {
    /**
     * @param PoolBulkImportService $importer the same import the Pools tab uses (PoolImportController)
     * @param IL10N $l10n English (Application wires IFactory::get('learning', 'en')), so the
     *                    output does not change with the instance's default language
     */
    public function __construct(
        private PoolBulkImportService $importer,
        private IL10N $l10n,
    ) {
        parent::__construct();
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

        $results = $this->importer->import($data, $userId, $dryRun, $this->l10n);

        $totalPools = 0;
        $totalQuestions = 0;
        $errors = [];
        $poolCount = count($results);
        foreach ($results as $pool) {
            $created = $pool['id'] !== null;
            if ($pool['name'] !== '') {
                $output->writeln(sprintf(
                    '<info>Pool %d/%d:</info> %s (%d questions, chapter %s)',
                    $pool['index'],
                    $poolCount,
                    $pool['name'],
                    $pool['questions_total'],
                    $pool['chapter_key'] ?? '—'
                ));
            }
            if ($dryRun) {
                // What the real import would store: a pool that passes its own checks, and of it
                // only the questions that pass theirs — not every question item of the file.
                if ($pool['name'] !== '' && $pool['errors'] === []) {
                    $totalPools++;
                    $totalQuestions += $pool['importable'];
                }
            } elseif ($created) {
                $output->writeln('  → Pool ID: ' . $pool['id']);
                $totalPools++;
                $totalQuestions += $pool['imported'];
            }
            // Question messages get their pool in front for the flat list below.
            $label = $created ? (string)$pool['id'] : '#' . $pool['index'];
            array_push($errors, ...$pool['errors']);
            foreach ($pool['question_errors'] as $err) {
                $errors[] = "Pool $label $err";
            }
            foreach ($pool['warnings'] as $warning) {
                $errors[] = $warning;
            }
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>%s: %d pools, %d questions.</info>',
            $dryRun ? 'Dry-run' : 'Imported',
            $totalPools,
            $totalQuestions
        ));

        if (!empty($errors)) {
            $output->writeln('<comment>Warnings/Errors:</comment>');
            foreach ($errors as $err) {
                $output->writeln('  - ' . $err);
            }
        }

        // Nothing imported (or, in a dry run, nothing importable) and errors to show: a script
        // must not read that as success (2026-11 audit #136).
        if ($totalPools === 0 && $errors !== []) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * The file as a {_meta?, pools: [...]} document, or null — see PoolBulkImportService::asPoolsDocument().
     *
     * @param array<mixed> $data
     * @return array<mixed>|null
     */
    public static function asPoolsDocument(array $data, string $fallbackName, ?string $poolName = null): ?array {
        return PoolBulkImportService::asPoolsDocument($data, $fallbackName, $poolName);
    }

    /**
     * Question type of one imported question — see PoolBulkImportService::resolveQuestionType().
     *
     * @param array<mixed> $question
     * @param array<array{text: string, is_correct: bool}> $answers
     */
    public static function resolveQuestionType(array $question, string $defaultType, array $answers): string {
        return PoolBulkImportService::resolveQuestionType($question, $defaultType, $answers);
    }
}
