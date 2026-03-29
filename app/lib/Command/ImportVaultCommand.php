<?php
declare(strict_types=1);

namespace OCA\Learning\Command;

use OCA\Learning\Db\RagChunk;
use OCA\Learning\Db\RagChunkMapper;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * OCC command to import Obsidian Markdown vaults as RAG chunks.
 *
 * Usage: php occ learning:import-vault /data/comptia-vault --course-id=20 --dry-run
 */
class ImportVaultCommand extends Command {
    private RagChunkMapper $chunkMapper;
    private LoggerInterface $logger;

    /** Target tokens per chunk (~4 chars per token heuristic) */
    private const TARGET_TOKENS = 500;
    private const CHARS_PER_TOKEN = 4;

    /** Minimum cleaned file length to be imported */
    private const MIN_FILE_LENGTH = 50;

    /** Directory names to exclude from import */
    private const EXCLUDED_DIRS = ['/Eigene-Notizen/', '/.obsidian/', '/Bilder/', '/.venv/', '/tools/'];

    public function __construct(
        RagChunkMapper $chunkMapper,
        LoggerInterface $logger
    ) {
        parent::__construct();
        $this->chunkMapper = $chunkMapper;
        $this->logger = $logger;
    }

    protected function configure(): void {
        $this
            ->setName('learning:import-vault')
            ->setDescription('Import Obsidian Markdown vault as RAG chunks')
            ->addArgument('vault-path', InputArgument::REQUIRED, 'Absolute path to vault directory')
            ->addOption('course-id', null, InputOption::VALUE_REQUIRED, 'Course ID to associate chunks with')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview matching files and chunks without DB writes')
            ->addOption('pattern', null, InputOption::VALUE_REQUIRED, 'Glob pattern for markdown files', '**/*.md')
            ->addOption('exclude', null, InputOption::VALUE_REQUIRED, 'Comma-separated exclude patterns', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $path = $input->getArgument('vault-path');
        $courseIdRaw = $input->getOption('course-id');
        $pattern = $input->getOption('pattern');
        $excludeRaw = $input->getOption('exclude');
        $dryRun = (bool) $input->getOption('dry-run');

        if (!is_string($path) || !is_dir($path)) {
            $output->writeln('<error>vault-path must be an existing directory</error>');
            return Command::INVALID;
        }

        if (!is_string($courseIdRaw) || !ctype_digit($courseIdRaw) || (int) $courseIdRaw <= 0) {
            $output->writeln('<error>--course-id must be a positive integer</error>');
            return Command::INVALID;
        }

        if (!is_string($pattern) || trim($pattern) === '') {
            $output->writeln('<error>--pattern must be a non-empty glob</error>');
            return Command::INVALID;
        }

        if (!is_string($excludeRaw)) {
            $output->writeln('<error>--exclude must be a comma-separated string</error>');
            return Command::INVALID;
        }

        $courseId = (int) $courseIdRaw;
        $vaultRoot = rtrim($path, '/');
        $excludePatterns = array_values(array_filter(array_map('trim', explode(',', $excludeRaw))));
        $existingSources = $this->getExistingVaultSources($courseId);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($vaultRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        $filesMatched = 0;
        $filesImported = 0;
        $totalChunks = 0;
        $totalTokens = 0;
        $skippedExisting = 0;
        $skippedExcluded = 0;
        $skippedShort = 0;
        $skippedUnreadable = 0;

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (strtolower($file->getExtension()) !== 'md') {
                continue;
            }

            $fullPath = $file->getPathname();
            $relativePath = substr($fullPath, strlen($vaultRoot) + 1);
            if (!is_string($relativePath) || $relativePath === '') {
                continue;
            }

            $relativePath = str_replace('\\', '/', $relativePath);
            if (!$this->matchesGlob($relativePath, $pattern)) {
                continue;
            }

            if ($this->isExcludedPath($relativePath, $excludePatterns)) {
                $skippedExcluded++;
                continue;
            }

            if (isset($existingSources[$relativePath])) {
                $skippedExisting++;
                $output->writeln("  <comment>[SKIP]</comment> {$relativePath} (already imported)");
                continue;
            }

            $rawContent = file_get_contents($fullPath);
            if ($rawContent === false) {
                $skippedUnreadable++;
                $output->writeln("  <comment>[WARN]</comment> {$relativePath} (cannot read)");
                continue;
            }

            $cleaned = $this->cleanMarkdown($rawContent);
            if (mb_strlen($cleaned) < self::MIN_FILE_LENGTH) {
                $skippedShort++;
                continue;
            }

            $filesMatched++;
            $chunks = $this->splitIntoChunks($cleaned, $this->detectFallbackChapter($relativePath, $vaultRoot));
            $fileChunkCount = count($chunks);
            $fileTokenCount = 0;

            foreach ($chunks as $chunkData) {
                $fileTokenCount += $this->estimateTokens($chunkData['text']);
            }

            if ($dryRun) {
                $filesImported++;
                $totalChunks += $fileChunkCount;
                $totalTokens += $fileTokenCount;
                $output->writeln("  <info>[OK]</info> {$relativePath} -> {$fileChunkCount} chunks");
                continue;
            }

            $now = time();
            foreach ($chunks as $index => $chunkData) {
                $tokenCount = $this->estimateTokens($chunkData['text']);

                $chunk = new RagChunk();
                $chunk->setDocumentId(0);
                $chunk->setCourseId($courseId);
                $chunk->setChapter($chunkData['chapter']);
                $chunk->setText($chunkData['text']);
                $chunk->setSourceFile($relativePath);
                $chunk->setChunkIndex($index);
                $chunk->setTokenCount($tokenCount);
                $chunk->setCreatedAt($now);
                $chunk->setSourceType('vault');
                $chunk->setStatus('approved');

                $this->chunkMapper->insert($chunk);
                $totalChunks += 1;
                $totalTokens += $tokenCount;
            }

            $filesImported++;
            $existingSources[$relativePath] = true;
            $output->writeln("  <info>[OK]</info> {$relativePath} -> {$fileChunkCount} chunks");
        }

        $output->writeln('');
        if ($dryRun) {
            $output->writeln("Would import {$filesImported} files ({$totalChunks} chunks) from {$vaultRoot}");
        } else {
            $output->writeln("Imported {$filesImported} files ({$totalChunks} chunks) into course {$courseId}");
        }
        $output->writeln("  Files matched: {$filesMatched}");
        $output->writeln("  Chunks: {$totalChunks}");
        $output->writeln("  Tokens: {$totalTokens}");
        $output->writeln("  Skipped existing: {$skippedExisting}");
        $output->writeln("  Skipped excluded: {$skippedExcluded}");
        $output->writeln("  Skipped short: {$skippedShort}");
        $output->writeln("  Skipped unreadable: {$skippedUnreadable}");

        $this->logger->info(
            'ImportVaultCommand: {mode} {files} files ({chunks} chunks, {tokens} tokens) for course {courseId} from {path}',
            [
                'mode' => $dryRun ? 'previewed' : 'imported',
                'files' => $filesImported,
                'chunks' => $totalChunks,
                'tokens' => $totalTokens,
                'courseId' => $courseId,
                'path' => $vaultRoot,
                'pattern' => $pattern,
                'exclude' => $excludePatterns,
                'app' => 'learning',
            ]
        );

        return Command::SUCCESS;
    }

    /**
     * @return array<string, true>
     */
    private function getExistingVaultSources(int $courseId): array {
        $existingSources = [];

        foreach ($this->chunkMapper->findByCourseId($courseId) as $chunk) {
            if ($chunk->getDocumentId() !== 0) {
                continue;
            }

            $sourceFile = $chunk->getSourceFile();
            if ($sourceFile !== '') {
                $existingSources[$sourceFile] = true;
            }
        }

        return $existingSources;
    }

    /**
     * @param string[] $excludePatterns
     */
    private function isExcludedPath(string $relativePath, array $excludePatterns): bool {
        $checkPath = '/' . $relativePath;
        foreach (self::EXCLUDED_DIRS as $excluded) {
            if (strpos($checkPath, $excluded) !== false) {
                return true;
            }
        }

        foreach ($excludePatterns as $pattern) {
            if (
                $this->matchesGlob($relativePath, $pattern)
                || $this->matchesGlob(basename($relativePath), $pattern)
            ) {
                return true;
            }
        }

        return false;
    }

    private function detectFallbackChapter(string $relativePath, string $vaultRoot): ?string {
        $parentDir = basename(dirname($vaultRoot . '/' . $relativePath));
        if ($parentDir === '.' || $parentDir === basename($vaultRoot)) {
            return null;
        }

        return $parentDir;
    }

    private function matchesGlob(string $relativePath, string $pattern): bool {
        $pattern = trim(str_replace('\\', '/', $pattern));
        if ($pattern === '') {
            return false;
        }

        $quoted = preg_quote($pattern, '~');
        $quoted = str_replace('\*\*/', '(?:.*/)?', $quoted);
        $quoted = str_replace('\*\*', '.*', $quoted);
        $quoted = str_replace('\*', '[^/]*', $quoted);
        $quoted = str_replace('\?', '.', $quoted);

        return preg_match('~^' . $quoted . '$~u', $relativePath) === 1;
    }

    /**
     * Clean Obsidian Markdown: strip frontmatter, image embeds, wikilinks, callouts.
     */
    private function cleanMarkdown(string $content): string {
        // Strip YAML frontmatter
        $content = preg_replace('/\A---\s*\n.*?\n---\s*\n/s', '', $content) ?? $content;

        // Remove image embeds: ![[...]]
        $content = preg_replace('/!\[\[.*?\]\]/', '', $content) ?? $content;

        // Resolve wikilinks with optional alias: [[target|alias]] -> alias, [[target]] -> target
        $content = preg_replace('/\[\[(?:[^|\]]*\|)?([^\]]+)\]\]/', '$1', $content) ?? $content;

        // Convert callouts: > [!type] text -> type: text
        $content = preg_replace('/^>\s*\[!(\w+)\]\s*(.*)$/m', '$1: $2', $content) ?? $content;

        // Collapse 3+ newlines to 2
        $content = preg_replace('/\n{3,}/', "\n\n", $content) ?? $content;

        return trim($content);
    }

    /**
     * @param string|null $fallbackChapter Parent directory name as fallback
     * @return array<int, array{text: string, chapter: string|null}>
     */
    private function splitIntoChunks(string $text, ?string $fallbackChapter): array {
        $paragraphs = preg_split('/\n{2,}/', $text);
        if ($paragraphs === false) {
            return [['text' => $text, 'chapter' => $fallbackChapter]];
        }

        $chunks = [];
        $currentBuffer = '';
        $chapter = $fallbackChapter;

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            $heading = $this->detectHeading($paragraph);
            if ($heading !== null) {
                if (trim($currentBuffer) !== '') {
                    $chunks[] = ['text' => trim($currentBuffer), 'chapter' => $chapter];
                    $currentBuffer = '';
                }
                $chapter = $heading;
                continue;
            }

            $paragraphTokens = $this->estimateTokens($paragraph);
            $bufferTokens = $this->estimateTokens($currentBuffer);

            if ($paragraphTokens > self::TARGET_TOKENS) {
                if (trim($currentBuffer) !== '') {
                    $chunks[] = ['text' => trim($currentBuffer), 'chapter' => $chapter];
                    $currentBuffer = '';
                }

                foreach ($this->splitBySentences($paragraph, $chapter) as $sentenceChunk) {
                    $chunks[] = $sentenceChunk;
                }
                continue;
            }

            if ($bufferTokens + $paragraphTokens > self::TARGET_TOKENS && trim($currentBuffer) !== '') {
                $chunks[] = ['text' => trim($currentBuffer), 'chapter' => $chapter];
                $currentBuffer = '';
            }

            $currentBuffer .= ($currentBuffer !== '' ? "\n\n" : '') . $paragraph;
        }

        if (trim($currentBuffer) !== '') {
            $chunks[] = ['text' => trim($currentBuffer), 'chapter' => $chapter];
        }

        return $chunks;
    }

