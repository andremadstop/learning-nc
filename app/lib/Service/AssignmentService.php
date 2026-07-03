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
     * Close the active assignment period for a subject, ending the obligation cycle.
     *
     * Write-set (RECERT-04, 164-04 corrected design — 4 logical writes, 3 DB round-trips):
     *   1+2 (single UPDATE on learning_certificates): set expires_at = now-1 so the old
     *        verify URL reads 'expired' (revoked=false, expires_at<now → 'expired' per
     *        CertificateVerifyService precedence: invalid→withdrawn→expired→valid), AND set
     *        active_idem_key = NULL to free the cert-level UNIQUE slot for the next-period cert
     *        insert. WHERE active_idem_key = "{subjectId}:{courseId}" (matches IssuanceService).
     *        NOTE: active_idem_key lives on learning_CERTIFICATES, NOT learning_assignments.
     *   3.   UPDATE learning_assignments SET active_period_key = NULL WHERE active_period_key =
     *        "{courseId}:{subjectType}:{subjectId}" — frees the assignment UNIQUE slot.
     *   4.   INSERT a fresh assignment row (same period key, status='assigned') — opens the new
     *        recertification period. A second closePeriod() call hits the assignment UNIQUE
     *        constraint on this INSERT → caught → idempotent return (SC3).
     *
     * SC2 INVARIANT — MUST NOT set revoked=true or revoked_at: revoked=true would make the old
     * verify URL read 'withdrawn' (punitive-revoke status) instead of 'expired'. Period-close is
     * a structural lifecycle event, NOT a punitive administrative action.
     *
     * GROUP ASSIGNMENTS: markPassed/Branch-B/getStates operate on subject_type='user' rows only.
     * For group-originated obligations the RecertPeriodCloseJob calls closePeriod per-member
     * (expandGroup → one call per member uid with subjectType='user'), so one member's period
     * state is isolated from all other members.
     *
     * Audit: logComplianceEvent(PERIOD_CLOSED) — facts only (course_id, subject_type,
     * period_key, closed_at); NO PII in context_json.
     */
    public function closePeriod(string $subjectType, string $subjectId, int $courseId): void {
        // The cert's UNIQUE slot value (mirrors IssuanceService::issueIfPassed/issueIfPassedResult)
        $idemKey   = "{$subjectId}:{$courseId}";
        // The assignment's UNIQUE slot value (mirrors createAssignment)
        $periodKey = "{$courseId}:{$subjectType}:{$subjectId}";
        $now       = time();

        // Write 1+2: expire the cert AND free the cert-level UNIQUE slot (single UPDATE).
        // expires_at = now-1 → CertificateVerifyService reads 'expired' (revoked=false,
        // expires_at < now). active_idem_key = NULL → slot freed for next-period cert INSERT.
        $qb = $this->db->getQueryBuilder();
        $qb->update('learning_certificates')
            ->set('expires_at',     $qb->createNamedParameter($now - 1, IQueryBuilder::PARAM_INT))
            ->set('active_idem_key', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->where($qb->expr()->eq(
                'active_idem_key',
                $qb->createNamedParameter($idemKey)
            ));
        $qb->executeStatement();

        // Write 3: free the assignment-level UNIQUE slot so the INSERT below can use the same key.
        $qb2 = $this->db->getQueryBuilder();
        $qb2->update('learning_assignments')
            ->set('active_period_key', $qb2->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->where($qb2->expr()->eq(
                'active_period_key',
                $qb2->createNamedParameter($periodKey)
            ));
        $qb2->executeStatement();

        // Write 4: open a fresh obligation period with the same key so the subject can be
        // recertified. Idempotency: a second closePeriod() call (writes 1-3 are no-ops for
        // NULLed rows) hits the assignment UNIQUE constraint here → caught → clean return.
        $qb3 = $this->db->getQueryBuilder();
        $qb3->insert('learning_assignments')
            ->values([
                'course_id'         => $qb3->createNamedParameter($courseId, IQueryBuilder::PARAM_INT),
                'subject_type'      => $qb3->createNamedParameter($subjectType),
                'subject_id'        => $qb3->createNamedParameter($subjectId),
                'assigned_by'       => $qb3->createNamedParameter('system'),
                'assigned_at'       => $qb3->createNamedParameter($now, IQueryBuilder::PARAM_INT),
                'due_date'          => $qb3->createNamedParameter(null, IQueryBuilder::PARAM_NULL),
                'status'            => $qb3->createNamedParameter('assigned'),
                'active_period_key' => $qb3->createNamedParameter($periodKey),
            ]);
        try {
            $qb3->executeStatement();
        } catch (\OCP\DB\Exception $e) {
            if ($e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                return; // period already re-opened by a concurrent or repeated call — idempotent
            }
            throw $e;
        }

        // Audit: compliance event after all writes succeed.
        // Subject = subjectId (the NC user UID or group GID — not PII when it's a UID).
        $this->auditService->logComplianceEvent(ComplianceEventTypes::PERIOD_CLOSED, $subjectId, [
            'course_id'    => $courseId,
            'subject_type' => $subjectType,
            'period_key'   => $periodKey,
            'closed_at'    => $now,
        ]);
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
