<?php
declare(strict_types=1);

namespace OCA\Learning\Command;

use OCA\Learning\Service\CourseMergeService;
use OCA\Learning\Service\ForbiddenException;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * OCC command to merge a source course into a target course.
 *
 * Usage: php occ learning:merge-course <sourceCourseId> <targetCourseId> [--dry-run] [--force]
 */
class MergeCourseCommand extends Command {
    private CourseMergeService $mergeService;

    public function __construct(CourseMergeService $mergeService) {
        parent::__construct();
        $this->mergeService = $mergeService;
    }

    protected function configure(): void {
        $this
            ->setName('learning:merge-course')
            ->setDescription('Merge a source course into a target course (copies members, Leitner items, stats)')
            ->addArgument('sourceCourseId', InputArgument::REQUIRED, 'Source course ID (will be archived)')
            ->addArgument('targetCourseId', InputArgument::REQUIRED, 'Target course ID (receives data)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview what would happen without making changes')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $sourceRaw = $input->getArgument('sourceCourseId');
        $targetRaw = $input->getArgument('targetCourseId');

        if (!is_string($sourceRaw) || !ctype_digit($sourceRaw) || (int) $sourceRaw <= 0) {
            $output->writeln('<error>sourceCourseId must be a positive integer</error>');
            return Command::INVALID;
        }
        if (!is_string($targetRaw) || !ctype_digit($targetRaw) || (int) $targetRaw <= 0) {
            $output->writeln('<error>targetCourseId must be a positive integer</error>');
            return Command::INVALID;
        }

        $sourceCourseId = (int) $sourceRaw;
        $targetCourseId = (int) $targetRaw;
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');

        try {
            // Always show dry-run first
            $dryReport = $this->mergeService->mergeCourse($sourceCourseId, $targetCourseId, 'admin', true);
            $this->printReport($output, $dryReport, true);

            if ($dryRun) {
                return Command::SUCCESS;
            }

            // Confirm unless --force
            if (!$force) {
                $output->writeln('');
                $output->writeln('<comment>This will archive course ' . $sourceCourseId . ' and merge data into course ' . $targetCourseId . '.</comment>');
                $output->writeln('<comment>Use --force to skip this prompt or --dry-run to preview only.</comment>');
                $output->writeln('');

                /** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
                $helper = $this->getHelper('question');
                $question = new \Symfony\Component\Console\Question\ConfirmationQuestion(
                    'Continue with merge? [y/N] ',
                    false
                );
                if (!$helper->ask($input, $output, $question)) {
                    $output->writeln('<info>Merge aborted.</info>');
                    return Command::SUCCESS;
                }
            }

            // Execute merge
            $report = $this->mergeService->mergeCourse($sourceCourseId, $targetCourseId, 'admin', false);
            $output->writeln('');
            $this->printReport($output, $report, false);
            $output->writeln('<info>Merge completed successfully.</info>');

            return Command::SUCCESS;
        } catch (DoesNotExistException $e) {
            $output->writeln('<error>Course not found</error>');
            return Command::FAILURE;
        } catch (ForbiddenException $e) {
            $output->writeln('<error>Not authorized: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::INVALID;
        } catch (\Throwable $e) {
            $output->writeln('<error>Merge failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    /**
     * @param array<string, mixed> $report
     */
    private function printReport(OutputInterface $output, array $report, bool $isDryRun): void {
        $prefix = $isDryRun ? '[DRY-RUN] ' : '';
        $output->writeln($prefix . 'Members copied:  ' . $report['members_copied']);
        $output->writeln($prefix . 'Members skipped: ' . $report['members_skipped']);
        $output->writeln($prefix . 'Items copied:    ' . $report['items_copied']);
        $output->writeln($prefix . 'Items skipped:   ' . $report['items_skipped']);
        $output->writeln($prefix . 'Stats merged:    ' . $report['stats_merged']);

        if (isset($report['pool_mappings']) && is_array($report['pool_mappings'])) {
            $output->writeln($prefix . 'Pool mappings:   ' . count($report['pool_mappings']));
        }
        if (isset($report['question_mappings_count'])) {
            $output->writeln($prefix . 'Question mappings: ' . $report['question_mappings_count']);
        }
        if (isset($report['snapshot_id']) && $report['snapshot_id'] !== null) {
            $output->writeln($prefix . 'Snapshot ID:     ' . $report['snapshot_id']);
        }
    }
}
