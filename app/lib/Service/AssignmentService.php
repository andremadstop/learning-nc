<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Manages course assignments to NC users and groups.
 *
 * ASSIGN-04: This service does NOT gate certificate issuance.
 * Self-enrolled learners (no assignment row) continue to receive certs.
 * Cert issuance is solely controlled by PassCriteriaService + IssuanceService.
 */
class AssignmentService {
    public function __construct(
        private readonly IDBConnection $db,
        private readonly IGroupManager $groupManager,
        private readonly AuditService  $auditService,
    ) {}

    /**
     * Create one active assignment period.
     *
     * active_period_key = "{courseId}:{subjectType}:{subjectId}"
     * A new period row is always inserted (PLAIN index allows history rows).
     * The UNIQUE active_period_key enforces at most one active period.
     *
     * @throws \RuntimeException if active_period_key already exists (active period conflict)
     */
    public function createAssignment(
        int $courseId, string $subjectType, string $subjectId,
        string $assignedBy, ?int $dueDate
    ): void {
        $periodKey = "{$courseId}:{$subjectType}:{$subjectId}";
        $now = time();

        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_assignments')
            ->values([
                'course_id'         => $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT),
                'subject_type'      => $qb->createNamedParameter($subjectType),
                'subject_id'        => $qb->createNamedParameter($subjectId),
                'assigned_by'       => $qb->createNamedParameter($assignedBy),
                'assigned_at'       => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                'due_date'          => $qb->createNamedParameter($dueDate, $dueDate === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT),
                'status'            => $qb->createNamedParameter('assigned'),
                'active_period_key' => $qb->createNamedParameter($periodKey),
            ]);
        $qb->executeStatement();
    }

    /**
     * Extend the due date for the active assignment of a subject.
     *
     * ASSIGN-05: deadline extension is an admin action — logged via logEvent() NOT logComplianceEvent().
     * logEvent() swallows DB errors (non-critical); the extension itself is the critical operation.
     */
    public function extendDeadline(
        int $courseId, string $subjectType, string $subjectId,
        int $newDueDate, string $adminUserId
    ): void {
        $periodKey = "{$courseId}:{$subjectType}:{$subjectId}";

        $qb = $this->db->getQueryBuilder();
        $qb->update('learning_assignments')
            ->set('due_date', $qb->createNamedParameter($newDueDate, IQueryBuilder::PARAM_INT))
            ->where($qb->expr()->eq(
                'active_period_key',
                $qb->createNamedParameter($periodKey)
            ));
        $qb->executeStatement();

        // ASSIGN-05: admin action audit — use logEvent (swallowing) NOT logComplianceEvent
        $this->auditService->logEvent('assignment.deadline.extended', $adminUserId, [
            'course_id'    => $courseId,
            'subject_type' => $subjectType,
            'subject_id'   => $subjectId,
            'new_due_date' => $newDueDate,
        ]);
    }

    /**
     * Advance assignment status to 'in_progress' if currently 'assigned'. Idempotent.
     */
    public function markInProgress(string $userId, int $courseId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('learning_assignments')
            ->set('status', $qb->createNamedParameter('in_progress'))
            ->where($qb->expr()->andX(
                $qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)),
                $qb->expr()->eq('subject_id', $qb->createNamedParameter($userId)),
                $qb->expr()->eq('subject_type', $qb->createNamedParameter('user')),
                $qb->expr()->eq('status', $qb->createNamedParameter('assigned')),
                $qb->expr()->isNotNull('active_period_key')
            ));
        $qb->executeStatement();
    }

    /**
     * Advance assignment status to 'passed' for matching active assignment.
     * Called by PassCriteriaService AFTER cert issuance (not a gate — if no row exists, no-op).
     */
    public function markPassed(string $userId, int $courseId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('learning_assignments')
            ->set('status', $qb->createNamedParameter('passed'))
            ->where($qb->expr()->andX(
                $qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)),
                $qb->expr()->eq('subject_id', $qb->createNamedParameter($userId)),
                $qb->expr()->eq('subject_type', $qb->createNamedParameter('user')),
                $qb->expr()->isNotNull('active_period_key')
            ));
        $qb->executeStatement();
    }

    /**
     * Assignment state for each user in the given list for a course.
     *
     * Only active periods (active_period_key IS NOT NULL) are returned.
     * Chunked to 999 per PARAM_STR_ARRAY limits.
     *
     * @param list<string> $userIds
     * @return array<string, array{status: string, due_date: int|null}> keyed by subject_id (user UID)
     */
    public function getStatesForCourseAndUsers(int $courseId, array $userIds): array {
        if ($userIds === []) {
            return [];
        }
        $stateMap = [];
        foreach (array_chunk($userIds, 999) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('subject_id', 'status', 'due_date')
               ->from('learning_assignments')
               ->where($qb->expr()->eq('course_id', $qb->createNamedParameter($courseId, IQueryBuilder::PARAM_INT)))
               ->andWhere($qb->expr()->eq('subject_type', $qb->createNamedParameter('user')))
               ->andWhere($qb->expr()->isNotNull('active_period_key'))
               ->andWhere($qb->expr()->in('subject_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)));
            $result = $qb->executeQuery();
            while (($row = $result->fetch()) !== false) {
                if (!is_array($row)) {
                    break;
                }
                $uid = $row['subject_id'];
                $stateMap[$uid] = [
                    'status'   => $row['status'],
                    'due_date' => $row['due_date'] !== null ? (int) $row['due_date'] : null,
                ];
            }
            $result->closeCursor();
        }
        return $stateMap;
    }

    /**
     * Expand a NC group to its member UIDs (LDAP-transparent via NC IGroupManager).
     *
     * ASSIGN-02: IGroupManager::get($gid)->getUsers() resolves LDAP/AD/SAML/local backends
     * identically — no custom LDAP code needed.
     *
     * @return list<string> list of NC user UIDs
     * @throws \InvalidArgumentException if group does not exist
     */
    public function expandGroup(string $gid): array {
        $group = $this->groupManager->get($gid); // NC 33: get() not getGroup() (IGroupManager.php:58)
        if ($group === null) {
            throw new \InvalidArgumentException("Group '$gid' not found");
        }
        return array_values(array_map(
            static fn(\OCP\IUser $u): string => $u->getUID(),
            $group->getUsers()
        ));
    }
}
