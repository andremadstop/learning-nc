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
 * OCC command to export a question pool.
 *
 * Usage: php occ learning:export-pool <poolId> [--format=json|csv] [--output=<path>]
 */
class ExportPoolCommand extends Command {
    private DataMobilityService $dataMobilityService;

    public function __construct(DataMobilityService $dataMobilityService) {
        parent::__construct();
        $this->dataMobilityService = $dataMobilityService;
    }

    protected function configure(): void {
        $this
            ->setName('learning:export-pool')
            ->setDescription('Export a question pool as JSON or CSV')
            ->addArgument('poolId', InputArgument::REQUIRED, 'Pool ID to export')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format: json or csv', 'json')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write to file instead of stdout');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $poolIdRaw = $input->getArgument('poolId');
        if (!is_string($poolIdRaw) || !ctype_digit($poolIdRaw) || (int)$poolIdRaw <= 0) {
            $output->writeln('<error>poolId must be a positive integer</error>');
            return Command::INVALID;
        }

        $poolId = (int)$poolIdRaw;
        $format = $input->getOption('format');
        $outputPath = $input->getOption('output');

        if (!is_string($format) || !in_array($format, ['json', 'csv'], true)) {
            $output->writeln('<error>--format must be "json" or "csv"</error>');
            return Command::INVALID;
        }

        try {
            if ($format === 'csv') {
                $content = $this->dataMobilityService->exportPoolCsv($poolId);
            } else {
                $data = $this->dataMobilityService->exportPoolJson($poolId);
                $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                if ($content === false) {
                    $output->writeln('<error>Failed to encode JSON</error>');
                    return Command::FAILURE;
                }
            }
        } catch (DoesNotExistException $e) {
            $output->writeln('<error>Pool ' . $poolId . ' not found</error>');
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
            $output->writeln('<info>Exported pool ' . $poolId . ' to ' . $outputPath . ' (' . $bytes . ' bytes)</info>');
        } else {
            $output->write($content);
        }

        return Command::SUCCESS;
    }
}
