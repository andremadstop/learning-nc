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
        $course = $this->courseMapper->findById($courseId);

        // Check instructor/admin permission
        $this->assertInstructorOrAdmin($courseId, $userId);

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
        $snapshotId = (int) $this->db->lastInsertId('learning_course_snapshots');

        // Set course status to archived
        $course->setStatus('archived');
        $this->courseMapper->update($course);

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
     * Get a specific snapshot by ID.
     *
     * @return array<string, mixed>|null
     * @throws ForbiddenException if user lacks permission
     */
    public function getSnapshot(int $snapshotId, string $userId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('learning_course_snapshots')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($snapshotId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if ($row === false) {
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
     * Check if user is instructor for the course or admin.
     *
     * @throws ForbiddenException
     */
    private function assertInstructorOrAdmin(int $courseId, string $userId): void {
        if ($this->roleService->isInstructor($userId)) {
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
