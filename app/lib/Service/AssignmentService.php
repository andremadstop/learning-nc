<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCA\Learning\AppInfo\ConfigDefaults;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\DB\QueryBuilder\IQueryBuilder;
use Psr\Log\LoggerInterface;

/**
 * Manages course assignments to NC users and groups.
 *
 * ASSIGN-04: This service does NOT gate certificate issuance.
 * Self-enrolled learners (no assignment row) continue to receive certs.
 * Cert issuance is solely controlled by PassCriteriaService + IssuanceService.
 */
class AssignmentService {
    public function __construct(
        private readonly IDBConnection   $db,
        private readonly IGroupManager   $groupManager,
        private readonly AuditService    $auditService,
        private readonly IConfig         $config,
        private readonly LoggerInterface $logger,
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
     * Write-set (RECERT-04, 164-04 post-impl-review hardened — one transaction, CAS-gated):
     *   1. (CAS GATE, UPDATE learning_certificates) set active_idem_key = NULL to free the
     *        cert-level UNIQUE slot for the next-period cert insert. WHERE id = $certId AND
     *        active_idem_key = "{subjectId}:{courseId}" — pinned to the SPECIFIC cert being
     *        closed. 0 affected rows = that cert is already closed (repeat/stale call) OR
     *        certId does not match the subject/course → clean no-op return. This makes
     *        closePeriod idempotent AND immune to the stale-call corruption where a reused
     *        period key would expire the NEXT period's fresh cert.
     *        NOTE: active_idem_key lives on learning_CERTIFICATES, NOT learning_assignments.
     *   2.   (conditional expiry clamp) set expires_at = now-1 ONLY where expires_at IS NULL or
     *        still in the future, so the old verify URL reads 'expired' (revoked=false,
     *        expires_at<now per CertificateVerifyService precedence: invalid→withdrawn→
     *        expired→valid). An ALREADY-expired cert keeps its historical expires_at — the
     *        daily job closes certs past the grace window, and overwriting would falsify the
     *        public verify page's expiry date (signed validUntil stays authoritative anyway).
     *   3.   UPDATE learning_assignments SET active_period_key = NULL WHERE active_period_key =
     *        "{courseId}:{subjectType}:{subjectId}" — frees the assignment UNIQUE slot.
     *   4.   INSERT a fresh assignment row (same period key, status='assigned') — opens the new
     *        recertification period. Under the CAS gate this can only run once per cert close;
     *        a UNIQUE violation here means a genuinely concurrent writer → whole transaction
     *        rolls back and the exception propagates (the daily job simply retries next run).
     *
     * ATOMICITY: writes 1–4 + the PERIOD_CLOSED audit append run in ONE transaction. A crash
     * mid-close can therefore never strand a subject in the "no active period, no cert slot,
     * mayIssue=false forever" state — either the period closes fully or not at all.
     * logComplianceEvent opens its own transaction; NC nests it via a savepoint
     * (Connection::setNestTransactionsWithSavepoints(true)), same pattern as IssuanceService.
     *
     * SC2 INVARIANT — MUST NOT set revoked=true or revoked_at: revoked=true would make the old
     * verify URL read 'withdrawn' (punitive-revoke status) instead of 'expired'. Period-close is
     * a structural lifecycle event, NOT a punitive administrative action.
     *
     * GROUP ASSIGNMENTS: markPassed/Branch-B/getStates operate on subject_type='user' rows only.
     * For group-originated obligations the RecertPeriodCloseJob calls closePeriod per-member
     * (expandGroup → one call per member uid with subjectType='user' + that member's certId),
     * so one member's period state is isolated from all other members.
     *
     * Audit: logComplianceEvent(PERIOD_CLOSED) — facts only (course_id, subject_type, closed_at).
     * period_key is deliberately NOT in context_json: it embeds the raw subject uid (NC uids can
     * be email-shaped PII) and context_json is bound into the tamper-evident chain via
     * payload_hash — PII there would survive DSGVO erasure and break the chain invariant.
     *
     * @param int $certId the learning_certificates row whose period is being closed — the
     *                    caller (RecertPeriodCloseJob) selects it via its expiry query.
     */
    public function closePeriod(string $subjectType, string $subjectId, int $courseId, int $certId): void {
        // The cert's UNIQUE slot value (mirrors IssuanceService::issueIfPassed/issueIfPassedResult)
        $idemKey   = "{$subjectId}:{$courseId}";
        // The assignment's UNIQUE slot value (mirrors createAssignment)
        $periodKey = "{$courseId}:{$subjectType}:{$subjectId}";
        $now       = time();

        $this->db->beginTransaction();
        try {
            // Write 1 (CAS gate): free the cert-level UNIQUE slot. Pinned to id = $certId AND
            // active_idem_key = $idemKey so a repeat/stale call (cert already closed, or a
            // NEWER cert now owns the idem slot) matches 0 rows.
            $qb = $this->db->getQueryBuilder();
            $qb->update('learning_certificates')
                ->set('active_idem_key', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
                ->where($qb->expr()->andX(
                    $qb->expr()->eq('id', $qb->createNamedParameter($certId, IQueryBuilder::PARAM_INT)),
                    $qb->expr()->eq('active_idem_key', $qb->createNamedParameter($idemKey))
                ));
            $affected = $qb->executeStatement();

            if ($affected === 0) {
                // Already closed (idempotent repeat) or stale/mismatched certId — touch NOTHING
                // else. Without this gate a stale call would null the fresh period row and
                // expire the next period's cert (data corruption).
                $this->db->commit();
                return;
            }

            // Write 2 (conditional expiry clamp): force 'expired' ONLY for certs that are not
            // yet expired (future expires_at) or never-expiring (NULL). An ALREADY-expired cert
            // keeps its historical expires_at — the daily job closes certs long past expiry,
            // and overwriting would falsify the publicly shown expiry date on the verify page
            // (the signed validUntil stays authoritative either way, status reads 'expired').
            $qbClamp = $this->db->getQueryBuilder();
            $qbClamp->update('learning_certificates')
                ->set('expires_at', $qbClamp->createNamedParameter($now - 1, IQueryBuilder::PARAM_INT))
                ->where($qbClamp->expr()->andX(
                    $qbClamp->expr()->eq('id', $qbClamp->createNamedParameter($certId, IQueryBuilder::PARAM_INT)),
                    $qbClamp->expr()->orX(
                        $qbClamp->expr()->isNull('expires_at'),
                        $qbClamp->expr()->gt('expires_at', $qbClamp->createNamedParameter($now - 1, IQueryBuilder::PARAM_INT))
                    )
                ));
            $qbClamp->executeStatement();

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
            // recertified. The CAS gate above guarantees this runs at most once per cert close;
            // a UNIQUE violation = concurrent writer → rollback + propagate (job retries).
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
            $qb3->executeStatement();

            // Audit INSIDE the transaction (savepoint-nested): a close without its PERIOD_CLOSED
            // event can never commit. Context is facts-only — NO period_key (contains raw uid).
            $this->auditService->logComplianceEvent(ComplianceEventTypes::PERIOD_CLOSED, $subjectId, [
                'course_id'    => $courseId,
                'subject_type' => $subjectType,
                'closed_at'    => $now,
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e; // partial close must never survive — fail loud, job retries next run
        }
    }

    /**
     * Close every recertification period whose cert expired past the grace window (RECERT-04).
     *
     * Called daily by RecertPeriodCloseJob. Source of truth is learning_CERTIFICATES, not the
     * assignment table: an "open period with an expired cert" is exactly a cert row that still
     * OWNS its idem slot (active_idem_key IS NOT NULL) and whose expires_at is past the cutoff.
     * user_id/course_id are read directly from the cert row — the idem key is NEVER parsed
     * (NC uids may contain ':').
     *
     * cutoff = $now - recert_grace_days * 86400 (IConfig app value, default
     * ConfigDefaults::RECERT_GRACE_DAYS_DEFAULT). Plain second arithmetic is fine here — the
     * grace window is a coarse operational buffer, not a calendar-exact validity (research note).
     *
     * Idempotency/self-cleaning: closePeriod() NULLs active_idem_key (CAS write 1), so a closed
     * cert drops out of this query on the next run — no re-selection, no double close.
     *
     * Per-row isolation: one failing close (rolled back + logged) must not block the remaining
     * periods — a poisoned row would otherwise stall the whole compliance loop forever. Failed
     * rows are retried on the next daily run.
     *
     * @return int number of periods closed this run
     */
    public function closeExpiredPeriods(int $now): int {
        $graceDays = (int)$this->config->getAppValue(
            'learning', 'recert_grace_days', ConfigDefaults::RECERT_GRACE_DAYS_DEFAULT
        );
        $cutoff = $now - $graceDays * 86400;

        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'user_id', 'course_id')
           ->from('learning_certificates')
           ->where($qb->expr()->isNotNull('active_idem_key'))
           ->andWhere($qb->expr()->isNotNull('expires_at'))
           ->andWhere($qb->expr()->lt('expires_at', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $rows = [];
        while (($row = $result->fetch()) !== false) {
            if (!is_array($row)) {
                break;
            }
            $rows[] = $row;
        }
        $result->closeCursor();

        $closed = 0;
        foreach ($rows as $row) {
            try {
                // Certs are per-user rows → subject_type is always 'user' here. Group-originated
                // obligations resolve to per-member user periods exactly this way (see closePeriod
                // GROUP ASSIGNMENTS note).
                $this->closePeriod('user', (string)$row['user_id'], (int)$row['course_id'], (int)$row['id']);
                $closed++;
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'closeExpiredPeriods: closing cert {cert} failed, retrying next run: {msg}',
                    ['cert' => (int)$row['id'], 'msg' => $e->getMessage()]
                );
            }
        }
        return $closed;
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
