<?php
declare(strict_types=1);

namespace OCA\Learning\Command;

use OCA\Learning\Service\NotFoundException;
use OCA\Learning\Service\QuestionMediaBundle;
use OCA\Learning\Service\QuestionMediaService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Import audio, timelines, cues and rich feedback per question from a media bundle (GitHub #66,
 * format learning-question-media/1 or /2, see QuestionMediaBundle) into an existing pool. Questions are
 * matched by exam_key. The files go into the app's own storage, not into a user's files.
 * Running it twice changes nothing the second time.
 *
 * Usage:
 *   php occ learning:import-question-media <poolId> <bundle-dir> [--dry-run]
 *
 * Exit code: 0 when every entry was matched and every listed file was read; 1 when the bundle or
 * the pool cannot be used at all, or when anything was unknown, ambiguous, missing, refused or
 * could not be written (the rest is imported all the same — a script must still notice).
 */
class ImportQuestionMediaCommand extends Command {
    private const LIST_LIMIT = 50;

    public function __construct(
        private QuestionMediaService $mediaService,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('learning:import-question-media')
            ->setDescription('Import audio, timelines, cues and feedback per question from a media bundle into a pool')
            ->addArgument('poolId', InputArgument::REQUIRED, 'ID of the pool whose questions the bundle belongs to (matched by exam_key)')
            ->addArgument('bundle-dir', InputArgument::REQUIRED, 'Directory with manifest.json (format learning-question-media/1 or /2)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Check everything and report, write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $poolArg = (string)$input->getArgument('poolId');
        if (!ctype_digit($poolArg) || (int)$poolArg <= 0) {
            $output->writeln('<error>poolId must be a positive number</error>');
            return Command::INVALID;
        }
        $dryRun = (bool)$input->getOption('dry-run');

        try {
            $bundle = QuestionMediaBundle::open((string)$input->getArgument('bundle-dir'));
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        try {
            $r = $this->mediaService->importBundle((int)$poolArg, $bundle, $dryRun);
        } catch (NotFoundException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $verb = $dryRun ? 'would be written' : 'written';
        $output->writeln(sprintf('<info>%s: pool %d, %d manifest entries, %d questions with exam_key in the pool</info>',
            $dryRun ? 'Dry-run' : 'Imported', (int)$poolArg, $r['entries'], $r['pool_questions']));
        $output->writeln(sprintf('  matched:            %d', $r['matched']));
        $output->writeln(sprintf('  unknown exam_key:   %d', count($r['unknown_keys'])));
        $output->writeln(sprintf('  ambiguous exam_key: %d', count($r['ambiguous_keys'])));
        $output->writeln(sprintf('  invalid entries:    %d', count($r['invalid_entries'])));
        $output->writeln(sprintf('  missing files:      %d', count($r['missing_files'])));
        $output->writeln(sprintf('  refused files:      %d', count($r['refused_files'])));
        $output->writeln(sprintf('  failed writes:      %d', count($r['write_failed'])));
        $output->writeln(sprintf('  tracks %s: %d (%s bytes), unchanged: %d, removed: %d',
            $verb, $r['tracks_written'], number_format($r['bytes_written']), $r['tracks_unchanged'], $r['tracks_removed']));
        if (($r['format'] ?? null) === QuestionMediaBundle::FORMAT_V2) {
            $output->writeln(sprintf('  option tracks %s: %d, unchanged: %d, removed: %d',
                $verb, $r['option_tracks_written'] ?? 0, $r['option_tracks_unchanged'] ?? 0, $r['option_tracks_removed'] ?? 0));
        }
        $output->writeln(sprintf('  cues %s: %d, unchanged: %d, removed: %d', $verb, $r['cues_written'], $r['cues_unchanged'], $r['cues_removed']));
        $output->writeln(sprintf('  feedback texts %s: %d, unchanged: %d', $verb, $r['feedback_updated'], $r['feedback_unchanged']));
        if ($r['dropped_languages'] !== []) {
            $parts = [];
            foreach ($r['dropped_languages'] as $lang => $count) {
                $parts[] = $lang . ' ×' . $count;
            }
            $output->writeln('  feedback texts in other languages than the question\'s, not imported: ' . implode(', ', $parts));
        }

        $this->listing($output, 'Unknown exam_key (no question of the pool has it)', $r['unknown_keys']);
        $this->listing($output, 'Ambiguous exam_key (several questions of the pool have it)', $r['ambiguous_keys']);
        $this->listing($output, 'Invalid entries', $r['invalid_entries']);
        $this->listing($output, 'Missing files', $r['missing_files']);
        $this->listing($output, 'Failed writes (storage or database error; rerun the import once it is fixed)', $r['write_failed']);
        $this->listing($output, 'Refused files', $r['refused_files']);
        $this->listing($output, 'Feedback warnings', $r['feedback_warnings']);

        $problems = count($r['unknown_keys']) + count($r['ambiguous_keys']) + count($r['invalid_entries'])
            + count($r['missing_files']) + count($r['refused_files']) + count($r['write_failed']);
        return $problems === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /** @param list<string> $items */
    private function listing(OutputInterface $output, string $title, array $items): void {
        if ($items === []) {
            return;
        }
        $output->writeln('<comment>' . $title . ':</comment>');
        foreach (array_slice($items, 0, self::LIST_LIMIT) as $item) {
            $output->writeln('  - ' . $item);
        }
        if (count($items) > self::LIST_LIMIT) {
            $output->writeln(sprintf('  … and %d more', count($items) - self::LIST_LIMIT));
        }
    }
}
