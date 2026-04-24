#!/usr/bin/env php
<?php
declare(strict_types=1);

use OCA\Learning\Db\Answer;
use OCA\Learning\Db\AnswerMapper;
use OCA\Learning\Db\Question;
use OCA\Learning\Db\QuestionMapper;
use OCA\Learning\Service\GeminiService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

define('OC_CONSOLE', true);
require_once '/var/www/html/lib/base.php';
\OC_App::loadApp('learning');

if (!class_exists(GeminiService::class)) {
    require_once '/var/www/html/custom_apps/learning/lib/Service/GeminiService.php';
    require_once '/var/www/html/custom_apps/learning/lib/Db/Question.php';
    require_once '/var/www/html/custom_apps/learning/lib/Db/QuestionMapper.php';
    require_once '/var/www/html/custom_apps/learning/lib/Db/Answer.php';
    require_once '/var/www/html/custom_apps/learning/lib/Db/AnswerMapper.php';
}

$options = parseArguments($argv);
$poolId = $options['pool_id'];
$dryRun = $options['dry_run'];
$limit = $options['limit'];
$answerIds = $options['answer_ids'];

$server = \OC::$server;
$db = $server->get(IDBConnection::class);
$questionMapper = new QuestionMapper($db);
$answerMapper = new AnswerMapper($db);
$gemini = buildGeminiService($server);

$candidates = loadCandidateRows($db, $poolId, $limit, $answerIds);
$questionCache = [];
$answerCache = [];
$answerEntityCache = [];

$report = [
    'generated_at' => gmdate('c'),
    'pool_id' => $poolId,
    'dry_run' => $dryRun,
    'candidate_threshold' => 80,
    'candidates' => count($candidates),
    'auto_fix_count' => 0,
    'manual_review_count' => 0,
    'unchanged_count' => 0,
    'gemini_available' => $gemini !== null && $gemini->isAvailable(),
    'changes' => [],
    'manual_review' => [],
    'unchanged' => [],
];

foreach ($candidates as $index => $candidate) {
    $questionId = (int)$candidate['question_id'];
    $answerId = (int)$candidate['answer_id'];

    if (!isset($questionCache[$questionId])) {
        $questionCache[$questionId] = $questionMapper->findById($questionId);
    }

    if (!isset($answerCache[$questionId])) {
        $answerEntities = $answerMapper->findByQuestion($questionId);
        $answerCache[$questionId] = $answerEntities;
        foreach ($answerEntities as $entity) {
            $answerEntityCache[(int)$entity->getId()] = $entity;
        }
    }

    if (!isset($answerEntityCache[$answerId])) {
        $report['manual_review_count']++;
        $report['manual_review'][] = buildManualReviewEntry(
            $candidate,
            null,
            'answer_entity_missing',
            'Answer entity could not be loaded from the mapper.',
            null
        );
        continue;
    }

    /** @var Question $questionEntity */
    $questionEntity = $questionCache[$questionId];
    /** @var Answer $answerEntity */
    $answerEntity = $answerEntityCache[$answerId];
    $questionRow = $questionEntity->jsonSerialize();
    $answerRows = array_map(static fn(Answer $answer): array => $answer->jsonSerialize(), $answerCache[$questionId]);

    $decision = analyzeCandidate($candidate, $questionRow, $answerRows, $gemini);
    fwrite(STDOUT, sprintf(
        "[%d/%d] answer=%d status=%s reason=%s\n",
        $index + 1,
        count($candidates),
        $answerId,
        $decision['status'],
        $decision['reason']
    ));

    if ($decision['status'] === 'unchanged') {
        $report['unchanged_count']++;
        $report['unchanged'][] = [
            'answer_id' => $answerId,
            'question_id' => $questionId,
            'position' => (int)$candidate['position'],
            'is_correct' => (bool)$candidate['is_correct'],
            'reason' => $decision['reason'],
            'preview' => previewText((string)$candidate['answer_text']),
        ];
        continue;
    }

    if ($decision['status'] === 'manual_review') {
        $report['manual_review_count']++;
        $report['manual_review'][] = buildManualReviewEntry(
            $candidate,
            $decision,
            $decision['reason'],
            $decision['reason_detail'],
            $decision['proposed'] ?? null
        );
        continue;
    }

    $mergedExplanation = mergeExplanation(
        (string)($questionRow['explanation'] ?? ''),
        (string)$decision['proposed']['explanation']
    );

    $change = [
        'answer_id' => $answerId,
        'question_id' => $questionId,
        'position' => (int)$candidate['position'],
        'is_correct' => (bool)$candidate['is_correct'],
        'old_text' => (string)$candidate['answer_text'],
        'new_text' => (string)$decision['proposed']['answer'],
        'old_explanation' => (string)($questionRow['explanation'] ?? ''),
        'new_explanation' => $mergedExplanation['text'],
        'explanation_action' => $mergedExplanation['action'],
        'method' => $decision['method'],
        'reason' => $decision['reason'],
        'confidence' => $decision['confidence'],
        'applied' => !$dryRun,
    ];

    if (!$dryRun) {
        $answerEntity->setText((string)$decision['proposed']['answer']);
        $answerMapper->createOrUpdate($answerEntity);

        if ($mergedExplanation['text'] !== (string)($questionRow['explanation'] ?? '')) {
            $questionEntity->setExplanation($mergedExplanation['text']);
            $questionMapper->createOrUpdate($questionEntity);
        }
    }

    $report['auto_fix_count']++;
    $report['changes'][] = $change;
}

