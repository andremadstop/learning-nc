<?php
declare(strict_types=1);

namespace OCA\Learning\Command;

use OCA\Learning\Service\CourseArchiveService;
use OCA\Learning\Service\ForbiddenException;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * OCC command to archive a course: creates a snapshot and sets status to 'archived'.
 *
 * Usage: php occ learning:archive-course <courseId> [--output=<path>]
 */
class ArchiveCourseCommand extends Command {
    private CourseArchiveService $archiveService;

    public function __construct(CourseArchiveService $archiveService) {
        parent::__construct();
        $this->archiveService = $archiveService;
    }

    protected function configure(): void {
        $this
            ->setName('learning:archive-course')
            ->setDescription('Archive a course: create snapshot and set status to archived')
            ->addArgument('courseId', InputArgument::REQUIRED, 'Course ID to archive')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Also export snapshot to file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $courseIdRaw = $input->getArgument('courseId');
        if (!is_string($courseIdRaw) || !ctype_digit($courseIdRaw) || (int) $courseIdRaw <= 0) {
            $output->writeln('<error>courseId must be a positive integer</error>');
            return Command::INVALID;
        }

        $courseId = (int) $courseIdRaw;
        $outputPath = $input->getOption('output');

        try {
            // OCC runs as admin context — use 'admin' as userId
            $result = $this->archiveService->archiveCourse($courseId, 'admin');
            $output->writeln('<info>Course ' . $courseId . ' archived. Snapshot ID: ' . $result['snapshot_id'] . '</info>');

            // Optionally export to file
            if (is_string($outputPath) && $outputPath !== '') {
                $snapshotData = $this->archiveService->getSnapshot($result['snapshot_id'], 'admin');
                if ($snapshotData !== null && isset($snapshotData['snapshot'])) {
                    $json = json_encode($snapshotData['snapshot'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    if ($json !== false) {
                        $bytes = file_put_contents($outputPath, $json);
                        if ($bytes !== false) {
                            $output->writeln('<info>Snapshot exported to ' . $outputPath . ' (' . $bytes . ' bytes)</info>');
                        } else {
                            $output->writeln('<error>Failed to write to ' . $outputPath . '</error>');
                            return Command::FAILURE;
                        }
                    }
                }
            }

            return Command::SUCCESS;
        } catch (DoesNotExistException $e) {
            $output->writeln('<error>Course ' . $courseId . ' not found</error>');
            return Command::FAILURE;
        } catch (ForbiddenException $e) {
            $output->writeln('<error>Not authorized: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        } catch (\Throwable $e) {
            $output->writeln('<error>Archive failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
