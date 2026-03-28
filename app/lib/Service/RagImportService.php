<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCA\Learning\Db\RagChunk;
use OCA\Learning\Db\RagChunkMapper;
use Psr\Log\LoggerInterface;

/**
 * Service for importing raw text/markdown as RAG chunks via the web UI.
 *
 * Uses document_id = -1 to distinguish web-imported chunks from
 * vault-imported (0) and document-extracted (positive IDs).
 */
class RagImportService {
    private RagChunkMapper $chunkMapper;
    private LoggerInterface $logger;

    /** Target tokens per chunk (~4 chars per token heuristic) */
    private const TARGET_TOKENS = 500;
    private const CHARS_PER_TOKEN = 4;

    /** Minimum text length to accept for import */
    private const MIN_TEXT_LENGTH = 50;

    /** Document ID for web-imported chunks */
    public const WEB_IMPORT_DOCUMENT_ID = -1;

    public function __construct(
        RagChunkMapper $chunkMapper,
        LoggerInterface $logger
    ) {
        $this->chunkMapper = $chunkMapper;
        $this->logger = $logger;
    }

    /**
     * Import raw text/markdown as RAG chunks for a course.
     *
     * Idempotent: re-importing with the same title replaces previous chunks.
     *
     * @return array{chunks: int, tokens: int, title: string}
     * @throws \InvalidArgumentException If text is too short
     */
    public function importText(int $courseId, string $title, string $text): array {
        if (mb_strlen(trim($text)) < self::MIN_TEXT_LENGTH) {
            throw new \InvalidArgumentException(
                'Text must be at least ' . self::MIN_TEXT_LENGTH . ' characters long'
            );
        }

        $cleaned = $this->cleanMarkdown($text);

        // Idempotent: delete existing chunks for this title
        $this->chunkMapper->deleteBySourceFileAndDocumentIdAndCourseId(
            $title,
            self::WEB_IMPORT_DOCUMENT_ID,
            $courseId
        );

        $chunks = $this->splitIntoChunks($cleaned, null);
        $now = time();
        $totalTokens = 0;
        $count = 0;

        foreach ($chunks as $index => $chunkData) {
            $tokenCount = $this->estimateTokens($chunkData['text']);

            $chunk = new RagChunk();
            $chunk->setDocumentId(self::WEB_IMPORT_DOCUMENT_ID);
            $chunk->setCourseId($courseId);
            $chunk->setChapter($chunkData['chapter']);
            $chunk->setText($chunkData['text']);
            $chunk->setSourceFile($title);
            $chunk->setChunkIndex($index);
            $chunk->setTokenCount($tokenCount);
            $chunk->setCreatedAt($now);

            $this->chunkMapper->insert($chunk);
            $totalTokens += $tokenCount;
            $count++;
        }

        $this->logger->info('RagImportService: imported {count} chunks ({tokens} tokens) for course {courseId}, title "{title}"', [
            'count' => $count,
            'tokens' => $totalTokens,
            'courseId' => $courseId,
            'title' => $title,
            'app' => 'learning',
        ]);

        return [
            'chunks' => $count,
            'tokens' => $totalTokens,
            'title' => $title,
        ];
    }

    /**
     * List web-imported knowledge sources for a course.
     *
     * @return array<int, array{source_file: string, chunk_count: int, token_count: int, created_at: int}>
     */
    public function listImported(int $courseId): array {
        return $this->chunkMapper->findWebImportsByCourseId($courseId);
    }

    /**
     * Delete web-imported chunks for a specific title.
     *
     * @return int Number of deleted chunks
     */
    public function deleteImported(int $courseId, string $title): int {
        return $this->chunkMapper->deleteBySourceFileAndDocumentIdAndCourseId(
            $title,
            self::WEB_IMPORT_DOCUMENT_ID,
            $courseId
        );
    }

    /**
     * Clean Obsidian/Markdown: strip frontmatter, image embeds, wikilinks, callouts.
     *
     * Copied from ImportVaultCommand for consistency.
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
     * @param string $text Cleaned text
     * @param string|null $fallbackChapter Fallback chapter name
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

            // Detect headings and use as chapter tags
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

            // If single paragraph exceeds target, split by sentences
            if ($paragraphTokens > self::TARGET_TOKENS) {
                if (trim($currentBuffer) !== '') {
                    $chunks[] = ['text' => trim($currentBuffer), 'chapter' => $chapter];
                    $currentBuffer = '';
                }

                $sentenceChunks = $this->splitBySentences($paragraph, $chapter);
                foreach ($sentenceChunks as $sc) {
                    $chunks[] = $sc;
                }
                continue;
            }

            // If adding this paragraph would exceed target, flush buffer
            if ($bufferTokens + $paragraphTokens > self::TARGET_TOKENS && trim($currentBuffer) !== '') {
                $chunks[] = ['text' => trim($currentBuffer), 'chapter' => $chapter];
                $currentBuffer = '';
            }

            $currentBuffer .= ($currentBuffer !== '' ? "\n\n" : '') . $paragraph;
        }

        // Flush remaining buffer
        if (trim($currentBuffer) !== '') {
            $chunks[] = ['text' => trim($currentBuffer), 'chapter' => $chapter];
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
     * Detect if a paragraph is a heading/chapter title.
     *
     * @return string|null The heading text, or null if not a heading
     */
    private function detectHeading(string $paragraph): ?string {
        // Markdown ATX: # Heading
        if (preg_match('/^#{1,6}\s+(.+)$/m', $paragraph, $matches)) {
            return trim($matches[1]);
        }

        // Markdown setext: Heading\n===== or Heading\n-----
        if (preg_match('/^(.+)\n[=\-]{3,}$/m', $paragraph, $matches)) {
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
