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
 * cooldownMinutes() between two starts. "Per pool" means per pool in one context (GitHub #47):
 * a course exam counts only the exams of that pool in that course, the pool window only its own.
 * toArray() names this scope 'pool_context', and the texts say "per pool in this course".
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
            'scope' => 'pool_context',
        ];
    }

    /**
     * GitHub #92: when the next counting exam on a pool may start, for the countdown after a
     * failed attempt. The same two rules TrainingService::enforceExamStartPolicy() enforces: a
     * start is refused while limitPerDay() starts lie within the last 24 hours (started_at >=
     * now - 86400), and before the last start plus cooldownMinutes(). The cooldown is at most
     * 1440 minutes, so the starts of the last 24 hours are all this needs.
     *
     * @param list<int> $startsLast24h the started_at of the counting attempts on that pool in that
     *   context (course or pool window) since $now - 86400, any order
     * @return int|null the earliest allowed start, or null when a start is allowed now
     */
    public function nextStartAt(array $startsLast24h, int $now): ?int {
        $starts = array_values(array_filter(
            array_map('intval', $startsLast24h),
            static fn(int $s): bool => $s >= $now - 86400,
        ));
        if ($starts === []) {
            return null;
        }
        rsort($starts);
        $next = 0;
        $cooldown = $this->cooldownMinutes();
        if ($cooldown > 0) {
            $next = $starts[0] + $cooldown * 60;
        }
        $limit = $this->limitPerDay();
        if (count($starts) >= $limit) {
            // The window holds fewer than $limit starts once the $limit-th newest one has left it,
            // i.e. once started_at < now - 86400.
            $next = max($next, $starts[$limit - 1] + 86401);
        }
        return $next > $now ? $next : null;
    }
}
