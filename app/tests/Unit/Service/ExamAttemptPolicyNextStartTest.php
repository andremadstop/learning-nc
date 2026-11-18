<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\ExamAttemptPolicy;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #92: after a failed attempt the result screen counts down to the next possible start.
 * nextStartAt() must name the moment TrainingService::enforceExamStartPolicy() lets the start
 * through: not earlier (the learner clicks and is refused), not later (the learner waits for
 * nothing). Both rules: the cooldown after the last start and the starts per 24 hours.
 */
class ExamAttemptPolicyNextStartTest extends TestCase {

    private const NOW = 1_800_000_000;

    private function policy(int $limitPerDay, int $cooldownMinutes): ExamAttemptPolicy {
        $config = $this->createStub(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            static fn(string $app, string $key, string $default): string => match ($key) {
                'exam_attempt_limit_per_day' => (string)$limitPerDay,
                'exam_attempt_cooldown_minutes' => (string)$cooldownMinutes,
                default => $default,
            },
        );
        return new ExamAttemptPolicy($config);
    }

    public function testNoAttemptMeansNoWait(): void {
        $this->assertNull($this->policy(5, 10)->nextStartAt([], self::NOW));
    }

    public function testCooldownCountsFromTheLastStart(): void {
        $lastStart = self::NOW - 4 * 60;
        $this->assertSame($lastStart + 600, $this->policy(5, 10)->nextStartAt([self::NOW - 3600, $lastStart], self::NOW));
    }

    public function testCooldownOverIsNoWait(): void {
        $this->assertNull($this->policy(5, 10)->nextStartAt([self::NOW - 600], self::NOW));
    }

    public function testNoCooldownAndRoomLeftIsNoWait(): void {
        $this->assertNull($this->policy(5, 0)->nextStartAt([self::NOW - 5], self::NOW));
    }

    public function testDailyLimitWaitsUntilTheOldestCountedStartLeavesTheWindow(): void {
        // three starts in the last 24 hours, limit 3: the gate counts started_at >= now - 86400,
        // so a fourth start is allowed once the oldest is strictly older than that.
        $starts = [self::NOW - 80000, self::NOW - 7200, self::NOW - 3600];
        $next = $this->policy(3, 10)->nextStartAt($starts, self::NOW);
        $this->assertSame(self::NOW - 80000 + 86401, $next);
        // the gate's own rule at that moment and one second before
        $this->assertFalse($this->gateRefusesByDailyLimit($starts, 3, $next));
        $this->assertTrue($this->gateRefusesByDailyLimit($starts, 3, $next - 1));
    }

    public function testDailyLimitAndCooldownTakeTheLaterOfTheTwo(): void {
        // limit 2: the older start leaves the window in ~1 minute, the cooldown of 60 min lasts longer
        $starts = [self::NOW - 86340, self::NOW - 600];
        $this->assertSame(self::NOW - 600 + 3600, $this->policy(2, 60)->nextStartAt($starts, self::NOW));
    }

    public function testStartsOutsideTheWindowAreIgnored(): void {
        $this->assertNull($this->policy(1, 10)->nextStartAt([self::NOW - 90000], self::NOW));
    }

    /**
     * The daily rule as TrainingService::enforceExamStartPolicy() applies it.
     *
     * @param list<int> $starts
     */
    private function gateRefusesByDailyLimit(array $starts, int $limit, int $now): bool {
        $inWindow = count(array_filter($starts, static fn(int $s): bool => $s >= $now - 86400));
        return $inWindow >= $limit;
    }
}
