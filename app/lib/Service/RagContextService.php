<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Builds a RAG (Retrieval-Augmented Generation) context payload for VirtuProf.
 *
 * Loads pool questions, Leitner box stats, course name and last wrong answer
 * for a given user so that GeminiService can give contextually relevant answers.
 *
 * @privacy-audit (PRIV-04) — Context array sent to Gemini API contains:
 *   INCLUDED: pool_name (string), pool_questions (question text + answer texts, truncated),
 *             leitner_stats (numeric box counts only), course_name (string),
 *             last_wrong (question text + correct answer text).
 *   EXCLUDED: userId (used only for DB queries, never returned), username, email,
 *             display name, passwords, system paths, or any personal identifiers.
 *
 * Token budget: context is trimmed to MAX_TOKENS (4000 tokens ≈ 16000 chars).
 */
class RagContextService {
    private IDBConnection $db;
    private LoggerInterface $logger;

    /** Max questions loaded from pool to keep context compact */
    private const MAX_POOL_QUESTIONS = 15;

    /** Approximate token limit (1 token ≈ 4 chars) */
    private const MAX_TOKENS = 4000;
    private const MAX_CHARS = self::MAX_TOKENS * 4;

    public function __construct(IDBConnection $db, LoggerInterface $logger) {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Build a RAG context array for the given user and learning context.
     *
     * @param string   $userId               NC user ID (used only for DB queries, never returned)
     * @param int|null $poolId               Active pool the user is studying
     * @param int|null $courseId             Active course context (optional)
     * @param int|null $lastWrongQuestionId  Question the user just answered incorrectly (optional)
     *
     * @return array{
     *   pool_name: string|null,
     *   pool_questions: list<array{text: string, answers: list<string>}>,
     *   leitner_stats: array{box_1:int,box_2:int,box_3:int,box_4:int,box_5:int,total:int}|null,
     *   course_name: string|null,
     *   last_wrong: array{question:string,correct_answer:string}|null,
     *   token_estimate: int,
     * }
     */
    public function buildContext(
        string $userId,
        ?int $poolId,
        ?int $courseId,
        ?int $lastWrongQuestionId
    ): array {
        $context = [
            'pool_name'      => null,
            'pool_questions' => [],
            'leitner_stats'  => null,
            'course_name'    => null,
            'last_wrong'     => null,
            'token_estimate' => 0,
        ];

        try {
            // RAG-01: Pool questions
            if ($poolId !== null) {
                $context['pool_name']      = $this->loadPoolName($poolId);
                $context['pool_questions'] = $this->loadPoolQuestions($poolId);
                // RAG-03: Leitner box stats
                $context['leitner_stats']  = $this->loadLeitnerStats($userId, $poolId);
            }

            // RAG-03: Course name
            if ($courseId !== null) {
                $context['course_name'] = $this->loadCourseName($courseId);
            }

            // RAG-02: Last wrong question + correct answer
            if ($lastWrongQuestionId !== null) {
                $context['last_wrong'] = $this->loadLastWrongQuestion($lastWrongQuestionId);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('RagContextService: error building context: ' . $e->getMessage(), ['app' => 'learning']);
            // Partial context is fine — return what we have
        }

        // RAG-04: Enforce token budget by trimming pool_questions from the end
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
     * Returns array of ['text' => ..., 'answers' => [...]] — correct answers listed first.
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
     * RAG-04: Enforce the 4000-token budget by trimming pool_questions from the end.
     * Uses strlen(json_encode()) / 4 as token estimate (1 token ≈ 4 chars).
     */
    private function enforceTokenBudget(array $context): array {
        // Remove token_estimate from the payload before encoding
        $payload = $context;
        unset($payload['token_estimate']);

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $chars = $encoded !== false ? strlen($encoded) : 0;

        // Trim questions one by one until under budget
        while ($chars > self::MAX_CHARS && !empty($payload['pool_questions'])) {
            array_pop($payload['pool_questions']);
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $chars = $encoded !== false ? strlen($encoded) : 0;
        }

        $context['pool_questions']  = $payload['pool_questions'];
        $context['token_estimate']  = (int)ceil($chars / 4);

        return $context;
    }
}
