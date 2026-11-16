<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The rich feedback a learner gets AFTER answering (GitHub #66): feedback per answer option,
 * background fact and key takeaway, in the content language. One place for every delivery point
 * — the answer responses of training (TrainingService::submitAnswer) and Leitner / Smart Queue
 * (LeitnerService::answerQuestion), and the review of a practice exam
 * (TrainingService::getSessionReview with explanations) — so the oracle rules sit at the callers
 * and the data is built once.
 *
 * The callers decide WHETHER it may be handed out (never before the answer, never while a
 * counting exam runs on the pool, never in the review of a counting exam). The hint, the only
 * field meant for before the answer, is not part of this.
 */
class RichFeedbackService {
    public function __construct(
        private IDBConnection $db,
        private TranslationService $translationService,
    ) {
    }

    /**
     * @return array{option_feedback: list<array{answer_id: int, position: int, is_correct: bool, why: ?string, misconception: ?string}>, background_fact: ?string, key_takeaway: ?string}|null
     *         null when the question has no rich feedback
     */
    public function forQuestion(int $questionId, ?string $lang): ?array {
        return $this->forQuestions([$questionId], $lang)[$questionId] ?? null;
    }

    /**
     * @param list<int> $questionIds
     * @return array<int, array{option_feedback: list<array{answer_id: int, position: int, is_correct: bool, why: ?string, misconception: ?string}>, background_fact: ?string, key_takeaway: ?string}>
     *         by question id; questions without rich feedback are missing
     */
    public function forQuestions(array $questionIds, ?string $lang): array {
        $questionIds = array_values(array_unique(array_filter(array_map('intval', $questionIds), static fn (int $id): bool => $id > 0)));
        if ($questionIds === []) {
            return [];
        }

        $rows = [];
        foreach (array_chunk($questionIds, 1000) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'background_fact', 'key_takeaway', 'option_feedback')
               ->from('learning_questions')
               ->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $rows[(int)$row['id']] = [
                    'background_fact' => self::text($row['background_fact'] ?? null),
                    'key_takeaway' => self::text($row['key_takeaway'] ?? null),
                    'option_feedback' => RichFeedbackFormat::decodeOptions(is_string($row['option_feedback'] ?? null) ? $row['option_feedback'] : null),
                ];
            }
            $result->closeCursor();
        }
        $rows = array_filter($rows, static fn (array $r): bool => $r['background_fact'] !== null || $r['key_takeaway'] !== null || $r['option_feedback'] !== []);
        if ($rows === []) {
            return [];
        }

        $rows = $this->translationService->translateRichFeedback($rows, $lang);

        // Answer id and verdict per position; only questions with option feedback need them.
        $withOptions = array_keys(array_filter($rows, static fn (array $r): bool => $r['option_feedback'] !== []));
        $answers = [];
        foreach (array_chunk($withOptions, 1000) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'question_id', 'is_correct', 'position')
               ->from('learning_answers')
               ->where($qb->expr()->in('question_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
               ->orderBy('question_id', 'ASC')
               ->addOrderBy('position', 'ASC');
            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $answers[(int)$row['question_id']][] = $row;
            }
            $result->closeCursor();
        }

        $out = [];
        foreach ($rows as $questionId => $row) {
            $byPosition = [];
            foreach ($answers[$questionId] ?? [] as $index => $answer) {
                // A row without a position (older imports) counts by its order, as the export does.
                $position = $answer['position'] !== null && $answer['position'] !== '' ? (int)$answer['position'] : $index;
                $byPosition[$position] = $answer;
            }
            $options = [];
            foreach ($row['option_feedback'] as $entry) {
                $answer = $byPosition[$entry['position']] ?? null;
                if ($answer === null) {
                    continue; // feedback for an answer the question no longer has
                }
                $options[] = [
                    'answer_id' => (int)$answer['id'],
                    'position' => $entry['position'],
                    'is_correct' => filter_var($answer['is_correct'], FILTER_VALIDATE_BOOLEAN),
                    'why' => $entry['why'],
                    'misconception' => $entry['misconception'],
                ];
            }
            if ($options === [] && $row['background_fact'] === null && $row['key_takeaway'] === null) {
                continue;
            }
            $out[$questionId] = [
                'option_feedback' => $options,
                'background_fact' => $row['background_fact'],
                'key_takeaway' => $row['key_takeaway'],
            ];
        }
        return $out;
    }

    private static function text(mixed $value): ?string {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
