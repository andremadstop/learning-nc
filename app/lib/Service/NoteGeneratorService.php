<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * NoteGeneratorService — Phase 24: Note-Generator
 *
 * Generates Obsidian-compatible Markdown summaries for weak learning topics
 * by calling Gemini (via GeminiService::generateNote) and persisting the result
 * to the user's /Learning/Zusammenfassungen/ folder via LernbotFileService.
 *
 * NOTE-01: generateSummary() — Gemini generates a topic summary on trigger
 * NOTE-02: Summary includes: key points, common mistake, practice recommendation
 * NOTE-03: Wiki-links to related questions/simulations embedded in summary
 * NOTE-04: updateExistingNote() — filename = topic slug → overwrites, no duplicates
 *
 * Privacy: No PII sent to Gemini — only pool_name, question texts, error_rate.
 */
class NoteGeneratorService {
    private GeminiService $geminiService;
    private LernbotFileService $fileService;
    private LernprofilService $lernprofilService;
    private IDBConnection $db;
    private IConfig $config;
    private LoggerInterface $logger;

    /** Max wrong questions to include in prompt context */
    private const MAX_WRONG_QUESTIONS = 5;

    /** Sub-folder for topic summaries (matches LernbotFileService internal structure) */
    private const SUBFOLDER = 'Zusammenfassungen';

    public function __construct(
        GeminiService $geminiService,
        LernbotFileService $fileService,
        LernprofilService $lernprofilService,
        IDBConnection $db,
        IConfig $config,
        LoggerInterface $logger
    ) {
        $this->geminiService = $geminiService;
        $this->fileService = $fileService;
        $this->lernprofilService = $lernprofilService;
        $this->db = $db;
        $this->config = $config;
        $this->logger = $logger;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Generate (or regenerate) an Obsidian-compatible Markdown summary for a pool.
     *
     * NOTE-01, NOTE-02, NOTE-03, NOTE-04
     *
     * @param string   $userId    NC user ID
     * @param int      $poolId    Pool to summarize
     * @param int|null $courseId  Optional course context for profile lookup
     *
     * @return array{path: string, updated: bool, content: string}
     *
     * @throws \RuntimeException  If Gemini is unavailable or file write fails
     */
    public function generateSummary(string $userId, int $poolId, ?int $courseId = null): array {
        // 1. Load pool info
        $poolInfo = $this->loadPoolInfo($poolId);
        if ($poolInfo === null) {
            throw new \RuntimeException('Pool ' . $poolId . ' not found');
        }

        $poolName = $poolInfo['name'];
        $chapterRef = $poolInfo['chapter_ref'] ?? '';

        // 2. Load user's error rate for this pool from profile
        $errorRate = $this->getPoolErrorRate($userId, $poolId, $courseId);

        // 3. Load most commonly wrong question texts for this pool + user
        $wrongQuestions = $this->loadWrongQuestions($userId, $poolId);

        // 4. Determine content language
        $language = $this->config->getUserValue($userId, 'learning', 'content_language', '') ?: 'en';
        $languageName = $this->resolveLanguageName($language);

        // 5. Build filename (NOTE-04: filename = topic slug)
        $filename = $this->slugify($poolName) . '.md';
        $noteExisted = $this->fileService->listNotes($userId, self::SUBFOLDER) !== []
            && $this->noteFileExists($userId, $filename);

        // 6. Build prompt and call Gemini (NOTE-01)
        $systemPrompt = $this->buildSystemPrompt($poolName, $chapterRef, $languageName);
        $userPrompt = $this->buildUserPrompt($poolName, $errorRate, $wrongQuestions, $language);

        $generatedContent = $this->geminiService->generateNote($systemPrompt, $userPrompt);

        // 7. Ensure content has valid Obsidian frontmatter; add/fix if missing
        $bodyMarkdown = $this->stripGeminiFrontmatter($generatedContent);

        // 8. Build our own authoritative frontmatter (FILES-04, NOTE-02)
        $meta = [
            'source' => 'VirtuProf',
            'topic' => $poolName,
            'status' => '#schwach',
            'chapter' => $chapterRef !== '' ? $chapterRef : $poolName,
            'tags' => ['#schwach', '#lernbot'],
        ];

        // 9. Write via LernbotFileService (NOTE-04: overwrites if exists)
        $this->fileService->writeNote($userId, self::SUBFOLDER, $filename, $bodyMarkdown, $meta);

        $path = '/Learning/' . self::SUBFOLDER . '/' . $filename;

        $this->logger->info('NoteGeneratorService: note ' . ($noteExisted ? 'updated' : 'created') . ' for user ' . $userId . ' pool ' . $poolId, ['app' => 'learning']);

        // Reconstruct the full content (frontmatter + body) to return
        $fullContent = $this->fileService->buildFrontmatter($meta) . $bodyMarkdown;

        return [
            'path' => $path,
            'updated' => $noteExisted,
            'content' => $fullContent,
        ];
    }

    /**
     * Update an existing note (alias for generateSummary — overwrites via same filename).
     *
     * NOTE-04: filename = topic slug → update instead of duplicate.
     *
     * @param string   $userId    NC user ID
     * @param int      $poolId    Pool to update summary for
     * @param int|null $courseId  Optional course context
     *
     * @return array{path: string, updated: bool, content: string}
     */
    public function updateExistingNote(string $userId, int $poolId, ?int $courseId = null): array {
        return $this->generateSummary($userId, $poolId, $courseId);
    }

    // -------------------------------------------------------------------------
    // Private helpers — DB queries
    // -------------------------------------------------------------------------

    /**
     * Load pool name and chapter_ref from DB.
     *
     * @return array{name: string, chapter_ref: string}|null
     */
    private function loadPoolInfo(int $poolId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('name', 'chapter_ref')
           ->from('learning_pools')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($poolId)));
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if ($row === false) {
            return null;
        }

