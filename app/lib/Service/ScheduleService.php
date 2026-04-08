<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseSchedule;
use OCA\Learning\Db\CourseScheduleMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CurriculumScopeMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;

class ScheduleService {
    private CourseScheduleMapper $scheduleMapper;
    private CourseMapper $courseMapper;
    private CourseMemberMapper $courseMemberMapper;
    private CurriculumScopeMapper $curriculumScopeMapper;
    private IDBConnection $db;

    public function __construct(
        CourseScheduleMapper $scheduleMapper,
        CourseMapper $courseMapper,
        CourseMemberMapper $courseMemberMapper,
        CurriculumScopeMapper $curriculumScopeMapper,
        IDBConnection $db
    ) {
        $this->scheduleMapper = $scheduleMapper;
        $this->courseMapper = $courseMapper;
        $this->courseMemberMapper = $courseMemberMapper;
        $this->curriculumScopeMapper = $curriculumScopeMapper;
        $this->db = $db;
    }

    /**
     * Get the schedule for a course. Only instructors may access this.
     *
     * @return array{schedule: array, chapters_in_scope: string[]}
     */
    public function getSchedule(int $courseId, string $userId): array {
        $this->requireInstructor($courseId, $userId);

        $entries = $this->scheduleMapper->findByCourse($courseId);
        $scopeKeys = $this->getActiveScopeChapterRefs($courseId);

        $result = [];
        foreach ($entries as $entry) {
            $result[] = $entry->jsonSerialize();
        }

        return [
            'schedule' => $result,
            'chapters_in_scope' => $scopeKeys,
        ];
    }

    /**
     * Bulk-save schedule entries for a course (replaces all existing).
     *
     * @param array $entries Array of {chapter_ref, chapter_title?, start_date?, target_date?, sort_order?}
     * @return array{schedule: array}
     */
    public function setSchedule(int $courseId, array $entries, string $userId): array {
        $this->requireInstructor($courseId, $userId);

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // Validate entries before touching DB
        $prepared = [];
        $seenRefs = [];
        foreach ($entries as $i => $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException("Entry $i must be an object");
            }
            $chapterRef = trim((string)($entry['chapter_ref'] ?? ''));
            if ($chapterRef === '') {
                throw new \InvalidArgumentException("Entry $i: chapter_ref is required");
            }
            if (strlen($chapterRef) > 255) {
                throw new \InvalidArgumentException("Entry $i: chapter_ref too long");
            }
            if (isset($seenRefs[$chapterRef])) {
                throw new \InvalidArgumentException("Entry $i: duplicate chapter_ref '$chapterRef'");
            }
            $seenRefs[$chapterRef] = true;

            $chapterTitle = isset($entry['chapter_title']) ? substr(trim((string)$entry['chapter_title']), 0, 255) : null;
            $startDate = $this->normalizeDate($entry['start_date'] ?? null, "Entry $i: start_date");
            $targetDate = $this->normalizeDate($entry['target_date'] ?? null, "Entry $i: target_date");
            $sortOrder = (int)($entry['sort_order'] ?? $i);

            $prepared[] = [
                'chapter_ref' => $chapterRef,
                'chapter_title' => $chapterTitle ?: null,
                'start_date' => $startDate,
                'target_date' => $targetDate,
                'sort_order' => $sortOrder,
            ];
        }

        // Delete old entries and insert new ones
        $this->scheduleMapper->deleteByCourse($courseId);

        $result = [];
        foreach ($prepared as $data) {
            $entity = new CourseSchedule();
            $entity->setCourseId($courseId);
            $entity->setChapterRef($data['chapter_ref']);
            $entity->setChapterTitle($data['chapter_title']);
            $entity->setStartDate($data['start_date']);
            $entity->setTargetDate($data['target_date']);
            $entity->setSortOrder($data['sort_order']);
            $entity->setCreatedAt($now);
            $entity->setUpdatedAt($now);

            /** @var CourseSchedule $saved */
            $saved = $this->scheduleMapper->insert($entity);
            $result[] = $saved->jsonSerialize();
        }

        return ['schedule' => $result];
    }

    /**
     * Delete all schedule entries for a course.
     */
    public function deleteSchedule(int $courseId, string $userId): void {
        $this->requireInstructor($courseId, $userId);
        $this->scheduleMapper->deleteByCourse($courseId);
    }

    /**
     * Get chapter references that are in the active curriculum scope for a course.
     * Uses the pools' chapter_key column joined with curriculum_scope chapter_keys.
     *
     * @return string[]
     */
    private function getActiveScopeChapterRefs(int $courseId): array {
        $scope = $this->curriculumScopeMapper->findByCourse($courseId);
        if ($scope === null || !$scope->getEnabled()) {
            // No scope filtering — return all chapter_refs from course pools
            return $this->getAllChapterRefsForCourse($courseId);
        }

        $activeKeys = $scope->getChapterKeys();
        if (empty($activeKeys)) {
            return [];
        }

        // chapter_keys in curriculum_scope correspond to question.chapter_key,
        // but schedule uses pool-level chapter_ref. Return active chapter_keys
        // since they represent the curriculum chapters.
        return $activeKeys;
    }

    /**
     * Get all distinct chapter_key values from pools attached to this course.
     *
     * @return string[]
     */
    private function getAllChapterRefsForCourse(int $courseId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('p.chapter_key')
            ->from('learning_course_pools', 'cp')
            ->innerJoin('cp', 'learning_pools', 'p', $qb->expr()->eq('cp.pool_id', 'p.id'))
            ->where($qb->expr()->eq('cp.course_id', $qb->createNamedParameter($courseId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNotNull('p.chapter_key'))
            ->andWhere($qb->expr()->neq('p.chapter_key', $qb->createNamedParameter('')))
            ->orderBy('p.chapter_key', 'ASC');

        $result = $qb->executeQuery();
        $refs = [];
        while ($row = $result->fetch()) {
            $refs[] = (string)$row['chapter_key'];
        }
        $result->closeCursor();

        return $refs;
    }

    /**
     * Ensure the user is an instructor of the given course.
     *
     * @throws ForbiddenException
     */
    private function requireInstructor(int $courseId, string $userId): void {
        $course = $this->courseMapper->findById($courseId);
        if ($course->getInstructorId() === $userId) {
            return;
        }
        try {
            $member = $this->courseMemberMapper->findByCourseAndUser($courseId, $userId);
            if ($member->getRole() === 'instructor') {
                return;
            }
        } catch (DoesNotExistException $e) {
            // not a member
        }
        throw new ForbiddenException('No permission to manage schedule');
    }

    /**
     * Normalize a date string to Y-m-d or null.
     */
    private function normalizeDate(?string $date, string $context): ?string {
        if ($date === null || trim($date) === '') {
            return null;
        }
        $date = trim($date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException("$context: invalid date format (expected YYYY-MM-DD)");
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException("$context: invalid date");
        }
        return $date;
    }
}
