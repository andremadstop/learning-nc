<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Builds a RAG (Retrieval-Augmented Generation) context payload for VirtuProf.
 *
 * Loads document chunks, pool questions, user weaknesses, Leitner box stats,
 * course name and last wrong answer for a given user so that GeminiService
 * can give contextually relevant answers with source citations.
 *
 * Context-window filling is prioritized (RAG-04):
 *   1. Document chunks (highest priority -- real course material)
 *   2. Pool questions (sample questions for topic awareness)
 *   3. User weaknesses (frequently wrong questions)
 *   4. Last wrong question (most recent mistake)
 *
 * @privacy-audit (PRIV-04) -- Context array sent to Gemini API contains:
 *   INCLUDED: pool_name (string), pool_questions (question text + answer texts, truncated),
 *             leitner_stats (numeric box counts only), course_name (string),
 *             last_wrong (question text + correct answer text),
 *             chunks (course material text + source_file + chapter -- no PII, only course content),
 *             user_weaknesses (question text + error rate -- derived from existing DB data).
 *   EXCLUDED: userId (used only for DB queries, never returned), username, email,
 *             display name, passwords, system paths, or any personal identifiers.
 *
 * Token budget: context is trimmed to MAX_TOKENS (7500 tokens ~ 30000 chars).
 */
class RagContextService {
    private IDBConnection $db;
    private LoggerInterface $logger;
    private ChunkSearchService $chunkSearchService;

    /** Max questions loaded from pool to keep context compact */
    private const MAX_POOL_QUESTIONS = 15;

    /** Approximate token limit (1 token ~ 4 chars) */
    private const MAX_TOKENS = 7500;
    private const MAX_CHARS = self::MAX_TOKENS * 4;

    public function __construct(
        IDBConnection $db,
        LoggerInterface $logger,
        ChunkSearchService $chunkSearchService
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->chunkSearchService = $chunkSearchService;
    }

