<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCA\Learning\Db\CourseDocument;
use OCA\Learning\Db\RagChunk;
use OCA\Learning\Db\RagChunkMapper;
use Psr\Log\LoggerInterface;

class ChunkingService {
    private RagChunkMapper $chunkMapper;
    private LoggerInterface $logger;

    /** Target tokens per chunk (~4 chars per token heuristic) */
    private const TARGET_TOKENS = 500;
    private const CHARS_PER_TOKEN = 4;

    public function __construct(
        RagChunkMapper $chunkMapper,
        LoggerInterface $logger
    ) {
        $this->chunkMapper = $chunkMapper;
        $this->logger = $logger;
    }

    /**
     * Chunk a document's extracted text into ~500-token pieces with chapter tags.
     *
     * Safe to call multiple times (deletes existing chunks first).
     *
     * @return int Number of chunks created
     */
    public function chunkDocument(CourseDocument $doc): int {
        // Delete existing chunks for re-chunking safety
        $this->chunkMapper->deleteByDocumentId($doc->getId());

        $text = $doc->getExtractedText();
        if ($text === null || trim($text) === '') {
            return 0;
        }

        $chunks = $this->splitIntoChunks($text);
        $now = time();
        $count = 0;

        foreach ($chunks as $index => $chunkData) {
            $chunk = new RagChunk();
            $chunk->setDocumentId($doc->getId());
            $chunk->setCourseId($doc->getCourseId());
            $chunk->setChapter($chunkData['chapter']);
            $chunk->setText($chunkData['text']);
            $chunk->setSourceFile($doc->getFileName());
            $chunk->setChunkIndex($index);
            $chunk->setTokenCount($this->estimateTokens($chunkData['text']));
            $chunk->setCreatedAt($now);

            $this->chunkMapper->insert($chunk);
            $count++;
        }

        $this->logger->info('ChunkingService: created {count} chunks for document {docId} ({file})', [
            'count' => $count,
            'docId' => $doc->getId(),
            'file' => $doc->getFileName(),
            'app' => 'learning',
        ]);

        return $count;
    }

    /**
     * Split text into ~500-token chunks with chapter/heading detection.
     *
     * Strategy:
     * 1. Split by double-newline (paragraphs)
     * 2. Accumulate paragraphs until reaching ~500 tokens
     * 3. If a single paragraph exceeds target, split by sentences
     * 4. Track headings to tag each chunk with its chapter
     *
     * @return array<int, array{text: string, chapter: ?string}>
     */
    private function splitIntoChunks(string $text): array {
        $paragraphs = preg_split('/\n{2,}/', $text);
        if ($paragraphs === false) {
            return [['text' => $text, 'chapter' => null]];
        }

        $chunks = [];
        $currentBuffer = '';
        $currentChapter = null;
        $chunkChapter = null; // chapter for the current accumulating chunk

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            // Check if this paragraph is a heading
            $heading = $this->detectHeading($paragraph);
            if ($heading !== null) {
                $currentChapter = $heading;
                // If we have accumulated text, flush it before the new chapter
                if (trim($currentBuffer) !== '') {
                    $chunks[] = ['text' => trim($currentBuffer), 'chapter' => $chunkChapter];
                    $currentBuffer = '';
                }
                $chunkChapter = $currentChapter;
                continue;
            }

            // Set chunk chapter if not yet set
            if ($chunkChapter === null) {
                $chunkChapter = $currentChapter;
            }

            $paragraphTokens = $this->estimateTokens($paragraph);
            $bufferTokens = $this->estimateTokens($currentBuffer);

            // If single paragraph exceeds target, split by sentences
            if ($paragraphTokens > self::TARGET_TOKENS) {
                // Flush current buffer first
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
     * @return array<int, array{text: string, chapter: ?string}>
     */
    private function splitBySentences(string $paragraph, ?string $chapter): array {
        // Split by sentence-ending punctuation followed by space or newline
        $sentences = preg_split('/(?<=[.!?])\s+/', $paragraph);
        if ($sentences === false || count($sentences) <= 1) {
            // Cannot split further -- return as-is
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
     * Detects:
     * - Markdown ATX headings: # Heading
     * - Markdown setext headings: Heading\n====== or Heading\n------
     * - PDF-style: ALL CAPS lines shorter than 80 chars
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

        // PDF-style: ALL CAPS, short line (< 80 chars), no sentence-ending punctuation
        $lines = explode("\n", trim($paragraph));
        if (count($lines) === 1) {
            $line = trim($lines[0]);
            if (
                mb_strlen($line) > 2
                && mb_strlen($line) < 80
                && mb_strtoupper($line) === $line
                && preg_match('/[A-Z]/', $line)
                && !preg_match('/[.!?]$/', $line)
            ) {
                // Convert to title case for readability
                return mb_convert_case($line, MB_CASE_TITLE);
            }
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
