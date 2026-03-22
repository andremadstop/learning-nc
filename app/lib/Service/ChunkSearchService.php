<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCA\Learning\Db\RagChunk;
use OCA\Learning\Db\RagChunkMapper;
use Psr\Log\LoggerInterface;

class ChunkSearchService {

    private const STOPWORDS = [
        // German
        'der', 'die', 'das', 'ein', 'eine', 'und', 'oder', 'ist', 'sind',
        'was', 'wie', 'wo', 'wer', 'den', 'dem', 'des', 'im', 'in', 'an',
        'auf', 'mit', 'von', 'zu', 'fuer', 'fur', 'nicht', 'auch', 'es',
        'als', 'bei', 'aus', 'nach', 'wird', 'hat', 'kann', 'man',
        // English
        'the', 'is', 'are', 'was', 'what', 'how', 'where', 'who',
        'on', 'at', 'with', 'of', 'to', 'for', 'and', 'or', 'not',
        'it', 'this', 'that', 'be', 'has', 'have', 'can', 'do',
    ];

    private RagChunkMapper $chunkMapper;
    private LoggerInterface $logger;

    public function __construct(RagChunkMapper $chunkMapper, LoggerInterface $logger) {
        $this->chunkMapper = $chunkMapper;
        $this->logger = $logger;
    }

    /**
     * Search for relevant chunks matching a user query.
     *
     * Extracts keywords from the query, searches chunks by keyword ILIKE match,
     * and returns the most relevant chunks sorted by relevance score.
     *
     * @param int $courseId Course to search within
     * @param string $query User question or search string
     * @param int $limit Maximum chunks to return (default 5)
     * @return RagChunk[] Ranked chunks, most relevant first
     */
    public function search(int $courseId, string $query, int $limit = 5): array {
        $trimmed = trim($query);
        if ($trimmed === '') {
            return [];
        }

        $keywords = $this->extractKeywords($trimmed);
        if (empty($keywords)) {
            return [];
        }

        $results = $this->chunkMapper->searchByKeywords($courseId, $keywords, $limit);

        // Strip relevance wrapper, return only RagChunk entities
        $chunks = array_map(
            static fn(array $row): RagChunk => $row['chunk'],
            $results
        );

        $this->logger->debug('ChunkSearch: found {count} chunks for query \'{query}\' in course {courseId}', [
            'count' => count($chunks),
            'query' => $trimmed,
            'courseId' => $courseId,
        ]);

        return $chunks;
    }

    /**
     * Extract meaningful keywords from a user query.
     *
     * Lowercases, splits on whitespace, removes stopwords and short words,
     * deduplicates.
     *
     * @param string $query Raw user query
     * @return string[] Unique keywords
     */
    private function extractKeywords(string $query): array {
        $lower = mb_strtolower($query);
        $words = preg_split('/\s+/', $lower, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false) {
            return [];
        }

        $stopwords = array_flip(self::STOPWORDS);
        $keywords = [];
        $seen = [];

        foreach ($words as $word) {
            // Remove non-alphanumeric edges (punctuation)
            $clean = preg_replace('/^[^a-z0-9äöüß]+|[^a-z0-9äöüß]+$/u', '', $word);
            if ($clean === '' || $clean === null) {
                continue;
            }
            // Skip stopwords
            if (isset($stopwords[$clean])) {
                continue;
            }
            // Skip words shorter than 3 characters
            if (mb_strlen($clean) < 3) {
                continue;
            }
            // Deduplicate
            if (isset($seen[$clean])) {
                continue;
            }
            $seen[$clean] = true;
            $keywords[] = $clean;
        }

        return $keywords;
    }
}
