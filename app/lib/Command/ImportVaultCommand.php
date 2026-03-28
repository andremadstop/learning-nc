<?php
declare(strict_types=1);
namespace OCA\Learning\Command;

use OCA\Learning\Db\RagChunk;
use OCA\Learning\Db\RagChunkMapper;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * OCC command to import Obsidian Markdown vaults as RAG chunks.
 *
 * Usage: php occ learning:import-vault --path=/data/comptia-vault --course-id=20
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
	private const EXCLUDED_DIRS = ['/Eigene-Notizen/', '/.obsidian/', '/Bilder/'];

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
			->addOption('path', null, InputOption::VALUE_REQUIRED, 'Absolute path to vault directory')
			->addOption('course-id', null, InputOption::VALUE_REQUIRED, 'Course ID to associate chunks with');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$path = $input->getOption('path');
		$courseIdRaw = $input->getOption('course-id');

		// Validate path
		if (!is_string($path) || !is_dir($path)) {
			$output->writeln('<error>--path must be an existing directory</error>');
			return 1;
		}

		// Validate course-id
		if (!is_string($courseIdRaw) || !ctype_digit($courseIdRaw) || (int)$courseIdRaw <= 0) {
			$output->writeln('<error>--course-id must be a positive integer</error>');
			return 1;
		}
		$courseId = (int)$courseIdRaw;

		// Normalize path (remove trailing slash)
		$vaultRoot = rtrim($path, '/');

		// Idempotent cleanup: delete existing vault chunks (document_id=0) for this course
		$deleted = $this->chunkMapper->deleteByDocumentIdAndCourseId(0, $courseId);
		$output->writeln("Deleted {$deleted} existing vault chunks for course {$courseId}");

		// Find all .md files
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($vaultRoot, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		$totalFiles = 0;
		$skippedFiles = 0;
		$totalChunks = 0;
		$totalTokens = 0;

		/** @var \SplFileInfo $file */
		foreach ($iterator as $file) {
			if ($file->getExtension() !== 'md') {
				continue;
			}

			$fullPath = $file->getPathname();
			$relativePath = substr($fullPath, strlen($vaultRoot) + 1);

			// Exclude certain directories
			if ($this->isExcludedPath($relativePath)) {
				continue;
			}

			$totalFiles++;

			$rawContent = file_get_contents($fullPath);
			if ($rawContent === false) {
				$output->writeln("<comment>Warning: Cannot read {$relativePath}</comment>");
				$skippedFiles++;
				continue;
			}

			$cleaned = $this->cleanMarkdown($rawContent);

			if (strlen($cleaned) < self::MIN_FILE_LENGTH) {
				$skippedFiles++;
				continue;
			}

			// Determine parent directory as fallback chapter
			$parentDir = basename(dirname($fullPath));
			if ($parentDir === '.' || $parentDir === basename($vaultRoot)) {
				$parentDir = null;
			}

			$chunks = $this->splitIntoChunks($cleaned, $parentDir);
			$now = time();

			foreach ($chunks as $index => $chunkData) {
				$chunk = new RagChunk();
				$chunk->setDocumentId(0);
				$chunk->setCourseId($courseId);
				$chunk->setChapter($chunkData['chapter']);
				$chunk->setText($chunkData['text']);
				$chunk->setSourceFile($relativePath);
				$chunk->setChunkIndex($index);
				$chunk->setTokenCount((int)ceil(strlen($chunkData['text']) / self::CHARS_PER_TOKEN));
				$chunk->setCreatedAt($now);

				$this->chunkMapper->insert($chunk);
				$totalChunks++;
				$totalTokens += $chunk->getTokenCount();
			}
		}

		$output->writeln('');
		$output->writeln("Import complete:");
		$output->writeln("  Files processed: {$totalFiles}");
		$output->writeln("  Files skipped (short): {$skippedFiles}");
		$output->writeln("  Chunks created: {$totalChunks}");
		$output->writeln("  Total tokens: {$totalTokens}");

		$this->logger->info('ImportVaultCommand: imported {chunks} chunks ({tokens} tokens) from {files} files for course {courseId}', [
			'chunks' => $totalChunks,
			'tokens' => $totalTokens,
			'files' => $totalFiles,
			'courseId' => $courseId,
			'app' => 'learning',
		]);

		return 0;
	}

	/**
	 * Check if a relative path should be excluded from import.
	 */
	private function isExcludedPath(string $relativePath): bool {
		$checkPath = '/' . $relativePath;
		foreach (self::EXCLUDED_DIRS as $excluded) {
			if (strpos($checkPath, $excluded) !== false) {
				return true;
			}
		}
		return false;
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
	 * Split text into ~500-token chunks with chapter/heading detection.
	 *
	 * @param string $text Cleaned markdown text
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
		$currentChapter = $fallbackChapter;
		$chunkChapter = null;

		foreach ($paragraphs as $paragraph) {
			$paragraph = trim($paragraph);
			if ($paragraph === '') {
				continue;
			}

			// Check if this paragraph is a heading
			$heading = $this->detectHeading($paragraph);
			if ($heading !== null) {
				$currentChapter = $heading;
				if (trim($currentBuffer) !== '') {
					$chunks[] = ['text' => trim($currentBuffer), 'chapter' => $chunkChapter];
					$currentBuffer = '';
				}
				$chunkChapter = $currentChapter;
				continue;
			}

			if ($chunkChapter === null) {
				$chunkChapter = $currentChapter;
			}

			$paragraphTokens = $this->estimateTokens($paragraph);
			$bufferTokens = $this->estimateTokens($currentBuffer);

			// If single paragraph exceeds target, split by sentences
			if ($paragraphTokens > self::TARGET_TOKENS) {
				if (trim($currentBuffer) !== '') {
					$chunks[] = ['text' => trim($currentBuffer), 'chapter' => $chunkChapter];
					$currentBuffer = '';
					$chunkChapter = $currentChapter;
				}

				$sentenceChunks = $this->splitBySentences($paragraph, $currentChapter);
				foreach ($sentenceChunks as $sc) {
					$chunks[] = $sc;
				}
				$chunkChapter = $currentChapter;
				continue;
			}

			// If adding this paragraph would exceed target, flush buffer
			if ($bufferTokens + $paragraphTokens > self::TARGET_TOKENS && trim($currentBuffer) !== '') {
				$chunks[] = ['text' => trim($currentBuffer), 'chapter' => $chunkChapter];
				$currentBuffer = '';
				$chunkChapter = $currentChapter;
			}

			$currentBuffer .= ($currentBuffer !== '' ? "\n\n" : '') . $paragraph;
		}

		// Flush remaining buffer
		if (trim($currentBuffer) !== '') {
			$chunks[] = ['text' => trim($currentBuffer), 'chapter' => $chunkChapter];
		}

		return $chunks;
	}

	/**
	 * Split a long paragraph into chunks by sentence boundaries.
	 *
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

	/**
	 * Detect if a paragraph is a Markdown heading.
	 *
	 * @return string|null The heading text, or null if not a heading
	 */
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
		return (int)ceil(strlen($text) / self::CHARS_PER_TOKEN);
	}
}
