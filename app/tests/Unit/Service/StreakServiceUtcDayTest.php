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
 * Audit #167: the activity date came from TO_CHAR/FROM_UNIXTIME in the DB session's time zone,
 * 'today' from gmdate() (UTC). The query now returns the UTC day's midnight and PHP formats it.
 */
#[AllowMockObjectsWithoutExpectations]
final class StreakServiceUtcDayTest extends TestCase {

    /** @param list<int> $dayStarts */
    private function streak(array $dayStarts, ?FakeQueryBuilder &$daysBuilder = null): array {
        $daysBuilder = new FakeQueryBuilder(FakeResult::fromFetchAll(
            array_map(static fn(int $ts): array => ['day_start' => (string)$ts], $dayStarts)
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

        $streak = $this->streak([$todayStart, $todayStart - 86400, $todayStart - 2 * 86400], $builder);

        $this->assertSame(3, $streak['current_streak']);
        $this->assertTrue($streak['is_active_today']);
        $this->assertSame(gmdate('Y-m-d'), $streak['last_activity_date']);

        $select = implode(' ', array_map('strval', $builder->selects));
        $this->assertStringContainsString('% 86400', $select);
        $this->assertStringNotContainsString('TO_CHAR', $select, 'no DB time zone in the date');
        $this->assertStringNotContainsString('FROM_UNIXTIME', $select, 'no DB time zone in the date');
    }

    public function testYesterdayKeepsTheStreakAlive(): void {
        $todayStart = intdiv(time(), 86400) * 86400;

        $streak = $this->streak([$todayStart - 86400]);

        $this->assertSame(1, $streak['current_streak']);
        $this->assertFalse($streak['is_active_today']);
    }
}
