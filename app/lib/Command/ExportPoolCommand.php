<?php
declare(strict_types=1);

namespace OCA\Learning\Command;

use OCA\Learning\Service\DataMobilityService;
use OCA\Learning\Service\NotFoundException;
use OCA\Learning\Service\QuestionMediaService;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * OCC command to export a question pool.
 *
 * Usage: php occ learning:export-pool <poolId> [--format=json|csv] [--output=<path>] [--media-dir=<dir>]
 *
 * --media-dir writes the pool's question media (GitHub #66) as a bundle for
 * occ learning:import-question-media: format learning-question-media/2 when the pool has spoken
 * explanations per answer option, /1 otherwise. With --media-dir the JSON/CSV export is only
 * written when --output names a file (nothing goes to stdout but the report).
 */
class ExportPoolCommand extends Command {
    private const LIST_LIMIT = 50;

    private DataMobilityService $dataMobilityService;

    public function __construct(DataMobilityService $dataMobilityService, private ?QuestionMediaService $mediaService = null) {
        parent::__construct();
        $this->dataMobilityService = $dataMobilityService;
    }

    protected function configure(): void {
        $this
            ->setName('learning:export-pool')
            ->setDescription('Export a question pool as JSON or CSV')
            ->addArgument('poolId', InputArgument::REQUIRED, 'Pool ID to export')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format: json or csv', 'json')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write to file instead of stdout')
            ->addOption('media-dir', null, InputOption::VALUE_REQUIRED, 'Also write the question media as a bundle into this new or empty directory');
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
        $mediaDir = $input->getOption('media-dir');

        if (!is_string($format) || !in_array($format, ['json', 'csv'], true)) {
            $output->writeln('<error>--format must be "json" or "csv"</error>');
            return Command::INVALID;
        }

        $mediaCode = Command::SUCCESS;
        if (is_string($mediaDir) && $mediaDir !== '') {
            $mediaCode = $this->exportMedia($poolId, $mediaDir, $output);
            if (!is_string($outputPath) || $outputPath === '') {
                return $mediaCode;
            }
        }

        try {
            if ($format === 'csv') {
                $content = $this->dataMobilityService->exportPoolCsv($poolId);
            } else {
                // learning-pool/2: the pool's own fields plus its questions (docs/import-pool-workflow.md).
                $data = $this->dataMobilityService->exportPoolDocument($poolId);
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

        return $mediaCode;
    }

    private function exportMedia(int $poolId, string $dir, OutputInterface $output): int {
        if ($this->mediaService === null) {
            $output->writeln('<error>Question media are not available</error>');
            return Command::FAILURE;
        }
        try {
            $r = $this->mediaService->exportBundle($poolId, $dir);
        } catch (NotFoundException $e) {
            $output->writeln('<error>Pool ' . $poolId . ' not found</error>');
            return Command::FAILURE;
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $output->writeln('<error>Media export failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $output->writeln(sprintf('<info>Exported the media of pool %d to %s (format %s)</info>', $poolId, $dir, $r['format']));
        $output->writeln(sprintf('  questions:     %d', $r['questions']));
        $output->writeln(sprintf('  tracks:        %d', $r['tracks']));
        $output->writeln(sprintf('  option tracks: %d', $r['option_tracks']));
        $output->writeln(sprintf('  bytes:         %s', number_format($r['bytes'])));
        $problems = [
            'Questions with media but without exam_key (the import matches by it), left out' => array_map('strval', $r['without_key']),
            'exam_key used by several questions (the import cannot tell them apart), only the first exported' => array_values(array_unique($r['duplicate_key'])),
            'exam_key is not a plain folder name, option tracks left out' => $r['unusable_key'],
            'Files that could not be read from the app data' => $r['unreadable'],
        ];
        $count = 0;
        foreach ($problems as $title => $items) {
            if ($items === []) {
                continue;
            }
            $count += count($items);
            $output->writeln('<comment>' . $title . ':</comment>');
            foreach (array_slice($items, 0, self::LIST_LIMIT) as $item) {
                $output->writeln('  - ' . $item);
            }
            if (count($items) > self::LIST_LIMIT) {
                $output->writeln(sprintf('  … and %d more', count($items) - self::LIST_LIMIT));
            }
        }
        return $count === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