$reportPath = __DIR__ . '/cleanup_pool144_report.json';
$manualReviewPath = __DIR__ . '/cleanup_pool144_manual_review.md';
writeJsonFile($reportPath, $report);
writeManualReviewFile($manualReviewPath, $report);

fwrite(STDOUT, sprintf(
    "Done. candidates=%d auto_fix=%d manual_review=%d unchanged=%d report=%s manual_review=%s\n",
    $report['candidates'],
    $report['auto_fix_count'],
    $report['manual_review_count'],
    $report['unchanged_count'],
    $reportPath,
    $manualReviewPath
));

function parseArguments(array $argv): array {
    $poolId = 144;
    $dryRun = false;
    $limit = null;
    $answerIds = [];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run') {
            $dryRun = true;
            continue;
        }

        if (preg_match('/^--limit=(\d+)$/', $arg, $matches)) {
            $limit = (int)$matches[1];
            continue;
        }

        if (preg_match('/^--answer-id=(.+)$/', $arg, $matches)) {
            $answerIds = array_values(array_unique(array_filter(array_map(
                'intval',
                preg_split('/,/', $matches[1]) ?: []
            ), static fn(int $id): bool => $id > 0)));
            continue;
        }

        if (preg_match('/^\d+$/', $arg)) {
            $poolId = (int)$arg;
        }
    }

    return [
        'pool_id' => $poolId,
        'dry_run' => $dryRun,
        'limit' => $limit,
        'answer_ids' => $answerIds,
    ];
}

function buildGeminiService($server): ?GeminiService {
    try {
        return new GeminiService(
            $server->get(IConfig::class),
            $server->get(ICacheFactory::class),
            $server->get(IDBConnection::class),
            $server->get(LoggerInterface::class),
            $server->get(\OCA\Learning\Service\LlmService::class)
        );
    } catch (\Throwable $e) {
        fwrite(STDERR, "[warn] GeminiService bootstrap failed: " . $e->getMessage() . "\n");
        return null;
    }
}

function loadCandidateRows(IDBConnection $db, int $poolId, ?int $limit, array $answerIds): array {
    $qb = $db->getQueryBuilder();
    $qb->select(
        'a.id AS answer_id',
        'a.question_id',
        'a.position',
        'a.is_correct',
        'a.text AS answer_text',
        'q.text AS question_text',
        'q.explanation AS question_explanation'
    )
        ->from('learning_answers', 'a')
        ->innerJoin('a', 'learning_questions', 'q', $qb->expr()->eq('q.id', 'a.question_id'))
        ->where($qb->expr()->eq('q.pool_id', $qb->createNamedParameter($poolId, IQueryBuilder::PARAM_INT)))
        ->orderBy('a.id', 'ASC');

    if ($answerIds !== []) {
        $qb->andWhere($qb->expr()->in('a.id', $qb->createNamedParameter($answerIds, IQueryBuilder::PARAM_INT_ARRAY)));
    }

    if ($limit !== null && $limit > 0) {
        $qb->setMaxResults($limit);
    }

    $rows = $qb->executeQuery()->fetchAllAssociative();

    return array_values(array_filter($rows, static function (array $row): bool {
        return mb_strlen(normalizeWhitespace((string)($row['answer_text'] ?? ''))) > 80;
    }));
}

