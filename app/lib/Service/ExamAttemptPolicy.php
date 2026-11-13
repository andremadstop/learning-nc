<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCP\IConfig;

/**
 * The admin's retake rules for counting exams (practice exams are exempt), in one place.
 *
 * TrainingService enforces them when an exam starts; the pass status shows them to learners
 * (GitHub #35), so the number a learner reads is the number that is enforced. Both limits apply
 * per pool: at most limitPerDay() attempts within a rolling 24 hours, and at least
 * cooldownMinutes() between two starts.
 */
class ExamAttemptPolicy {
    public const DEFAULT_LIMIT_PER_DAY = 5;
    public const DEFAULT_COOLDOWN_MINUTES = 10;

    public function __construct(
        private readonly IConfig $config,
    ) {}

    public function limitPerDay(): int {
        $raw = (int)$this->config->getAppValue('learning', 'exam_attempt_limit_per_day', (string)self::DEFAULT_LIMIT_PER_DAY);
        return max(1, min(50, $raw));
    }

    public function cooldownMinutes(): int {
        $raw = (int)$this->config->getAppValue('learning', 'exam_attempt_cooldown_minutes', (string)self::DEFAULT_COOLDOWN_MINUTES);
        return max(0, min(1440, $raw));
    }

    /**
     * @return array{limit_per_day: int, cooldown_minutes: int, scope: string}
     */
    public function toArray(): array {
        return [
            'limit_per_day' => $this->limitPerDay(),
            'cooldown_minutes' => $this->cooldownMinutes(),
            'scope' => 'pool',
        ];
    }
}
