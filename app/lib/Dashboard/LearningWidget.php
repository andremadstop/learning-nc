<?php
declare(strict_types=1);
namespace OCA\Learning\Dashboard;

use OCA\Learning\Db\CourseMapper;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;

class LearningWidget implements IAPIWidgetV2, IIconWidget {
    private CourseMapper $courseMapper;
    private IURLGenerator $urlGenerator;
    private IL10N $l10n;

    public function __construct(CourseMapper $courseMapper, IURLGenerator $urlGenerator, IL10N $l10n) {
        $this->courseMapper = $courseMapper;
        $this->urlGenerator = $urlGenerator;
        $this->l10n = $l10n;
    }

    public function getId(): string {
        return 'learning';
    }

    public function getTitle(): string {
        return $this->l10n->t('Exam countdown');
    }

    public function getOrder(): int {
        return 10;
    }

    public function getIconClass(): string {
        return 'icon-category-education';
    }

    public function getIconUrl(): string {
        // IIconWidget requires an absolute url ("Get the absolute url for the widget icon").
        return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('core', 'actions/star.svg'));
    }

    public function getUrl(): ?string {
        return $this->urlGenerator->linkToRouteAbsolute('learning.page.index');
    }

    public function load(): void {
    }

    public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
        $items = [];
        $appUrl = $this->urlGenerator->linkToRouteAbsolute('learning.page.index');
        $primaryExam = $this->selectPrimaryExamCourse($this->courseMapper->findStudentExamCourses($userId));

        if ($primaryExam !== null) {
            $items[] = new WidgetItem(
                $this->buildCountdownTitle($primaryExam['exam_date']),
                $primaryExam['title'] . ' - ' . self::formatExamDate($primaryExam['exam_date']),
                $appUrl,
                '',
                'exam-course-' . (string)$primaryExam['id']
            );
        } else {
            $items[] = new WidgetItem(
                $this->l10n->t('No exam scheduled'),
                $this->l10n->t('Your next exam date appears here as soon as a course has one.'),
                $appUrl,
                '',
                'exam-course-empty'
            );
        }

        return new WidgetItems($items, '');
    }

    private function selectPrimaryExamCourse(array $courses): ?array {
        $today = new \DateTimeImmutable('today');
        $upcoming = [];
        $todayCourses = [];
        $past = [];

        foreach ($courses as $course) {
            $examDate = $course['exam_date'] ?? null;
            if (!is_string($examDate)) {
                continue;
            }

            $parsedDate = self::parseExamDay($examDate);
            if ($parsedDate === null) {
                continue;
            }

            if ($parsedDate > $today) {
                $upcoming[] = $course;
                continue;
            }

            if ($parsedDate < $today) {
                $past[] = $course;
                continue;
            }

            $todayCourses[] = $course;
        }

        if ($todayCourses !== []) {
            return $todayCourses[0];
        }

        if ($upcoming !== []) {
            return $upcoming[0];
        }

        if ($past !== []) {
            return $past[count($past) - 1];
        }

        return null;
    }

    /**
     * The calendar day of a course's exam date, or null if it is not a valid date.
     *
     * The course tab saves YYYY-MM-DDTHH:MM (<input type="datetime-local">); older courses hold
     * YYYY-MM-DD. Parsing only the second form skipped every date set in the current UI, and the
     * widget said "No exam scheduled" (GitHub #35). The countdown counts whole days,
     * so the time of day is dropped: an exam today at 14:00 is "today", not "0 days".
     */
    public static function parseExamDay(string $examDate): ?\DateTimeImmutable {
        $examDate = trim($examDate);
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $examDate) === 1) {
            $format = 'Y-m-d\\TH:i';
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $examDate) === 1) {
            $format = '!Y-m-d';
        } else {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat($format, $examDate);
        $errors = \DateTimeImmutable::getLastErrors();
        $hasWarnings = is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);
        // Round trip rejects overflowing values such as 2026-02-30 that PHP would roll over.
        if ($parsed === false || $hasWarnings || $parsed->format(str_replace('!', '', $format)) !== $examDate) {
            return null;
        }
        return $parsed->setTime(0, 0);
    }

    /** YYYY-MM-DDTHH:MM reads as "YYYY-MM-DD HH:MM" in the widget's subtitle. */
    private static function formatExamDate(string $examDate): string {
        return str_replace('T', ' ', $examDate);
    }

    private function buildCountdownTitle(string $examDate): string {
        $today = new \DateTimeImmutable('today');
        $parsedDate = self::parseExamDay($examDate);
        if ($parsedDate === null) {
            return $this->l10n->t('Exam date scheduled');
        }

        if ($parsedDate < $today) {
            return $this->l10n->t('Exam date passed');
        }

        if ($parsedDate == $today) {
            return $this->l10n->t('Exam is today');
        }

        $days = (int)$today->diff($parsedDate)->days;
        return $this->l10n->n('%n day until exam', '%n days until exam', $days);
    }
}