function analyzeCandidate(array $candidate, array $questionRow, array $answerRows, ?GeminiService $gemini): array {
    $original = normalizeWhitespace((string)$candidate['answer_text']);
    $signals = collectSignals($original);

    if ($signals['likely_unchanged']) {
        return [
            'status' => 'unchanged',
            'reason' => 'looks_like_legitimate_long_answer',
        ];
    }

    $heuristic = tryHeuristicSplit($original);
    if ($heuristic !== null) {
        return finalizeDecision($original, $heuristic['answer'], $heuristic['explanation'], 'heuristic', $heuristic['reason'], 'high');
    }

    if ($gemini !== null && $gemini->isAvailable() && $signals['needs_ai']) {
        $geminiDecision = tryGeminiSplit($gemini, $questionRow, $answerRows, $candidate);
        if ($geminiDecision !== null) {
            return finalizeDecision(
                $original,
                (string)($geminiDecision['answer'] ?? $original),
                (string)($geminiDecision['explanation'] ?? ''),
                'gemini',
                (string)($geminiDecision['reason'] ?? 'gemini_structured_extraction'),
                (string)($geminiDecision['confidence'] ?? 'low'),
                (string)($geminiDecision['status'] ?? 'manual_review')
            );
        }
    }

    return [
        'status' => 'manual_review',
        'method' => 'none',
        'reason' => 'ambiguous_candidate',
        'reason_detail' => 'No safe heuristic split was found and the candidate stays ambiguous.',
        'confidence' => 'low',
    ];
}

function collectSignals(string $text): array {
    $teacherCommentPattern = '/(?:\b(?:ich halte|ich vermute|da bin ich mir|warum nicht|bezieht sich aber|es geht doch|k\.?\s*a\.?|m\.?\s*e\.?|w(?:ue|u|\x{00FC})rde ich|would i|examtopics|proudly presented)\b|das nicht vorhandene)/iu';
    $hasTeacherComment = preg_match($teacherCommentPattern, $text) === 1;
    $hasUrl = preg_match('/(?:https?:\/\/|www\.|\/\/[a-z0-9.-]+\.)/i', $text) === 1;
    $hasDefinitionVerb = preg_match('/\b(?:is|are|represents|refers to|describes|helps|provides|enables|means)\b/i', $text) === 1;
    $startsLikeSentence = preg_match('/^(?:A|An|The|To|Using|Use|Apply|Conduct|Create|Implement|Move|Place|Perform|Include|Including|Purchase|Employees?)\b/', $text) === 1;
    $wordCount = countWords($text);
    $likelyUnchanged = !$hasTeacherComment && !$hasUrl && !$hasDefinitionVerb && $startsLikeSentence && $wordCount >= 12;

    return [
        'likely_unchanged' => $likelyUnchanged,
        'needs_ai' => !$likelyUnchanged,
    ];
}

