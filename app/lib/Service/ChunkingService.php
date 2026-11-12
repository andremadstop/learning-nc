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
        // CRLF/CR documents would otherwise stay one paragraph (2026-11 audit)
        $text = str_replace(["\r\n", "\r"], "\n", $text);
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
                $currentChapter = $heading['heading'];
                // If we have accumulated text, flush it before the new chapter
                if (trim($currentBuffer) !== '') {
                    $chunks[] = ['text' => trim($currentBuffer), 'chapter' => $chunkChapter];
                    $currentBuffer = '';
                }
                $chunkChapter = $currentChapter;
                $paragraph = $heading['body'];
                if ($paragraph === '') {
                    continue;
                }
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
     * A sub-heading directly under a heading ('# A\n## B\ntext') names the text below it, so the
     * innermost ATX heading wins and its line is not left in the body (2026-11 audit, recheck D4).
     *
     * @param array{heading: string, body: string} $found
     * @return array{heading: string, body: string}
     */
    private function innermostHeading(array $found): array {
        while ($found['body'] !== '') {
            $lines = explode("\n", $found['body']);
            if (!preg_match('/^#{1,6}\s+(.+)$/', trim($lines[0]), $m)) {
                break;
            }
            $found = ['heading' => trim($m[1]), 'body' => trim(implode("\n", array_slice($lines, 1)))];
        }
        return $found;
    }

    /**
     * Detect if a paragraph is a heading/chapter title.
     *
     * Detects:
     * - Markdown ATX headings: # Heading
     * - Markdown setext headings: Heading\n====== or Heading\n------
     * - PDF-style: ALL CAPS lines shorter than 80 chars
     *
     * Only the first line(s) of a paragraph can be a heading. The lines after it come back as the
     * paragraph body, so text written directly under a heading is not lost (2026-11 audit).
     *
     * @return array{heading: string, body: string}|null
     */
    private function detectHeading(string $paragraph): ?array {
        $lines = explode("\n", trim($paragraph));
        $first = trim($lines[0]);

        // Markdown ATX: # Heading (first line only; a '# comment' further down is body text)
        if (preg_match('/^#{1,6}\s+(.+)$/', $first, $matches)) {
            return $this->innermostHeading(['heading' => trim($matches[1]), 'body' => trim(implode("\n", array_slice($lines, 1)))]);
        }

        // Markdown setext: Heading\n===== or Heading\n-----
        if ($first !== '' && isset($lines[1]) && preg_match('/^[=\-]{3,}$/', trim($lines[1]))) {
            return $this->innermostHeading(['heading' => $first, 'body' => trim(implode("\n", array_slice($lines, 2)))]);
        }

        // PDF-style: ALL CAPS, short line (< 80 chars), no sentence-ending punctuation
        if (count($lines) === 1) {
            $line = $first;
            if (
                mb_strlen($line) > 2
                && mb_strlen($line) < 80
                && mb_strtoupper($line) === $line
                && preg_match('/[A-Z]/', $line)
                && !preg_match('/[.!?]$/', $line)
            ) {
                // Convert to title case for readability
                return ['heading' => mb_convert_case($line, MB_CASE_TITLE), 'body' => ''];
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
