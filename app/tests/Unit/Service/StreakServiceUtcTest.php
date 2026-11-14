<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Service\StreakService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Audit #30: the streak days were rendered in SQL (TO_CHAR(TO_TIMESTAMP()), FROM_UNIXTIME), i.e.
 * in the session time zone of the database connection, and then compared with gmdate() days. On a
 * database running in Europe/Berlin a session at 23:30 UTC counted for the next day. The day is
 * now formed in PHP from the raw completed_at, in UTC, whatever the database's zone.
 */
#[AllowMockObjectsWithoutExpectations]
final class StreakServiceUtcTest extends TestCase {
    private function service(array $completedAt): array {
        $sessions = new FakeQueryBuilder(new FakeResult(array_map(
            static fn (int $ts): array => ['completed_at' => (string)$ts],
            $completedAt
        )));
        $freeze = new FakeQueryBuilder(FakeResult::fromFetch(['streak_freeze_tokens' => 0, 'last_freeze_reset_week' => gmdate('o-\WW')]));
        $db = new FakeDbConnection([$sessions, $freeze]);
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturn('yes');
        return [new StreakService($db, $config), $sessions];
    }

    public function testDaysAreUtcDaysOfTheRawTimestamps(): void {
        $todayStart = (int)strtotime(gmdate('Y-m-d') . ' 00:00:00 UTC');
        [$service, $sessions] = $this->service([
            $todayStart + 600,           // today 00:10 UTC
            $todayStart - 1800,          // yesterday 23:30 UTC (Berlin: already today)
            $todayStart - 1900,          // the same UTC day again
            $todayStart - 86400 - 1800,  // the day before, 23:30 UTC
        ]);

        $streak = $service->getStreak('alice');

        $this->assertSame(3, $streak['current_streak']);
        $this->assertSame(3, $streak['longest_streak']);
        $this->assertSame(gmdate('Y-m-d'), $streak['last_activity_date']);
        $this->assertTrue($streak['is_active_today']);
        // No date function in SQL: the database's time zone cannot enter
        $this->assertSame(['completed_at'], $sessions->selects);
    }

    public function testAnEveningSessionYesterdayKeepsTheStreak(): void {
        $todayStart = (int)strtotime(gmdate('Y-m-d') . ' 00:00:00 UTC');
        [$service] = $this->service([$todayStart - 1800, $todayStart - 86400 - 1800]);

        $streak = $service->getStreak('alice');

        $this->assertSame(2, $streak['current_streak']);
        $this->assertSame(gmdate('Y-m-d', $todayStart - 1800), $streak['last_activity_date']);
        $this->assertFalse($streak['is_active_today']);
    }
}