    /**
     * @return array<int, array{text: string, chapter: string|null}>
     */
    private function splitBySentences(string $paragraph, ?string $chapter): array {
        $sentences = preg_split('/(?<=[.!?])\s+/', $paragraph);
        if ($sentences === false || count($sentences) <= 1) {
            return [['text' => $paragraph, 'chapter' => $chapter]];
        }

        $chunks = [];
        $buffer = '';

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if ($sentence === '') {
                continue;
            }

            $bufferTokens = $this->estimateTokens($buffer);
            $sentenceTokens = $this->estimateTokens($sentence);

            if ($bufferTokens + $sentenceTokens > self::TARGET_TOKENS && trim($buffer) !== '') {
                $chunks[] = ['text' => trim($buffer), 'chapter' => $chapter];
                $buffer = '';
            }

            $buffer .= ($buffer !== '' ? ' ' : '') . $sentence;
        }

        if (trim($buffer) !== '') {
            $chunks[] = ['text' => trim($buffer), 'chapter' => $chapter];
        }

        return $chunks;
    }

    private function detectHeading(string $paragraph): ?string {
        if (preg_match('/^#{1,6}\s+(.+)$/m', $paragraph, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Estimate token count using ~4 chars per token heuristic.
     */
    private function estimateTokens(string $text): int {
        return (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN);
    }
}
