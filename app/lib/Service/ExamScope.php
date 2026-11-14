<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CoursePool;

/**
 * GitHub #47: the scope of a counting exam in a course: how many questions and how many minutes.
 *
 * Each value is resolved on its own: the pool rule of the course, else the course default, else
 * none. A value of none keeps the behaviour before #47 (question count from the pool, time limit
 * from the exam card within the server's bounds). The single source for the exam start
 * (TrainingService) and for the exam card of the learners (CourseService pool enrichment).
 */
final class ExamScope {
    public const MIN_QUESTIONS = 1;
    /** Upper bound of the course default; a pool rule is bounded by the pool's question count. */
    public const MAX_QUESTIONS = 500;
    public const MIN_MINUTES = 1;
    /** Same upper bound as the practice exam's time limit. */
    public const MAX_MINUTES = 600;

    /**
     * @return array{questions: ?int, minutes: ?int}|null null when neither pool nor course sets a value
     */
    public static function resolve(?CoursePool $coursePool, ?Course $course): ?array {
        $questions = self::positive($coursePool?->getExamQuestions()) ?? self::positive($course?->getExamQuestions());
        $minutes = self::positive($coursePool?->getExamMinutes()) ?? self::positive($course?->getExamMinutes());
        if ($questions === null && $minutes === null) {
            return null;
        }
        return ['questions' => $questions, 'minutes' => $minutes];
    }

    private static function positive(mixed $value): ?int {
        if ($value === null || $value === '') {
            return null;
        }
        $int = (int)$value;
        return $int > 0 ? $int : null;
    }
}
