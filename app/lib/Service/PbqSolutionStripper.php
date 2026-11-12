<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

/**
 * Removes the solution from a PBQ config so the render config can go to a client that must not
 * see the answer (an exam attempt, or any read path while the user has an exam open on the pool).
 *
 * The one key list for every such path. It used to be copied into TrainingService and
 * LeitnerService, and the copies missed the grading data of cli/multi_panel PBQs (2026-11 audit
 * S04) — a third read path (QuestionService) had no stripping at all (S02).
 */
final class PbqSolutionStripper {
    /**
     * Keys that hold the answer, at any depth. 'evaluation' is the grading block of cli and
     * multi_panel PBQs (required_pattern = the expected command, plus its explanation); the
     * scorer reads it from the database and no client component reads it.
     */
    private const SOLUTION_KEYS = [
        'correct',
        'correct_position',
        'correct_problem',
        'correct_solution',
        'expected',
        'expected_value',
        'answer_key',
        'required_pattern',
        'evaluation',
    ];

    public static function strip(mixed $value): mixed {
        if (!is_array($value)) {
            return $value;
        }
        $sanitized = [];
        foreach ($value as $key => $child) {
            if (is_string($key) && in_array($key, self::SOLUTION_KEYS, true)) {
                continue;
            }
            $sanitized[$key] = self::strip($child);
        }
        return $sanitized;
    }
}
