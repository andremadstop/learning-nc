<?php
declare(strict_types=1);

namespace OCA\Learning\Command;

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
 * Usage:
 *   php occ learning:import-pool-json <file> --user=<uid> [--dry-run]
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

        if (!is_array($data) || !isset($data['pools']) || !is_array($data['pools'])) {
            $output->writeln('<error>JSON must contain a "pools" array</error>');
            return Command::FAILURE;
        }

        $meta = $data['_meta'] ?? [];
        $defaultHandbookKey = isset($meta['handbook_key']) && is_string($meta['handbook_key']) ? $meta['handbook_key'] : null;
        $defaultHandbookTitle = isset($meta['handbook_title']) && is_string($meta['handbook_title']) ? $meta['handbook_title'] : null;
        $defaultDifficulty = isset($meta['default_difficulty']) && is_string($meta['default_difficulty']) ? $meta['default_difficulty'] : 'medium';
        $defaultType = isset($meta['default_question_type']) && is_string($meta['default_question_type']) ? $meta['default_question_type'] : 'single';

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
            $handbookKey = $poolData['handbook_key'] ?? $defaultHandbookKey;
            $handbookTitle = $poolData['handbook_title'] ?? $defaultHandbookTitle;
            $chapterKey = isset($poolData['chapter_key']) ? (string)$poolData['chapter_key'] : null;
            $chapterTitle = isset($poolData['chapter_title']) ? (string)$poolData['chapter_title'] : null;
            $chapterOrder = isset($poolData['chapter_order']) ? (int)$poolData['chapter_order'] : null;
            $examKeyPrefix = isset($poolData['exam_key_prefix']) ? (string)$poolData['exam_key_prefix'] : null;

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

            foreach ($questions as $qIndex => $q) {
                if (!is_array($q) || empty($q['text']) || empty($q['answers']) || !is_array($q['answers'])) {
                    $errors[] = "Pool $poolId Q$qIndex: missing text or answers — skipped";
                    continue;
                }

                $text = (string)$q['text'];
                $explanation = isset($q['explanation']) ? (string)$q['explanation'] : null;
                $difficulty = isset($q['difficulty']) && is_string($q['difficulty']) ? $q['difficulty'] : $defaultDifficulty;
                $questionType = isset($q['question_type']) && is_string($q['question_type']) ? $q['question_type'] : $defaultType;
                $originalNumber = $q['original_number'] ?? ($qIndex + 1);
                $examKey = $q['exam_key'] ?? ($examKeyPrefix ? sprintf('%s-q%s', $examKeyPrefix, $originalNumber) : null);

                $answers = [];
                foreach ($q['answers'] as $a) {
                    if (!is_array($a) || !isset($a['text'])) {
                        continue;
                    }
                    $answers[] = [
                        'text' => (string)$a['text'],
                        'is_correct' => !empty($a['is_correct']),
                    ];
                }

                // 'open' (fill-in / free-text) questions carry exactly ONE model answer;
                // MCQ (single/multi) need at least two options. QuestionService::create enforces
                // the same rule — mirror it here so the pre-check does not drop valid open questions.
                if ($questionType === 'open') {
                    if (count($answers) !== 1) {
                        $errors[] = "Pool $poolId Q$originalNumber: open question needs exactly 1 model answer — skipped";
                        continue;
                    }
                } elseif (count($answers) < 2) {
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
                        null, // pbqSubtype
                        null, // pbqConfig
                        null, // instructorNote
                        false, // noteVisible
                        $handbookKey,
                        $handbookTitle,
                        $examKey,
                        $chapterKey,
                        $chapterTitle,
                        $chapterOrder
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
}
