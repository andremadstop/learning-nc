<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * LernplanService — Phase 25: Lernplan + Fortschritt
 *
 * Generates a weekly learning plan and a progress dashboard as Obsidian-
 * compatible Markdown files in the user's /Learning/ folder.
 *
 * PLAN-01: generateWeeklyPlan() — Gemini creates 7-day plan based on weakest topics
 * PLAN-02: Lernplan.md has - [ ] Montag: ... checklist format
 * PLAN-03: Writes to /Learning/Lernplan.md (overwrites previous)
 * PLAN-04: generateFortschritt() — pure-data dashboard: box stats, 4-week trend, recommendations
 *
 * Privacy: No PII sent to Gemini — only pool names and error rates.
 */
class LernplanService {
    private GeminiService $geminiService;
    private LernbotFileService $fileService;
    private LernprofilService $lernprofilService;
    private IConfig $config;
    private LoggerInterface $logger;

    /** Top-level filenames written directly in /Learning/ */
    private const LERNPLAN_FILE    = 'Lernplan.md';
    private const FORTSCHRITT_FILE = 'Fortschritt.md';

    /** Subfolder for Zusammenfassungen (used for Wiki-Link references) */
    private const SUBFOLDER_SUMMARIES = 'Zusammenfassungen';

    public function __construct(
        GeminiService $geminiService,
        LernbotFileService $fileService,
        LernprofilService $lernprofilService,
        IConfig $config,
        LoggerInterface $logger
    ) {
        $this->geminiService        = $geminiService;
        $this->fileService          = $fileService;
        $this->lernprofilService    = $lernprofilService;
        $this->config               = $config;
        $this->logger               = $logger;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Generate (or regenerate) a weekly Lernplan.md via Gemini.
     *
     * PLAN-01, PLAN-02, PLAN-03
     *
     * @param string   $userId    NC user ID
     * @param int|null $courseId  Optional course context for profile lookup
     *
     * @return array{path: string, updated: bool, content: string}
     *
     * @throws \RuntimeException  If Gemini is unavailable or file write fails
     */
    public function generateWeeklyPlan(string $userId, ?int $courseId = null): array {
        // 1. Load weakest topics for context
        $weakTopics = $this->lernprofilService->getWeakestTopics($userId, $courseId, 5);

        // 2. Determine output language
        $language     = $this->config->getUserValue($userId, 'learning', 'content_language', '') ?: 'de';
        $languageName = $this->resolveLanguageName($language);

        // 3. Build prompts and call Gemini
        $systemPrompt = $this->buildPlanSystemPrompt($weakTopics, $languageName);
        $userPrompt   = $this->buildPlanUserPrompt($weakTopics);

        $generatedContent = $this->geminiService->generateNote($systemPrompt, $userPrompt);

        // 4. Strip any Gemini-generated frontmatter
        $bodyMarkdown = $this->stripGeminiFrontmatter($generatedContent);

        // 5. Build authoritative frontmatter
        $meta = [
            'source' => 'VirtuProf',
            'topic'  => 'Wöchentlicher Lernplan',
            'status' => 'aktiv',
            'tags'   => ['#lernplan', '#wochenplan', '#lernbot'],
        ];

        // 6. Check if file existed before (for 'updated' flag)
        $fileExisted = $this->noteFileExists($userId, self::LERNPLAN_FILE);

        // 7. Write to /Learning/Lernplan.md (empty subfolder = directly in /Learning/)
        $this->fileService->writeNote($userId, '', self::LERNPLAN_FILE, $bodyMarkdown, $meta);

        $path = '/Learning/' . self::LERNPLAN_FILE;

        $this->logger->info(
            'LernplanService: Lernplan.md ' . ($fileExisted ? 'updated' : 'created') . ' for user ' . $userId,
            ['app' => 'learning']
        );

        $fullContent = $this->fileService->buildFrontmatter($meta) . $bodyMarkdown;

        return [
            'path'    => $path,
            'updated' => $fileExisted,
            'content' => $fullContent,
        ];
    }

    /**
     * Generate (or regenerate) Fortschritt.md — pure-data progress dashboard.
     *
     * PLAN-04 — No Gemini call; built from profile + lernhistorie data.
     *
     * @param string   $userId    NC user ID
     * @param int|null $courseId  Optional course context
     *
     * @return array{path: string, updated: bool, content: string}
     *
     * @throws \RuntimeException  If file write fails
     */
    public function generateFortschritt(string $userId, ?int $courseId = null): array {
        // 1. Load full profile + 4-week history
        $profile      = $this->lernprofilService->aggregateProfile($userId, $courseId);
        $lernhistorie = $this->lernprofilService->getLernhistorie($userId, 28);

        // 2. Build markdown body from data
        $bodyMarkdown = $this->buildFortschrittBody($profile, $lernhistorie);

        // 3. Authoritative frontmatter
        $meta = [
            'source' => 'VirtuProf',
            'topic'  => 'Lernfortschritt',
            'status' => 'aktuell',
            'tags'   => ['#fortschritt', '#lernbot', '#statistik'],
        ];

        // 4. Check if file existed before
        $fileExisted = $this->noteFileExists($userId, self::FORTSCHRITT_FILE);

        // 5. Write to /Learning/Fortschritt.md
        $this->fileService->writeNote($userId, '', self::FORTSCHRITT_FILE, $bodyMarkdown, $meta);

        $path = '/Learning/' . self::FORTSCHRITT_FILE;

        $this->logger->info(
            'LernplanService: Fortschritt.md ' . ($fileExisted ? 'updated' : 'created') . ' for user ' . $userId,
            ['app' => 'learning']
        );

        $fullContent = $this->fileService->buildFrontmatter($meta) . $bodyMarkdown;

        return [
            'path'    => $path,
            'updated' => $fileExisted,
            'content' => $fullContent,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers — prompt building (Lernplan)
    // -------------------------------------------------------------------------

    /**
     * System prompt for the weekly learning plan generation.
     * Contains NO PII — only topic names and format instructions.
     *
     * @param array<int, array> $weakTopics  From LernprofilService::getWeakestTopics()
     */
    private function buildPlanSystemPrompt(array $weakTopics, string $languageName): string {
        $poolNames = implode(', ', array_map(fn($t) => '"' . $t['pool_name'] . '"', $weakTopics));

        return <<<PROMPT
Du bist ein Lern-Assistent. Erstelle einen 7-Tage Lernplan basierend auf den schwächsten Themen des Lernenden.

Format: Obsidian-kompatibles Markdown (NUR den Body, KEIN YAML Frontmatter):

## Wöchentlicher Lernplan — KW {aktuelle Woche}

Kurze motivierende Einleitung (1-2 Sätze).

## Tagesplan

Für jeden Wochentag (Montag bis Sonntag) eine Zeile im Format:
- [ ] Montag: [Aufgabe] — [[Pool-Name]]
- [ ] Dienstag: [Aufgabe] — [[Pool-Name]]
...
- [ ] Samstag: Wiederholung: [[schwächstes Thema]]
- [ ] Sonntag: Selbsttest zu allen Themen dieser Woche

Regeln:
- Montag bis Freitag: je 1-2 Aufgaben basierend auf den schwächsten Themen
- Samstag: Wiederholung des schwächsten Themas
- Sonntag: Selbsttest / Wiederholung aller Themen
- Jede Aufgabe enthält einen Wiki-Link auf den Pool: [[Pool-Name]]
- Aufgaben sind konkret und umsetzbar (z.B. "10 Leitner-Karten wiederholen", "5 Fragen üben")
- Optional: Wiki-Link auf vorhandene Zusammenfassung: [[Zusammenfassungen/Pool-Slug]]

## Tipps für diese Woche

2-3 konkrete Lerntipps basierend auf den schwachen Themen: {$poolNames}

Abschließende Tags: #lernplan #wochenplan

Sprache: {$languageName}
Maximale Länge: 500 Wörter.
Nur den Markdown-Body ausgeben — KEIN YAML Frontmatter, KEINE Einleitung wie "Hier ist der Lernplan:".
PROMPT;
    }

    /**
     * User prompt: contains weakest topics + error rates. No PII.
     *
     * @param array<int, array> $weakTopics
     */
    private function buildPlanUserPrompt(array $weakTopics): string {
        $lines = ['Schwächste Themen (nach Fehlerrate sortiert):'];

        foreach ($weakTopics as $idx => $topic) {
            $errorRate = number_format((float)$topic['error_rate'], 1);
            $trend     = $topic['trend'] ?? 'stable';
            $trendIcon = match($trend) {
                'improving' => '(↑ verbessert)',
                'declining' => '(↓ verschlechtert)',
                default     => '(→ stabil)',
            };
            $lines[] = ($idx + 1) . '. ' . $topic['pool_name'] . ' — Fehlerrate: ' . $errorRate . '% ' . $trendIcon;
        }

        if (empty($weakTopics)) {
            $lines[] = 'Keine schwachen Themen identifiziert. Erstelle einen allgemeinen Wiederholungsplan.';
        }

        $lines[] = '';
        $lines[] = 'Erstelle einen motivierenden, realistischen 7-Tage Lernplan für diese Themen.';

        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Private helpers — Fortschritt body builder (no AI)
    // -------------------------------------------------------------------------

    /**
     * Build the Fortschritt.md body from profile and history data.
     * Pure PHP — no AI call.
     *
     * @param array $profile      From LernprofilService::aggregateProfile()
     * @param array $lernhistorie From LernprofilService::getLernhistorie()
     */
    private function buildFortschrittBody(array $profile, array $lernhistorie): string {
        $now = date('d.m.Y H:i');
        $sections = [];

        // Header
        $sections[] = '# Lernfortschritt';
        $sections[] = '';
        $sections[] = '_Stand: ' . $now . '_';
        $sections[] = '';

        // --- Summary stats ---
        $summary = $profile['summary'] ?? [];
        $totalQ  = (int)($summary['total_questions'] ?? 0);
        $mastered = (int)($summary['total_mastered'] ?? 0);
        $accuracy = number_format((float)($summary['overall_accuracy'] ?? 0), 1);

        $sections[] = '## Gesamtübersicht';
        $sections[] = '';
        $sections[] = '| Kennzahl | Wert |';
        $sections[] = '|----------|------|';
        $sections[] = '| Karten gesamt | ' . $totalQ . ' |';
        $sections[] = '| Gemeistert (Box 5) | ' . $mastered . ' |';
        $sections[] = '| Gesamtgenauigkeit | ' . $accuracy . '% |';
        $masterPct = $totalQ > 0 ? number_format($mastered * 100.0 / $totalQ, 1) : '0.0';
        $sections[] = '| Fortschritt | ' . $masterPct . '% gemeistert |';
        $sections[] = '';

        // --- Leitner box distribution ---
        $pools = $profile['pools'] ?? [];
        if (!empty($pools)) {
            $sections[] = '## Leitner-Boxen';
            $sections[] = '';
            $sections[] = '| Pool | Box 1 | Box 2 | Box 3 | Box 4 | Box 5 | Gesamt | Fehlerrate |';
            $sections[] = '|------|-------|-------|-------|-------|-------|--------|------------|';

            foreach ($pools as $pool) {
                $ls = $pool['leitner_stats'] ?? [];
                $poolName  = $pool['pool_name'] ?? '';
                $errorRate = number_format((float)($pool['error_rate'] ?? 0), 1);
                $sections[] = sprintf(
                    '| [[%s]] | %d | %d | %d | %d | %d | %d | %s%% |',
                    $poolName,
                    (int)($ls['box_1'] ?? 0),
                    (int)($ls['box_2'] ?? 0),
                    (int)($ls['box_3'] ?? 0),
                    (int)($ls['box_4'] ?? 0),
                    (int)($ls['box_5'] ?? 0),
                    (int)($ls['total'] ?? 0),
                    $errorRate
                );
            }
            $sections[] = '';
        }

        // --- 4-week trend ---
        $history = $lernhistorie['history'] ?? [];
        $weeklySummary = $this->buildWeeklySummary($history);

        if (!empty($weeklySummary)) {
            $sections[] = '## Trend (letzte 4 Wochen)';
            $sections[] = '';
            $sections[] = '| Woche | Sessions | Fragen beantwortet | Genauigkeit |';
            $sections[] = '|-------|----------|--------------------|-------------|';

            foreach ($weeklySummary as $week) {
                $acc = number_format((float)$week['accuracy'], 1);
                $sections[] = sprintf(
                    '| %s | %d | %d | %s%% |',
                    $week['label'],
                    $week['sessions'],
                    $week['questions'],
                    $acc
                );
            }
            $sections[] = '';

            $overallTrend = $lernhistorie['overall_trend'] ?? 'stable';
            $trendText = match($overallTrend) {
                'improving' => 'Trend: Verbesserung — weiter so!',
                'declining' => 'Trend: Rückgang — jetzt ist ein guter Zeitpunkt für intensive Wiederholung.',
                default     => 'Trend: Stabil — gleichmäßiger Lernfortschritt.',
            };
            $sections[] = '> ' . $trendText;
            $sections[] = '';
        }

        // --- Recommendations ---
        $weakPools = array_slice($pools, 0, 3); // Top 3 weakest (already sorted by error_rate DESC)
        if (!empty($weakPools)) {
            $sections[] = '## Empfehlungen';
            $sections[] = '';
            $sections[] = 'Basierend auf deinen Schwachstellen empfehle ich diese Themen für die nächste Woche:';
            $sections[] = '';

            foreach ($weakPools as $idx => $pool) {
                $poolName  = $pool['pool_name'] ?? '';
                $slug      = $this->slugify($poolName);
                $errorRate = number_format((float)($pool['error_rate'] ?? 0), 1);
                $trend     = $pool['trend'] ?? 'stable';
                $trendIcon = match($trend) {
                    'improving' => '↑',
                    'declining' => '↓',
                    default     => '→',
                };
                $sections[] = ($idx + 1) . '. **[[' . $poolName . ']]** — Fehlerrate: ' . $errorRate . '% ' . $trendIcon;
                $sections[] = '   - Zusammenfassung: [[' . self::SUBFOLDER_SUMMARIES . '/' . $slug . ']]';
            }
            $sections[] = '';
        }

        // Footer
        $sections[] = '---';
        $sections[] = '#fortschritt #lernbot #statistik';
        $sections[] = '';

        return implode("\n", $sections);
    }

    /**
     * Group daily history entries into up to 4 weekly buckets.
     *
     * @param array<int, array{date: string, sessions_count: int, questions_answered: int, accuracy: float}> $history
     * @return array<int, array{label: string, sessions: int, questions: int, accuracy: float}>
     */
    private function buildWeeklySummary(array $history): array {
        if (empty($history)) {
            return [];
        }

        // Sort chronologically ascending (oldest first)
        usort($history, fn($a, $b) => strcmp($a['date'], $b['date']));

        // Determine week boundaries (ISO week, last 4 weeks)
        $now     = time();
        $weeks   = [];
        for ($i = 3; $i >= 0; $i--) {
            $weekStart = strtotime('monday this week', $now - $i * 7 * 86400);
            $weekEnd   = $weekStart + 6 * 86400;
            $weeks[]   = [
                'label'     => 'KW ' . date('W', $weekStart) . ' (' . date('d.m', $weekStart) . '–' . date('d.m', $weekEnd) . ')',
                'start'     => date('Y-m-d', $weekStart),
                'end'       => date('Y-m-d', $weekEnd),
                'sessions'  => 0,
                'questions' => 0,
                'total_q'   => 0,
                'correct_q' => 0,
            ];
        }

        // Assign history days to weeks
        foreach ($history as $day) {
            $date = $day['date'];
            foreach ($weeks as &$week) {
                if ($date >= $week['start'] && $date <= $week['end']) {
                    $week['sessions']  += (int)($day['sessions_count'] ?? 0);
                    $week['questions'] += (int)($day['questions_answered'] ?? 0);
                    // Reconstruct correct count from accuracy (approximately)
                    $qa  = (int)($day['questions_answered'] ?? 0);
                    $acc = (float)($day['accuracy'] ?? 0);
                    $week['total_q']   += $qa;
                    $week['correct_q'] += (int)round($qa * $acc / 100.0);
                    break;
                }
            }
            unset($week);
        }

        // Build output (only include weeks with activity, max 4)
        $result = [];
        foreach ($weeks as $week) {
            $acc = $week['total_q'] > 0
                ? round($week['correct_q'] * 100.0 / $week['total_q'], 1)
                : 0.0;
            $result[] = [
                'label'     => $week['label'],
                'sessions'  => $week['sessions'],
                'questions' => $week['questions'],
                'accuracy'  => $acc,
            ];
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Private helpers — content processing
    // -------------------------------------------------------------------------

    /**
     * Strip any YAML frontmatter that Gemini may have included.
     */
    private function stripGeminiFrontmatter(string $content): string {
        $content = ltrim($content);

        if (!str_starts_with($content, '---')) {
            return $content;
        }

        $rest     = substr($content, 3);
        $closePos = strpos($rest, '---');
        if ($closePos === false) {
            return $content;
        }

        $body = substr($rest, $closePos + 3);
        return ltrim($body, "\n\r");
    }

    /**
     * Convert a pool name to a safe, stable filename slug.
     * Mirrors NoteGeneratorService::slugify().
     */
    private function slugify(string $name): string {
        $slug = mb_strtolower($name);

        $replacements = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss'];
        $slug = str_replace(array_keys($replacements), array_values($replacements), $slug);

        $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug) ?? $slug;
        $slug = preg_replace('/-+/', '-', $slug) ?? $slug;
        $slug = trim($slug, '-');

        if ($slug === '') {
            $slug = 'note';
        }

        return $slug;
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
        return $map[$code] ?? 'Deutsch';
    }

    /**
     * Check whether a file exists directly in /Learning/ (not in a subfolder).
     */
    private function noteFileExists(string $userId, string $filename): bool {
        $notes = $this->fileService->listNotes($userId, '');
        foreach ($notes as $note) {
            if ($note['name'] === $filename) {
                return true;
            }
        }
        return false;
    }
}
