<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\StreakService;
use PHPUnit\Framework\TestCase;

/**
 * Audit #83: a streak whose newest day is two days ago only survives because yesterday is
 * bridged. That bridge takes the token, so the same token cannot bridge a second gap. The
 * charge itself happens once today's session is completed (the loop bridges yesterday then).
 */
final class StreakFreezeTest extends TestCase {
    private const TODAY = '2026-11-14';

    /** @return array{0: int, 1: bool} */
    private function compute(array $daysAgo, bool $canUseFreeze): array {
        $dates = array_map(fn (int $d) => gmdate('Y-m-d', strtotime('-' . $d . ' day', strtotime(self::TODAY))), $daysAgo);
        $m = new \ReflectionMethod(StreakService::class, 'currentStreakFromDates');
        $m->setAccessible(true);
        return $m->invoke(null, $dates, self::TODAY, $canUseFreeze);
    }

    public function testLeadingTwoDayGapTakesTheTokenAndBlocksASecondBridge(): void {
        // Not charged yet: per-question XP calls run before today's session is completed.
        $this->assertSame([2, false], $this->compute([2, 3, 5], true));
    }

    public function testCompletingTodayAfterTheLeadingGapChargesTheBridgeOnce(): void {
        // [today, today-2, today-3, today-5]: yesterday is bridged (charged), today-4 is not.
        $this->assertSame([3, true], $this->compute([0, 2, 3, 5], true));
    }

    public function testLeadingTwoDayGapWithoutTokenEndsTheStreak(): void {
        $this->assertSame([0, false], $this->compute([2, 3], false));
    }

    public function testActiveTodayBridgesOneGapInTheMiddle(): void {
        $this->assertSame([3, true], $this->compute([0, 1, 3, 5], true));
    }

    public function testActiveYesterdayWithoutGapUsesNoFreeze(): void {
        $this->assertSame([3, false], $this->compute([1, 2, 3], true));
    }
}