    /**
     * Build a RAG context array for the given user and learning context.
     *
     * @param string   $userId               NC user ID (used only for DB queries, never returned)
     * @param int|null $poolId               Active pool the user is studying
     * @param int|null $courseId             Active course context (optional)
     * @param int|null $lastWrongQuestionId  Question the user just answered incorrectly (optional)
     * @param string|null $userMessage       User's chat message for chunk search (optional)
     *
     * @return array{
     *   pool_name: string|null,
     *   pool_questions: list<array{text: string, answers: list<string>}>,
     *   leitner_stats: array{box_1:int,box_2:int,box_3:int,box_4:int,box_5:int,total:int}|null,
     *   course_name: string|null,
     *   last_wrong: array{question:string,correct_answer:string}|null,
     *   chunks: list<array{text: string, source_file: string, chapter: string|null}>,
     *   user_weaknesses: list<array{topic: string, error_rate: float}>,
     *   token_estimate: int,
     * }
     */
    /**
     * @param bool $suppressAnswers AUDIT MED-10: when the user has an active exam on the pool,
     *   withhold answer-bearing context (pool questions' answers + the last-wrong correct answer)
     *   so VirtuProf cannot be used as an exam oracle. The caller passes
     *   QuestionService::isExamActiveOnPool().
     */
    public function buildContext(
        string $userId,
        ?int $poolId,
        ?int $courseId,
        ?int $lastWrongQuestionId,
        ?string $userMessage = null,
        bool $suppressAnswers = false
    ): array {
        $context = [
            'pool_name'        => null,
            'pool_questions'   => [],
            'leitner_stats'    => null,
            'course_name'      => null,
            'last_wrong'       => null,
            'chunks'           => [],
            'user_weaknesses'  => [],
            'token_estimate'   => 0,
        ];

        try {
            // Course name (small, always included)
            if ($courseId !== null) {
                $context['course_name'] = $this->loadCourseName($courseId);
            }

            // Pool name (small, always included)
            if ($poolId !== null) {
                $context['pool_name'] = $this->loadPoolName($poolId);
                // Leitner box stats (small, always included)
                $context['leitner_stats'] = $this->loadLeitnerStats($userId, $poolId);
            }

            // Priority 1: Document chunks (RAG-01)
            if ($courseId !== null && $userMessage !== null && $userMessage !== '') {
                $chunks = $this->chunkSearchService->search($courseId, $userMessage, 8);
                foreach ($chunks as $chunk) {
                    $context['chunks'][] = [
                        'text'        => $chunk->getText(),
                        'source_file' => $chunk->getSourceFile(),
                        'chapter'     => $chunk->getChapter(),
                    ];
                }
            }

            // Priority 2: Pool questions (existing) — answer keys withheld during an active exam.
            if ($poolId !== null && !$suppressAnswers) {
                $context['pool_questions'] = $this->loadPoolQuestions($poolId);
            }

            // Priority 3: User weaknesses (RAG-03)
            if ($poolId !== null) {
                $context['user_weaknesses'] = $this->loadUserWeaknesses($userId, $poolId);
            }

            // Priority 4: Last wrong question — its correct answer is withheld during an active exam.
            if ($lastWrongQuestionId !== null && !$suppressAnswers) {
                $context['last_wrong'] = $this->loadLastWrongQuestion($lastWrongQuestionId);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('RagContextService: error building context: ' . $e->getMessage(), ['app' => 'learning']);
            // Partial context is fine -- return what we have
        }

        // RAG-04: Enforce token budget with priority-based trimming
        $context = $this->enforceTokenBudget($context);

        return $context;
    }

    /**
     * Load pool name by ID.
     */
    private function loadPoolName(int $poolId): ?string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('name')
           ->from('learning_pools')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($poolId)));
        $result = $qb->executeQuery();
        $name = $result->fetchOne();
        $result->closeCursor();
        return $name !== false ? (string)$name : null;
    }

    /**
     * Load up to MAX_POOL_QUESTIONS questions with their answers from a pool.
     * Returns array of ['text' => ..., 'answers' => [...]] -- correct answers listed first.
     */
    private function loadPoolQuestions(int $poolId): array {
        // Load question IDs (limited)
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'text')
           ->from('learning_questions')
           ->where($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId)))
           ->orderBy('id', 'ASC')
           ->setMaxResults(self::MAX_POOL_QUESTIONS);
        $result = $qb->executeQuery();
        $questionRows = $result->fetchAll();
        $result->closeCursor();

        if (empty($questionRows)) {
            return [];
        }

        $questionIds = array_column($questionRows, 'id');

        // Batch-load answers
        $aqb = $this->db->getQueryBuilder();
        $aqb->select('question_id', 'text', 'is_correct')
            ->from('learning_answers')
            ->where($aqb->expr()->in(
                'question_id',
                $aqb->createNamedParameter($questionIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)
            ))
            ->orderBy('is_correct', 'DESC')
            ->addOrderBy('position', 'ASC');
        $aResult = $aqb->executeQuery();
        $allAnswers = $aResult->fetchAll();
        $aResult->closeCursor();

        // Group answers by question_id
        $answersByQuestion = [];
        foreach ($allAnswers as $answer) {
            $qid = (int)$answer['question_id'];
            $answersByQuestion[$qid][] = $answer['text'];
        }

        $questions = [];
        foreach ($questionRows as $row) {
            $qid = (int)$row['id'];
            $questions[] = [
                'text'    => (string)$row['text'],
                'answers' => $answersByQuestion[$qid] ?? [],
            ];
        }

        return $questions;
    }

    /**
     * Load Leitner box distribution for a user + pool.
     * Returns ['box_1' => int, ..., 'box_5' => int, 'total' => int] or null.
     */
    private function loadLeitnerStats(string $userId, int $poolId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('box', $qb->createFunction('COUNT(*) as cnt'))
           ->from('learning_leitner_items')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('pool_id', $qb->createNamedParameter($poolId)))
           ->groupBy('box');
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        if (empty($rows)) {
            return null;
        }

        $stats = ['box_1' => 0, 'box_2' => 0, 'box_3' => 0, 'box_4' => 0, 'box_5' => 0];
        foreach ($rows as $row) {
            $key = 'box_' . (int)$row['box'];
            if (isset($stats[$key])) {
                $stats[$key] = (int)$row['cnt'];
            }
        }
        $stats['total'] = array_sum(array_values($stats));

        return $stats;
    }

    /**
     * Load course name by ID.
     */
    private function loadCourseName(int $courseId): ?string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('name')
           ->from('learning_courses')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($courseId)));
        $result = $qb->executeQuery();
        $name = $result->fetchOne();
        $result->closeCursor();
        return $name !== false ? (string)$name : null;
    }

    /**
     * Load the question text and correct answer for the last wrong question.
     * Returns ['question' => ..., 'correct_answer' => ...] or null.
     */
    private function loadLastWrongQuestion(int $questionId): ?array {
        // Load question text
        $qb = $this->db->getQueryBuilder();
        $qb->select('text')
           ->from('learning_questions')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($questionId)));
        $result = $qb->executeQuery();
        $questionRow = $result->fetch();
        $result->closeCursor();

        if ($questionRow === false) {
            return null;
        }

        // Load correct answer text
        $aqb = $this->db->getQueryBuilder();
        $aqb->select('text')
            ->from('learning_answers')
            ->where($aqb->expr()->eq('question_id', $aqb->createNamedParameter($questionId)))
            ->andWhere($aqb->expr()->eq('is_correct', $aqb->createNamedParameter(true, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL)))
            ->orderBy('position', 'ASC')
            ->setMaxResults(1);
        $aResult = $aqb->executeQuery();
        $answerRow = $aResult->fetch();
        $aResult->closeCursor();

        return [
            'question'       => (string)$questionRow['text'],
            'correct_answer' => $answerRow !== false ? (string)$answerRow['text'] : '',
        ];
    }

    /**
     * Load the 5 questions with the most wrong answers for this user + pool.
     *
     * Returns array of ['topic' => question text, 'error_rate' => float 0..1].
     */
    private function loadUserWeaknesses(string $userId, int $poolId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('ua.question_id')
           ->selectAlias($qb->createFunction('COUNT(*)'), 'total_answers')
           ->selectAlias(
               $qb->createFunction(
                   'SUM(CASE WHEN ua.is_correct = ' . $qb->createNamedParameter(false, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL)
                   . ' THEN 1 ELSE 0 END)'
               ),
               'wrong_count'
           )
           ->from('learning_user_answers', 'ua')
           ->innerJoin('ua', 'learning_questions', 'q', $qb->expr()->eq('ua.question_id', 'q.id'))
           // The user hangs off the session, not off the answer: learning_user_answers has no
           // user_id column. Filtering on ua.user_id raised SQLSTATE 42703 on every call, so this
           // never returned weaknesses — it threw. Same join as NoteGeneratorService uses.
           ->innerJoin('ua', 'learning_sessions', 's', $qb->expr()->eq('ua.session_id', 's.id'))
           ->where($qb->expr()->eq('s.user_id', $qb->createNamedParameter($userId)))
           ->andWhere($qb->expr()->eq('q.pool_id', $qb->createNamedParameter($poolId)))
           ->groupBy('ua.question_id')
           ->orderBy('wrong_count', 'DESC')
           ->setMaxResults(5);

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        if (empty($rows)) {
            return [];
        }

        // Load question texts for the IDs
        $questionIds = array_column($rows, 'question_id');
        $tqb = $this->db->getQueryBuilder();
        $tqb->select('id', 'text')
            ->from('learning_questions')
            ->where($tqb->expr()->in(
                'id',
                $tqb->createNamedParameter($questionIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)
            ));
        $tResult = $tqb->executeQuery();
        $textRows = $tResult->fetchAll();
        $tResult->closeCursor();

        $textsById = [];
        foreach ($textRows as $tr) {
            $textsById[(int)$tr['id']] = (string)$tr['text'];
        }

        $weaknesses = [];
        foreach ($rows as $row) {
            $qid = (int)$row['question_id'];
            $total = (int)$row['total_answers'];
            $wrong = (int)$row['wrong_count'];
            if ($total === 0 || $wrong === 0) {
                continue;
            }
            $weaknesses[] = [
                'topic'      => $textsById[$qid] ?? '(unknown)',
                'error_rate' => round($wrong / $total, 2),
            ];
        }

        return $weaknesses;
    }

    /**
     * RAG-04: Enforce the 7500-token budget with priority-based trimming.
     *
     * Trimming order (lowest priority dropped first):
     *   1. Drop user_weaknesses entirely
     *   2. Trim pool_questions from the end
     *   3. Trim chunks from the end (last resort)
     *
     * pool_name, course_name, leitner_stats, and last_wrong are always kept (they are small).
     *
     * Uses strlen(json_encode()) / 4 as token estimate (1 token ~ 4 chars).
     */
    private function enforceTokenBudget(array $context): array {
        $payload = $context;
        unset($payload['token_estimate']);

        $chars = $this->estimateChars($payload);

        // Step 1: Drop user_weaknesses if over budget
        if ($chars > self::MAX_CHARS && !empty($payload['user_weaknesses'])) {
            $payload['user_weaknesses'] = [];
            $chars = $this->estimateChars($payload);
        }

        // Step 2: Trim pool_questions from the end
        while ($chars > self::MAX_CHARS && !empty($payload['pool_questions'])) {
            array_pop($payload['pool_questions']);
            $chars = $this->estimateChars($payload);
        }

        // Step 3: Trim chunks from the end (last resort)
        while ($chars > self::MAX_CHARS && !empty($payload['chunks'])) {
            array_pop($payload['chunks']);
            $chars = $this->estimateChars($payload);
        }

        $context['pool_questions']  = $payload['pool_questions'];
        $context['chunks']          = $payload['chunks'];
        $context['user_weaknesses'] = $payload['user_weaknesses'];
        $context['token_estimate']  = (int)ceil($chars / 4);

        return $context;
    }

    /**
     * Estimate character count of context payload.
     */
    private function estimateChars(array $payload): int {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        return $encoded !== false ? strlen($encoded) : 0;
    }
}
