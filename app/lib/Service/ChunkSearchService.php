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
        // audit #151: the other four interface languages, plus the German umlaut forms. Words
        // under 3 characters are dropped by length anyway; entries are in mb_strtolower form.
        'für', 'über', 'warum', 'welche', 'welcher', 'welches', 'sich', 'einen', 'einem', 'einer',
        'why', 'which', 'does', 'from', 'there', 'their', 'when',
        // French
        'les', 'des', 'une', 'est', 'que', 'qui', 'pour', 'dans', 'avec', 'pas', 'sur', 'par',
        'aux', 'ces', 'son', 'ses', 'mais', 'comme', 'quoi', 'quel', 'quelle', 'quels', 'quelles',
        'comment', 'pourquoi', 'est-ce', "qu'est-ce", 'qu’est-ce', 'sont', 'être', 'avoir', 'fait', 'leur',
        // Russian
        'что', 'как', 'для', 'это', 'или', 'так', 'его', 'она', 'они', 'при', 'где', 'кто',
        'такое', 'чем', 'зачем', 'почему', 'какой', 'какая', 'какое', 'какие', 'если', 'есть', 'был',
        'была', 'было', 'быть', 'также', 'между', 'когда', 'нет',
        // Ukrainian
        'що', 'таке', 'як', 'для', 'або', 'цей', 'ця', 'це', 'ці', 'який', 'яка', 'яке', 'які', 'чому',
        'навіщо', 'коли', 'якщо', 'між', 'також', 'був', 'була', 'було', 'бути', 'має', 'мають',
        'від', 'про', 'при', 'але', 'там', 'тут', 'хто', 'де',
        // Arabic
        'في', 'من', 'على', 'إلى', 'الى', 'عن', 'مع', 'هذا', 'هذه', 'ذلك', 'التي', 'الذي', 'الذين',
        'ما', 'ماذا', 'لماذا', 'كيف', 'هل', 'هو', 'هي', 'كان', 'كانت', 'بين', 'أو', 'او', 'أن',
        'إن', 'لا', 'لم', 'لن', 'عند', 'كل', 'بعد', 'قبل',
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

        // Task B4: the query is the learner's chat question — log counts only
        $this->logger->debug('ChunkSearch: found {count} chunks for {keywords} keywords in course {courseId}', [
            'count' => count($chunks),
            'keywords' => count($keywords),
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
            // Remove punctuation at the edges. Unicode classes, so Cyrillic, Arabic and accented
            // words survive (de/en/fr/ru/ar/uk); \p{M} keeps combining marks (2026-11 audit).
            $clean = preg_replace('/^[^\p{L}\p{N}\p{M}]+|[^\p{L}\p{N}\p{M}]+$/u', '', $word);
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
