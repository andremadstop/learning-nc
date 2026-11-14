<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Service\DataMobilityService;
use OCA\Learning\Service\RoleService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Service for archiving courses and managing snapshots.
 */
class CourseArchiveService {
    private CourseMapper $courseMapper;
    private CourseMemberMapper $courseMemberMapper;
    private DataMobilityService $dataMobilityService;
    private RoleService $roleService;
    private IDBConnection $db;
    private LoggerInterface $logger;

    public function __construct(
        CourseMapper $courseMapper,
        CourseMemberMapper $courseMemberMapper,
        DataMobilityService $dataMobilityService,
        RoleService $roleService,
        IDBConnection $db,
        LoggerInterface $logger
    ) {
        $this->courseMapper = $courseMapper;
        $this->courseMemberMapper = $courseMemberMapper;
        $this->dataMobilityService = $dataMobilityService;
        $this->roleService = $roleService;
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Archive a course: create snapshot + set status to 'archived'.
     *
     * @return array{snapshot_id: int, course_id: int}
     * @throws DoesNotExistException if course not found
     * @throws ForbiddenException if user lacks permission
     */
    public function archiveCourse(int $courseId, string $userId): array {
        $snapshotId = $this->snapshotCourse($courseId, $userId);
        $this->markArchived($courseId);

        $this->logger->info('Course {courseId} archived by {userId}, snapshot {snapshotId}', [
            'courseId' => $courseId,
            'userId' => $userId,
            'snapshotId' => $snapshotId,
            'app' => 'learning',
        ]);

        return [
            'snapshot_id' => $snapshotId,
            'course_id' => $courseId,
        ];
    }

    /**
     * Archive from occ. Whoever runs occ administers the server, so there is no user whose
     * course rights could be checked; the old command passed the user 'admin', which failed on
     * every instance without an admin account of that name (audit #135). $actor is recorded as
     * the snapshot's creator.
     *
     * @return array{snapshot_id: int, course_id: int}
     * @throws DoesNotExistException if course not found
     */
    public function archiveCourseFromConsole(int $courseId, string $actor): array {
        $this->courseMapper->findById($courseId);
        $snapshotId = $this->insertSnapshot($courseId, $actor);
        $this->markArchived($courseId);

        $this->logger->info('Course {courseId} archived via occ as {actor}, snapshot {snapshotId}', [
            'courseId' => $courseId,
            'actor' => $actor,
            'snapshotId' => $snapshotId,
            'app' => 'learning',
        ]);

        return [
            'snapshot_id' => $snapshotId,
            'course_id' => $courseId,
        ];
    }

    /**
     * Take a snapshot of the course without changing it.
     *
     * @throws DoesNotExistException if course not found
     * @throws ForbiddenException if user lacks permission
     */
    public function snapshotCourse(int $courseId, string $userId): int {
        $this->courseMapper->findById($courseId);

        // Check instructor/admin permission
        $this->assertInstructorOrAdmin($courseId, $userId);

        return $this->insertSnapshot($courseId, $userId);
    }

    /**
     * Set the course status to 'archived'. Inside a transaction of the caller, a rollback undoes
     * it (the merge archives its source course this way).
     *
     * @throws DoesNotExistException if course not found
     */
    public function markArchived(int $courseId): void {
        $course = $this->courseMapper->findById($courseId);
        $course->setStatus('archived');
        $this->courseMapper->update($course);
    }

    private function insertSnapshot(int $courseId, string $userId): int {
        // Create JSON snapshot via DataMobilityService
        $snapshotData = $this->dataMobilityService->exportCourse($courseId);
        $snapshotJson = json_encode($snapshotData, JSON_UNESCAPED_UNICODE);
        if ($snapshotJson === false) {
            throw new \RuntimeException('Failed to encode course snapshot as JSON');
        }

        // Insert snapshot into DB (uses existing course_snapshots table from Phase 104)
        $now = time();
        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_course_snapshots')
            ->values([
                'course_id' => $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT),
                'user_id' => $qb->createNamedParameter($userId),
                'snapshot_data' => $qb->createNamedParameter($snapshotJson),
                'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
            ]);
        $qb->executeStatement();
        return (int) $this->db->lastInsertId('learning_course_snapshots');
    }

    /**
     * Read a snapshot from occ (see archiveCourseFromConsole()).
     *
     * @return array<string, mixed>|null
     */
    public function getSnapshotFromConsole(int $snapshotId): ?array {
        $row = $this->findSnapshotRow($snapshotId);
        return $row === null ? null : $this->formatSnapshotRow($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findSnapshotRow(int $snapshotId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('learning_course_snapshots')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($snapshotId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return $row === false ? null : $row;
    }

    /**
     * Get a specific snapshot by ID.
     *
     * @return array<string, mixed>|null
     * @throws ForbiddenException if user lacks permission
     */
    public function getSnapshot(int $snapshotId, string $userId): ?array {
        $row = $this->findSnapshotRow($snapshotId);
        if ($row === null) {
            return null;
        }

        // Check permission on the course
        $this->assertInstructorOrAdmin((int) $row['course_id'], $userId);

        return $this->formatSnapshotRow($row);
    }

    /**
     * List all snapshots for a course.
     *
     * @return array<int, array<string, mixed>>
     * @throws ForbiddenException if user lacks permission
     */
    public function listSnapshots(int $courseId, string $userId): array {
        $this->assertInstructorOrAdmin($courseId, $userId);

        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'course_id', 'created_at', 'user_id')
            ->from('learning_course_snapshots')
            ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'DESC');
        $result = $qb->executeQuery();

        $snapshots = [];
        while ($row = $result->fetch()) {
            $snapshots[] = [
                'id' => (int) $row['id'],
                'course_id' => (int) $row['course_id'],
                'created_at' => (int) $row['created_at'],
                'created_by' => $row['user_id'],
            ];
        }
        $result->closeCursor();

        return $snapshots;
    }

    /**
     * Check if user is instructor of THIS course (owner or co-instructor) or a Nextcloud admin.
     *
     * Membership of the global instructor group is not enough: an instructor of course A must
     * not archive course B or read its snapshots (members + learning stats) — 2026-11 audit.
     *
     * @throws ForbiddenException
     */
    private function assertInstructorOrAdmin(int $courseId, string $userId): void {
        if ($this->roleService->isAdmin($userId)) {
            return;
        }

        // Check if user is the course owner
        try {
            $course = $this->courseMapper->findById($courseId);
            if ($course->getInstructorId() === $userId) {
                return;
            }
        } catch (DoesNotExistException $e) {
            // Course not found, will fail anyway
        }

        // Check if user is instructor member of the course
        try {
            $member = $this->courseMemberMapper->findByCourseAndUser($courseId, $userId);
            if ($member->getRole() === 'instructor') {
                return;
            }
        } catch (DoesNotExistException $e) {
            // Not a member
        }

        throw new ForbiddenException('Not authorized to manage course archives');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatSnapshotRow(array $row): array {
        $data = [
            'id' => (int) $row['id'],
            'course_id' => (int) $row['course_id'],
            'created_at' => (int) $row['created_at'],
            'created_by' => $row['user_id'],
        ];

        if (isset($row['snapshot_data'])) {
            $decoded = json_decode($row['snapshot_data'], true);
            $data['snapshot'] = is_array($decoded) ? $decoded : null;
        }

        return $data;
    }
}
