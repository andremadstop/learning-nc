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
 * Audit #167 (= #30): the activity date came from TO_CHAR/FROM_UNIXTIME in the DB session's time
 * zone, 'today' from gmdate() (UTC). The query now returns the raw completed_at and PHP formats
 * the UTC day. The SQL side against both engines is covered by CourseFiguresScopeTest.
 */
#[AllowMockObjectsWithoutExpectations]
final class StreakServiceUtcDayTest extends TestCase {

    /** @param list<int> $completedAt newest first, as the query orders them */
    private function streak(array $completedAt, ?FakeQueryBuilder &$daysBuilder = null): array {
        $daysBuilder = new FakeQueryBuilder(new FakeResult(
            array_map(static fn(int $ts): array => ['completed_at' => (string)$ts], $completedAt)
        ));
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturn('yes');
        $db = new FakeDbConnection([
            $daysBuilder,
            new FakeQueryBuilder(FakeResult::fromFetch(false)), // freeze state: no stats row
        ]);
        return (new StreakService($db, $config))->getStreak('alice');
    }

    public function testDaysAreUtcDaysFormattedInPhp(): void {
        $todayStart = intdiv(time(), 86400) * 86400;

        // two sessions on the same UTC day count once
        $streak = $this->streak([$todayStart + 60, $todayStart, $todayStart - 86400 + 3600, $todayStart - 2 * 86400], $builder);

        $this->assertSame(3, $streak['current_streak']);
        $this->assertTrue($streak['is_active_today']);
        $this->assertSame(gmdate('Y-m-d'), $streak['last_activity_date']);

        $select = implode(' ', array_map('strval', $builder->selects));
        $this->assertStringContainsString('completed_at', $select);
        $this->assertStringNotContainsString('TO_CHAR', $select, 'no DB time zone in the date');
        $this->assertStringNotContainsString('FROM_UNIXTIME', $select, 'no DB time zone in the date');
    }

    public function testYesterdayKeepsTheStreakAlive(): void {
        $todayStart = intdiv(time(), 86400) * 86400;

        $streak = $this->streak([$todayStart - 86400 + 82800]);

        $this->assertSame(1, $streak['current_streak']);
        $this->assertFalse($streak['is_active_today']);
    }
}