function tryHeuristicSplit(string $text): ?array {
    if (preg_match('/^(?<answer>.+?[a-z0-9.)])(?<explanation>[A-Z]{2,}\s*=\s*.+)$/u', $text, $matches)) {
        return [
            'answer' => trim((string)$matches['answer']),
            'explanation' => trim((string)$matches['explanation']),
            'reason' => 'glossary_suffix',
        ];
    }

    $commentPattern = '/(?:\b(?:ich halte|ich vermute|da bin ich mir|warum nicht|bezieht sich aber|es geht doch|k\.?\s*a\.?|m\.?\s*e\.?|w(?:ue|u|\x{00FC})rde ich|would i|examtopics|proudly presented)\b|das nicht vorhandene)/iu';
    if (preg_match($commentPattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
        $offset = (int)$matches[0][1];
        if ($offset >= 4) {
            return [
                'answer' => trim((string)mb_strcut($text, 0, $offset, 'UTF-8')),
                'explanation' => trim((string)mb_strcut($text, $offset, null, 'UTF-8')),
                'reason' => 'teacher_comment_marker',
            ];
        }
    }

    if (preg_match('/^(?<answer>[A-Z0-9][A-Za-z0-9()\/,&+\- ]{1,90})\s{2,}(?<explanation>[A-Z].{25,})$/u', $text, $matches)) {
        return [
            'answer' => trim((string)$matches['answer']),
            'explanation' => trim((string)$matches['explanation']),
            'reason' => 'double_space_definition',
        ];
    }

    return null;
}

function tryGeminiSplit(GeminiService $gemini, array $questionRow, array $answerRows, array $candidate): ?array {
    $answerLines = [];
    foreach ($answerRows as $row) {
        $answerLines[] = sprintf(
            "%d. %s%s",
            (int)($row['position'] ?? 0) + 1,
            (bool)($row['is_correct'] ?? false) ? '[correct] ' : '',
            normalizeWhitespace((string)($row['text'] ?? ''))
        );
    }

    $systemPrompt = implode("\n", [
        'You clean corrupted multiple-choice answer options imported into a quiz database.',
        'Return JSON only without markdown:',
        '{"status":"unchanged|split|manual_review","answer":"...","explanation":"...","reason":"...","confidence":"high|medium|low"}',
        'Rules:',
        '- If the text is already a clean answer option, return status="unchanged" and explanation="".',
        '- Use status="split" only if you can preserve the exact intended answer option without paraphrasing.',
        '- Put appended instructor comments or copied rationale into explanation.',
        '- If the prefix is ambiguous, merged, malformed, or you are unsure, return status="manual_review".',
        '- Never invent answer text that is not visible in the original string.',
    ]);

    $userPrompt = implode("\n", [
        'Question:',
        normalizeWhitespace((string)($questionRow['text'] ?? '')),
        '',
        'Current question explanation:',
        normalizeWhitespace((string)($questionRow['explanation'] ?? '')),
        '',
        'All answer options:',
        implode("\n", $answerLines),
        '',
        'Candidate answer ID: ' . (int)$candidate['answer_id'],
        'Original candidate text:',
        normalizeWhitespace((string)$candidate['answer_text']),
    ]);

    try {
        $raw = $gemini->generateNote($systemPrompt, $userPrompt);
    } catch (\Throwable $e) {
        return null;
    }

    $clean = trim($raw);
    $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean) ?? $clean;
    $clean = preg_replace('/\s*```$/', '', $clean) ?? $clean;
    $data = json_decode($clean, true);
    if (!is_array($data) && preg_match('/\{.*\}/s', $clean, $matches)) {
        $data = json_decode($matches[0], true);
    }

    if (!is_array($data)) {
        return null;
    }

    return [
        'status' => (string)($data['status'] ?? 'manual_review'),
        'answer' => normalizeWhitespace((string)($data['answer'] ?? '')),
        'explanation' => normalizeWhitespace((string)($data['explanation'] ?? '')),
        'reason' => normalizeWhitespace((string)($data['reason'] ?? 'gemini_parse')),
        'confidence' => (string)($data['confidence'] ?? 'low'),
    ];
}

