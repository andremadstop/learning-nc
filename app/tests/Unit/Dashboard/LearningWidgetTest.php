<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Dashboard;

use OCA\Learning\Dashboard\LearningWidget;
use OCA\Learning\Db\CourseMapper;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #35: the course tab saves the exam date from <input type="datetime-local">, i.e.
 * YYYY-MM-DDTHH:MM. The dashboard widget parsed only YYYY-MM-DD, skipped every such course and
 * said "No exam scheduled". Both forms must count, on whole days.
 */
#[AllowMockObjectsWithoutExpectations]
class LearningWidgetTest extends TestCase {

    /**
     * @param list<array{id: int, title: string, exam_date: string}> $courses
     */
    private function widget(array $courses): LearningWidget {
        $mapper = $this->createMock(CourseMapper::class);
        $mapper->method('findStudentExamCourses')->willReturnCallback(function (string $userId) use ($courses): array {
            $this->assertSame('alice', $userId);
            return $courses;
        });
        $url = $this->createMock(IURLGenerator::class);
        $url->method('linkToRouteAbsolute')->willReturn('https://cloud.example/apps/learning/');
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn(string $text, array $params = []): string => vsprintf($text, $params));
        $l10n->method('n')->willReturnCallback(
            static fn(string $singular, string $plural, int $count): string => str_replace('%n', (string)$count, $count === 1 ? $singular : $plural)
        );
        return new LearningWidget($mapper, $url, $l10n);
    }

    /** @return list<\OCP\Dashboard\Model\WidgetItem> */
    private function items(array $courses): array {
        return $this->widget($courses)->getItemsV2('alice')->getItems();
    }

    public function testDateTimeFromTheCourseTabIsCountedDown(): void {
        $inTenDays = (new \DateTimeImmutable('today'))->modify('+10 days')->format('Y-m-d') . 'T09:30';

        $items = $this->items([['id' => 5, 'title' => 'Brandschutz', 'exam_date' => $inTenDays]]);

        $this->assertCount(1, $items);
        $this->assertSame('10 days until exam', $items[0]->getTitle());
        $this->assertSame('Brandschutz - ' . str_replace('T', ' ', $inTenDays), $items[0]->getSubtitle());
    }

    public function testDateOnlyFromOlderCoursesStillWorks(): void {
        $tomorrow = (new \DateTimeImmutable('today'))->modify('+1 day')->format('Y-m-d');

        $items = $this->items([['id' => 5, 'title' => 'Erste Hilfe', 'exam_date' => $tomorrow]]);

        $this->assertSame('1 day until exam', $items[0]->getTitle());
    }

    // Whole days: an exam later today is "today", not "0 days until exam" or "passed".
    public function testExamLaterTodayIsToday(): void {
        $todayAtNight = (new \DateTimeImmutable('today'))->format('Y-m-d') . 'T23:59';

        $items = $this->items([['id' => 5, 'title' => 'Brandschutz', 'exam_date' => $todayAtNight]]);

        $this->assertSame('Exam is today', $items[0]->getTitle());
    }

    public function testUpcomingDateTimeWinsOverAPastDate(): void {
        $past = (new \DateTimeImmutable('today'))->modify('-3 days')->format('Y-m-d');
        $future = (new \DateTimeImmutable('today'))->modify('+2 days')->format('Y-m-d') . 'T08:00';

        $items = $this->items([
            ['id' => 1, 'title' => 'Alt', 'exam_date' => $past],
            ['id' => 2, 'title' => 'Neu', 'exam_date' => $future],
        ]);

        $this->assertSame('2 days until exam', $items[0]->getTitle());
        $this->assertStringStartsWith('Neu - ', $items[0]->getSubtitle());
    }

    public function testInvalidDatesAreSkipped(): void {
        $items = $this->items([
            ['id' => 1, 'title' => 'Kaputt', 'exam_date' => '2026-02-30T09:00'],
            ['id' => 2, 'title' => 'Kaputt', 'exam_date' => 'next week'],
        ]);

        $this->assertCount(1, $items);
        $this->assertSame('No exam scheduled', $items[0]->getTitle());
    }

    public function testParseExamDayDropsTheTimeOfDay(): void {
        $this->assertSame('2026-12-01 00:00', LearningWidget::parseExamDay('2026-12-01T14:45')?->format('Y-m-d H:i'));
        $this->assertSame('2026-12-01 00:00', LearningWidget::parseExamDay('2026-12-01')?->format('Y-m-d H:i'));
        $this->assertNull(LearningWidget::parseExamDay('2026-13-01'));
        $this->assertNull(LearningWidget::parseExamDay('2026-12-01 14:45'));
    }
}
