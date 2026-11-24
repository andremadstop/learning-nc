<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCA\Learning\Db\RagChunk;
use OCA\Learning\Db\RagChunkMapper;
use Psr\Log\LoggerInterface;

class ChunkSearchService {

    /** Upper bound of Han pairs per query: each one adds two LIKE terms to the search SQL. */
    private const MAX_HAN_PAIRS = 24;
    /**
     * Question words and particles that occur in almost every Chinese sentence; as pairs they
     * would rank any chunk that happens to contain them.
     */
    private const HAN_STOP_PAIRS = [
        '什么' => true, '怎么' => true, '如何' => true, '为什' => true, '么是' => true,
        '是什' => true, '怎样' => true, '哪些' => true, '一个' => true, '这个' => true,
        '那个' => true, '我们' => true, '你们' => true, '可以' => true, '能够' => true,
        '需要' => true, '应该' => true, '之间' => true, '的是' => true, '是一' => true,
        '请问' => true, '解释' => true, '一下' => true, '告诉' => true,
    ];

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
     * Chinese (and any Han text) has no spaces between words, so a whole question used to
     * become one keyword that never occurs in the course material, and two-character terms
     * like 子网 were dropped as too short (#105). Han runs are split into overlapping
     * character pairs instead (子网掩码 -> 子网, 网掩, 掩码): no dictionary needed, and a chunk
     * that shares more pairs with the question scores higher in searchByKeywords(). Text
     * without Han characters takes exactly the old path.
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
        $hanPairs = 0;

        // A token that mixes Han with other letters ("vlan是什么") is split into its Han runs
        // and the rest; the rest goes through the word rules below unchanged.
        $parts = [];
        foreach ($words as $word) {
            if (preg_match('/\p{Han}/u', $word) !== 1) {
                $parts[] = $word;
                continue;
            }
            $split = preg_split('/(\p{Han}+)/u', $word, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
            if ($split === false) {
                continue;
            }
            foreach ($split as $segment) {
                if (preg_match('/^\p{Han}+$/u', $segment) !== 1) {
                    $parts[] = $segment;
                    continue;
                }
                // One character alone matches far too much to rank anything.
                $length = mb_strlen($segment);
                for ($i = 0; $i + 1 < $length && $hanPairs < self::MAX_HAN_PAIRS; $i++) {
                    $pair = mb_substr($segment, $i, 2);
                    if (isset(self::HAN_STOP_PAIRS[$pair]) || isset($seen[$pair])) {
                        continue;
                    }
                    $seen[$pair] = true;
                    $keywords[] = $pair;
                    $hanPairs++;
                }
            }
        }

        foreach ($parts as $word) {
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