function finalizeDecision(
    string $original,
    string $answer,
    string $explanation,
    string $method,
    string $reason,
    string $confidence,
    string $requestedStatus = 'split'
): array {
    $original = normalizeWhitespace($original);
    $answer = normalizeWhitespace($answer);
    $explanation = normalizeWhitespace($explanation);

    if ($requestedStatus === 'unchanged') {
        return [
            'status' => 'unchanged',
            'reason' => $reason,
        ];
    }

    if ($requestedStatus !== 'split') {
        return [
            'status' => 'manual_review',
            'method' => $method,
            'reason' => 'model_requested_manual_review',
            'reason_detail' => $reason,
            'confidence' => $confidence,
            'proposed' => [
                'answer' => $answer,
                'explanation' => $explanation,
            ],
        ];
    }

    if ($answer === '' || $answer === $original) {
        return [
            'status' => 'unchanged',
            'reason' => $reason,
        ];
    }

    if ($explanation === '') {
        return [
            'status' => 'manual_review',
            'method' => $method,
            'reason' => 'missing_explanation_after_split',
            'reason_detail' => 'The proposed answer changed, but no explanation/commentary payload remained.',
            'confidence' => $confidence,
            'proposed' => [
                'answer' => $answer,
                'explanation' => $explanation,
            ],
        ];
    }

    if ($confidence !== 'high') {
        return [
            'status' => 'manual_review',
            'method' => $method,
            'reason' => 'confidence_below_high',
            'reason_detail' => 'Only high-confidence splits are auto-applied.',
            'confidence' => $confidence,
            'proposed' => [
                'answer' => $answer,
                'explanation' => $explanation,
            ],
        ];
    }

    if (!looksLikeVisibleSubstring($original, $answer)) {
        return [
            'status' => 'manual_review',
            'method' => $method,
            'reason' => 'answer_not_visible_in_original',
            'reason_detail' => 'The proposed answer is not a visible substring of the original answer text.',
            'confidence' => $confidence,
            'proposed' => [
                'answer' => $answer,
                'explanation' => $explanation,
            ],
        ];
    }

    $ratio = mb_strlen($answer) / max(1, mb_strlen($original));
    if ($ratio < 0.5) {
        return [
            'status' => 'manual_review',
            'method' => $method,
            'reason' => 'cut_ratio_below_half',
            'reason_detail' => sprintf('Proposed answer keeps only %.2f%% of the original text.', $ratio * 100),
            'confidence' => $confidence,
            'proposed' => [
                'answer' => $answer,
                'explanation' => $explanation,
            ],
        ];
    }

    if (preg_match('/^[A-Z]{5,}\s*\(/', $answer) === 1) {
        return [
            'status' => 'manual_review',
            'method' => $method,
            'reason' => 'merged_acronym_prefix',
            'reason_detail' => 'The proposed answer starts with an unusually long merged acronym token.',
            'confidence' => $confidence,
            'proposed' => [
                'answer' => $answer,
                'explanation' => $explanation,
            ],
        ];
    }

    if (preg_match('/\b(?:ich halte|ich vermute|da bin ich mir|warum nicht|bezieht sich aber|es geht doch|k\.?\s*a\.?|m\.?\s*e\.?|w(?:ue|u|\x{00FC})rde ich|examtopics)\b/iu', $answer) === 1) {
        return [
            'status' => 'manual_review',
            'method' => $method,
            'reason' => 'teacher_comment_leaked_into_answer',
            'reason_detail' => 'The proposed answer still contains review commentary markers.',
            'confidence' => $confidence,
            'proposed' => [
                'answer' => $answer,
                'explanation' => $explanation,
            ],
        ];
    }

    if (preg_match('/[,(=:\/-]$/u', $answer) === 1) {
        return [
            'status' => 'manual_review',
            'method' => $method,
            'reason' => 'answer_has_dangling_suffix',
            'reason_detail' => 'The proposed answer ends with a dangling punctuation marker.',
            'confidence' => $confidence,
            'proposed' => [
                'answer' => $answer,
                'explanation' => $explanation,
            ],
        ];
    }

    if (mb_strlen($answer) > 80) {
        return [
            'status' => 'manual_review',
            'method' => $method,
            'reason' => 'answer_still_too_long',
            'reason_detail' => 'The proposed clean answer remains unusually long.',
            'confidence' => $confidence,
            'proposed' => [
                'answer' => $answer,
                'explanation' => $explanation,
            ],
        ];
    }

    return [
        'status' => 'auto_fix',
        'method' => $method,
        'reason' => $reason,
        'reason_detail' => 'Safe split candidate.',
        'confidence' => $confidence,
        'proposed' => [
            'answer' => $answer,
            'explanation' => $explanation,
        ],
    ];
}

