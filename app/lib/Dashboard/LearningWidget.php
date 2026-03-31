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
        return $this->urlGenerator->imagePath('core', 'actions/star.svg');
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
                $primaryExam['title'] . ' - ' . $primaryExam['exam_date'],
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

            $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $examDate);
            $errors = \DateTimeImmutable::getLastErrors();
            $hasWarnings = is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);
            if ($parsedDate === false || $hasWarnings) {
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

    private function buildCountdownTitle(string $examDate): string {
        $today = new \DateTimeImmutable('today');
        $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $examDate);
        if ($parsedDate === false) {
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