        return [
            'name' => (string)$row['name'],
            'chapter_ref' => (string)($row['chapter_ref'] ?? ''),
        ];
    }

    /**
     * Get the user's error rate for a specific pool (0–100).
     * Falls back to 50.0 if no data.
     */
    private function getPoolErrorRate(string $userId, int $poolId, ?int $courseId): float {
        try {
            $profile = $this->lernprofilService->aggregateProfile($userId, $courseId);
            foreach ($profile['pools'] as $pool) {
                if ((int)$pool['pool_id'] === $poolId) {
                    return (float)$pool['error_rate'];
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('NoteGeneratorService: profile lookup failed: ' . $e->getMessage(), ['app' => 'learning']);
        }
        return 50.0;
    }

    /**
     * Load the question texts the user most frequently answered incorrectly in this pool.
     * Returns up to MAX_WRONG_QUESTIONS question texts.
     *
     * Query: learning_user_answers JOIN learning_sessions ON session_id
     *        JOIN learning_questions ON question_id
     * Filter: sessions.user_id = $userId AND sessions.pool_id = $poolId AND ua.is_correct = false
     * Group by question_id, order by wrong_count DESC.
     *
     * @return list<string>  Question text strings
     */
    private function loadWrongQuestions(string $userId, int $poolId): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $expr = $qb->expr();

            $qb->select('q.text', $qb->createFunction('COUNT(ua.id) AS wrong_count'))
               ->from('learning_user_answers', 'ua')
               ->innerJoin('ua', 'learning_sessions', 's', $expr->eq('ua.session_id', 's.id'))
               ->innerJoin('ua', 'learning_questions', 'q', $expr->eq('ua.question_id', 'q.id'))
               ->where($expr->eq('s.user_id', $qb->createNamedParameter($userId)))
               ->andWhere($expr->eq('s.pool_id', $qb->createNamedParameter($poolId)))
               ->andWhere($expr->eq('ua.is_correct', $qb->createNamedParameter(false, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL)))
               ->groupBy('ua.question_id', 'q.text')
               ->orderBy('wrong_count', 'DESC')
               ->setMaxResults(self::MAX_WRONG_QUESTIONS);

            $result = $qb->executeQuery();
            $rows = $result->fetchAll();
            $result->closeCursor();

            return array_map(fn($row) => mb_substr((string)$row['text'], 0, 200), $rows);
        } catch (\Throwable $e) {
            $this->logger->debug('NoteGeneratorService: wrong questions query failed: ' . $e->getMessage(), ['app' => 'learning']);
            return [];
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers — prompt building
    // -------------------------------------------------------------------------

    /**
     * System prompt: instructs Gemini on the desired output format.
     * Contains NO PII — only topic name and language instruction.
     */
    private function buildSystemPrompt(string $poolName, string $chapterRef, string $languageName): string {
        $chapter = $chapterRef !== '' ? $chapterRef : $poolName;
        return <<<PROMPT
Du bist ein Lern-Assistent. Erstelle eine Zusammenfassung zum Thema "{$poolName}".

Format: Obsidian-kompatibles Markdown (NUR den Body, KEIN YAML Frontmatter):
- ## Kernpunkte
  - 3-5 prägnante Bullet-Punkte zu den wichtigsten Konzepten
- ## Häufigster Fehler
  - Erkläre den häufigsten Fehler basierend auf den falsch beantworteten Fragen
- ## Übungsempfehlung
  - Konkrete Lernempfehlung mit Wiki-Link: [[{$poolName}]]
  - Wenn sinnvoll: weiterer Wiki-Link auf verwandtes Kapitel: [[{$chapter}]]
- Abschließende Tags: #schwach #lernbot

Sprache: {$languageName}
Maximale Länge: 400 Wörter.
Nur den Markdown-Body ausgeben — KEIN YAML Frontmatter, KEINE Einleitung wie "Hier ist die Zusammenfassung:".
PROMPT;
    }

    /**
     * User prompt: contains topic + error context.
     * Contains NO PII — only pool name, error rate, and question texts.
     *
     * @param list<string> $wrongQuestions
     */
    private function buildUserPrompt(string $poolName, float $errorRate, array $wrongQuestions, string $language): string {
        $errorPct = number_format($errorRate, 1);
        $lines = ["Thema: {$poolName}", "Fehlerrate: {$errorPct}%"];

        if (!empty($wrongQuestions)) {
            $lines[] = '';
            $lines[] = 'Häufig falsch beantwortete Fragen:';
            foreach ($wrongQuestions as $idx => $q) {
                $lines[] = ($idx + 1) . '. ' . $q;
            }
        }

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Private helpers — content processing
    // -------------------------------------------------------------------------

    /**
     * Strip any YAML frontmatter that Gemini may have included despite instructions.
     * Returns only the body content after the frontmatter block (if present).
     */
    private function stripGeminiFrontmatter(string $content): string {
        $content = ltrim($content);

        if (!str_starts_with($content, '---')) {
            return $content;
        }

        // Find closing ---
        $rest = substr($content, 3);
        $closePos = strpos($rest, '---');
        if ($closePos === false) {
            return $content; // Malformed — return as-is
        }

        // Body starts after closing --- and any trailing newline
        $body = substr($rest, $closePos + 3);
        return ltrim($body, "\n\r");
    }

    /**
     * Convert a pool name to a safe, stable filename slug.
     * e.g. "VLAN Konfiguration" → "vlan-konfiguration"
     */
    private function slugify(string $name): string {
        // Lowercase
        $slug = mb_strtolower($name);

        // Replace German umlauts
        $replacements = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss'];
        $slug = str_replace(array_keys($replacements), array_values($replacements), $slug);

        // Replace non-alphanumeric (except hyphen) with hyphen
        $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug) ?? $slug;

        // Collapse multiple hyphens, trim
        $slug = preg_replace('/-+/', '-', $slug) ?? $slug;
        $slug = trim($slug, '-');

        // Fallback
        if ($slug === '') {
            $slug = 'note';
        }

        return $slug;
    }

    /**
     * Check whether a note file already exists in /Zusammenfassungen/.
     */
    private function noteFileExists(string $userId, string $filename): bool {
        $notes = $this->fileService->listNotes($userId, self::SUBFOLDER);
        foreach ($notes as $note) {
            if ($note['name'] === $filename) {
                return true;
            }
        }
        return false;
    }

    /**
     * Map ISO language code to human-readable language name for prompts.
     */
    private function resolveLanguageName(string $code): string {
        $map = [
            'de' => 'Deutsch',
            'en' => 'English',
            'ru' => 'Русский',
            'ar' => 'Arabic',
        ];
        return $map[$code] ?? 'English';
    }
}
