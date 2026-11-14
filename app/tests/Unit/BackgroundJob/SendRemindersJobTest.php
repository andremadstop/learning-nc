<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\BackgroundJob;

use OCA\Learning\BackgroundJob\SendRemindersJob;
use OCA\Learning\Service\RecertReminderService;
use OCA\Learning\Service\ReminderService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Audit #6: the hourly TimedJob drifts (a run at 08:58 can be followed by one at 10:03), so
 * the daily reminders must not require the run to land exactly in hour 9 / hour 20. The
 * clock comes from ITimeFactory, not from new DateTimeImmutable('now').
 */
#[AllowMockObjectsWithoutExpectations]
class SendRemindersJobTest extends TestCase {

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool}>
     */
    public static function hours(): array {
        return [
            'before the morning window' => ['08:59', false, false],
            'exactly 09:xx' => ['09:30', true, false],
            'drifted past 09 (10:03)' => ['10:03', true, false],
            'afternoon' => ['15:00', true, false],
            'exactly 20:xx' => ['20:10', true, true],
            'drifted past 20 (21:02)' => ['21:02', true, true],
        ];
    }

    #[DataProvider('hours')]
    public function testDailyWindowsStayOpenAfterTheirStartHour(string $clock, bool $expectDue, bool $expectStreak): void {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getDateTime')->willReturn(new \DateTime('2026-11-14 ' . $clock . ':00'));
        $time->method('getTime')->willReturn(1763100000);

        $reminders = $this->createMock(ReminderService::class);
        $reminders->expects($this->once())->method('sendExamReminders')->willReturn(0);
        $reminders->expects($expectDue ? $this->once() : $this->never())->method('sendDueCardReminders')->willReturn(0);
        $reminders->expects($expectStreak ? $this->once() : $this->never())->method('sendStreakWarnings')->willReturn(0);

        $recert = $this->createMock(RecertReminderService::class);
        $recert->expects($expectDue ? $this->once() : $this->never())->method('sendRecertReminders')->willReturn(0);

        $job = new SendRemindersJob($time, $reminders, $recert, $this->createMock(LoggerInterface::class));
        $method = new \ReflectionMethod($job, 'run');
        $method->setAccessible(true);
        $method->invoke($job, null);
    }
}