function mergeExplanation(string $existing, string $addition): array {
    $existing = trim($existing);
    $addition = trim($addition);

    if ($addition === '') {
        return [
            'text' => $existing,
            'action' => 'noop',
        ];
    }

    if ($existing === '') {
        return [
            'text' => $addition,
            'action' => 'replace_empty',
        ];
    }

    if (normalizedContains($existing, $addition)) {
        return [
            'text' => $existing,
            'action' => 'already_present',
        ];
    }

    return [
        'text' => $existing . "\n\n---\n\n" . $addition,
        'action' => 'append_separator',
    ];
}

function buildManualReviewEntry(array $candidate, ?array $decision, string $reason, string $detail, ?array $proposed): array {
    return [
        'answer_id' => (int)$candidate['answer_id'],
        'question_id' => (int)$candidate['question_id'],
        'position' => (int)$candidate['position'],
        'is_correct' => (bool)$candidate['is_correct'],
        'reason' => $reason,
        'reason_detail' => $detail,
        'old_text' => (string)$candidate['answer_text'],
        'question_preview' => previewText((string)$candidate['question_text']),
        'proposed_answer' => $proposed['answer'] ?? null,
        'proposed_explanation' => $proposed['explanation'] ?? null,
        'confidence' => $decision['confidence'] ?? null,
        'method' => $decision['method'] ?? null,
    ];
}

function writeJsonFile(string $path, array $payload): void {
    file_put_contents(
        $path,
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL
    );
}

function writeManualReviewFile(string $path, array $report): void {
    $lines = [
        '# Pool 144 Manual Review',
        '',
        '- Generated: ' . $report['generated_at'],
        '- Pool ID: ' . $report['pool_id'],
        '- Candidates: ' . $report['candidates'],
        '- Auto-fix: ' . $report['auto_fix_count'],
        '- Manual review: ' . $report['manual_review_count'],
        '- Unchanged: ' . $report['unchanged_count'],
        '',
    ];

    if ($report['manual_review'] === []) {
        $lines[] = '_No manual-review items._';
        $lines[] = '';
    } else {
        foreach ($report['manual_review'] as $entry) {
            $lines[] = '## Answer ' . $entry['answer_id'];
            $lines[] = '';
            $lines[] = '- Question ID: ' . $entry['question_id'];
            $lines[] = '- Position: ' . $entry['position'];
            $lines[] = '- Correct: ' . ($entry['is_correct'] ? 'yes' : 'no');
            $lines[] = '- Reason: ' . $entry['reason'];
            $lines[] = '- Detail: ' . $entry['reason_detail'];
            if (!empty($entry['method'])) {
                $lines[] = '- Method: ' . $entry['method'];
            }
            if (!empty($entry['confidence'])) {
                $lines[] = '- Confidence: ' . $entry['confidence'];
            }
            $lines[] = '- Question: ' . previewText((string)$entry['question_preview'], 220);
            $lines[] = '- Old text: ' . previewText((string)$entry['old_text'], 260);
            if (!empty($entry['proposed_answer'])) {
                $lines[] = '- Proposed answer: ' . previewText((string)$entry['proposed_answer'], 220);
            }
            if (!empty($entry['proposed_explanation'])) {
                $lines[] = '- Proposed explanation: ' . previewText((string)$entry['proposed_explanation'], 260);
            }
            $lines[] = '';
        }
    }

    file_put_contents($path, implode(PHP_EOL, $lines));
}

function normalizeWhitespace(string $text): string {
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\n+/u', ' ', $text) ?? $text;
    return trim($text);
}

function previewText(string $text, int $max = 180): string {
    $text = normalizeWhitespace($text);
    if (mb_strlen($text) <= $max) {
        return $text;
    }

    return rtrim((string)mb_substr($text, 0, $max - 1)) . '...';
}

function countWords(string $text): int {
    $parts = preg_split('/\s+/u', trim($text)) ?: [];
    $parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
    return count($parts);
}

function normalizedContains(string $haystack, string $needle): bool {
    $haystack = mb_strtolower(normalizeWhitespace($haystack));
    $needle = mb_strtolower(normalizeWhitespace($needle));
    if ($needle === '') {
        return true;
    }

    return str_contains($haystack, $needle);
}

function looksLikeVisibleSubstring(string $original, string $answer): bool {
    return str_contains(normalizeWhitespace($original), normalizeWhitespace($answer));
}
