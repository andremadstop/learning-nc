<?php
declare(strict_types=1);

namespace OCA\Learning\Command;

use OCA\Learning\Service\DataMobilityService;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * OCC command to export a course with pools, members, and statistics.
 *
 * Usage: php occ learning:export-course <courseId> [--format=json|csv] [--output=<path>]
 */
class ExportCourseCommand extends Command {
    private DataMobilityService $dataMobilityService;

    public function __construct(DataMobilityService $dataMobilityService) {
        parent::__construct();
        $this->dataMobilityService = $dataMobilityService;
    }

    protected function configure(): void {
        $this
            ->setName('learning:export-course')
            ->setDescription('Export a course with pools, members, and statistics')
            ->addArgument('courseId', InputArgument::REQUIRED, 'Course ID to export')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format: json (full) or csv (stats only)', 'json')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write to file instead of stdout');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $courseIdRaw = $input->getArgument('courseId');
        if (!is_string($courseIdRaw) || !ctype_digit($courseIdRaw) || (int)$courseIdRaw <= 0) {
            $output->writeln('<error>courseId must be a positive integer</error>');
            return Command::INVALID;
        }

        $courseId = (int)$courseIdRaw;
        $format = $input->getOption('format');
        $outputPath = $input->getOption('output');

        if (!is_string($format) || !in_array($format, ['json', 'csv'], true)) {
            $output->writeln('<error>--format must be "json" or "csv"</error>');
            return Command::INVALID;
        }

        try {
            if ($format === 'csv') {
                $content = $this->dataMobilityService->exportCourseStatsCsv($courseId);
            } else {
                $data = $this->dataMobilityService->exportCourse($courseId);
                $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                if ($content === false) {
                    $output->writeln('<error>Failed to encode JSON</error>');
                    return Command::FAILURE;
                }
            }
        } catch (DoesNotExistException $e) {
            $output->writeln('<error>Course ' . $courseId . ' not found</error>');
            return Command::FAILURE;
        } catch (\Throwable $e) {
            $output->writeln('<error>Export failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if (is_string($outputPath) && $outputPath !== '') {
            $bytes = file_put_contents($outputPath, $content);
            if ($bytes === false) {
                $output->writeln('<error>Failed to write to ' . $outputPath . '</error>');
                return Command::FAILURE;
            }
            $output->writeln('<info>Exported course ' . $courseId . ' to ' . $outputPath . ' (' . $bytes . ' bytes)</info>');
        } else {
            $output->write($content);
        }

        return Command::SUCCESS;
    }
}
