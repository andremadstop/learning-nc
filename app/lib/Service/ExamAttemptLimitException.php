<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

/**
 * GitHub #87: the learner has used up the counting exam attempts of the current certification
 * cycle on this pool (ExamAttemptLimitService). The message is a stable code the client
 * translates; the instructor can open a new cycle.
 */
class ExamAttemptLimitException extends \Exception {
    public const CODE = 'exam_attempts_exhausted';

    public function __construct() {
        parent::__construct(self::CODE);
    }
}
