<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\AppInfo\ConfigDefaults;
use OCA\Learning\Db\Certificate;
use OCA\Learning\Db\CertificateMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CourseVideoMapper;
use OCA\Learning\Db\VideoProgressMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * DSGVO-safe, owner-scoped compliance report for a certifying course (156-01, REPORT-01..04).
 *
 * One shared read path feeds BOTH the on-screen JSON table and the CSV download, so the two are
 * always the same filtered set. For each issued certificate it decodes the stored, already-signed
 * VC-JWT payload to recover the frozen recipient display name and the achieved score, then projects
 * to a strict DTO (5 display fields plus the expired flag). The account identifier is NEVER
 * read into any output field, and an email-shaped frozen name (defence-in-depth against a
 * hand-edited / legacy row) falls back to a neutral pseudonym — so no plaintext email can
 * reach a report surface.
 */
class CertificateReportService {

    /**
     * Neutral DSGVO fallback recipient name. Mirrors IssuanceService::FALLBACK_RECIPIENT so the
     * report and the signed credential agree on the pseudonym (a backend constant, not a t() key —
     * locale-independent).
     */
    private const FALLBACK_RECIPIENT = 'Teilnehmer:in';

    private const SECONDS_PER_DAY = 86400;

    /**
     * Coverage statuses (Lückencheck B2), one per enrolled learner. Also the allow-list of the
     * status filter; anything else is ignored.
     */
    public const COVERAGE_STATUSES = ['current', 'renewal_open', 'expired', 'overdue', 'never_passed'];

    private CourseService $courseService;
    private CertificateMapper $certificateMapper;
    private ITimeFactory $timeFactory;
    private RoleService $roleService;
    private AssignmentService $assignmentService;
    private ReminderService $reminderService;
    private IGroupManager $groupManager;
    private PassCriteriaService $passCriteriaService;
    private CourseMemberMapper $courseMemberMapper;
    private IUserManager $userManager;
    private ?IConfig $config;
    private ?CourseVideoMapper $videoMapper;
    private ?VideoProgressMapper $progressMapper;

    public function __construct(
        CourseService $courseService,
        CertificateMapper $certificateMapper,
        ITimeFactory $timeFactory,
        RoleService $roleService,
        AssignmentService $assignmentService,
        ReminderService $reminderService,
        IGroupManager $groupManager,
        PassCriteriaService $passCriteriaService,
        CourseMemberMapper $courseMemberMapper,
        IUserManager $userManager,
        ?IConfig $config = null,
        ?CourseVideoMapper $videoMapper = null,
        ?VideoProgressMapper $progressMapper = null
    ) {
        $this->courseService = $courseService;
        $this->certificateMapper = $certificateMapper;
        $this->timeFactory = $timeFactory;
        $this->roleService = $roleService;
        $this->assignmentService = $assignmentService;
        $this->reminderService = $reminderService;
        $this->groupManager = $groupManager;
        $this->passCriteriaService = $passCriteriaService;
        $this->courseMemberMapper = $courseMemberMapper;
        $this->userManager = $userManager;
        $this->config = $config;
        $this->videoMapper = $videoMapper;
        $this->progressMapper = $progressMapper;
    }

    /**
     * Team-lead-scoped compliance report for a group within a course.
     *
     * SECURITY: assertTeamLeadForGroup is the FIRST statement — before any member read,
     * cert read, or state read. Denial tests lock this with never()-expectations on all
     * data sources (RBAC-02 / assert-first invariant).
     *
     * Row shape: {member_ref, display_name, status, passed_at, expires_at, due_date, expiring}
     * member_ref is an opaque handle (never a raw uid/email); the reminder POST echoes it back.
     * Status: 'passed' | 'expired' | 'overdue' | 'missing'
     * Display names (both cert-derived and IGroupManager-derived) are guarded by looksLikeEmail.
     *
     * Classification uses ALL non-revoked certificates of the course; per member the newest one
     * (issued_at, then id) counts. 'expired' = that certificate's expires_at lies in the past.
     * The expiry window never filters the classification (audit 2026-11: with the window applied
     * to the certificate read, a member holding a never-expiring or +365d certificate was shown as
     * 'missing'); it only sets the per-row 'expiring' flag for the "expiring certificates" list.
     *
     * @param int      $courseId     the certifying course
     * @param string   $groupId      the NC group the lead is authorised to view
     * @param string   $leadUserId   the requesting team lead
     * @param int|null $expiringDays optional expiry window (days) for the 'expiring' flag; null = no row flagged
     * @return array<int, array{member_ref: string, display_name: string, status: string, passed_at: int|null, expires_at: int|null, due_date: int|null, expiring: bool}>
     * @throws ForbiddenException if groupId is empty, or caller holds no oversight for the group/course
     */
    public function getGroupReport(int $courseId, string $groupId, string $leadUserId, ?int $expiringDays): array {
        // SECURITY GATE — MUST be first; all data reads are after this line.
        $this->assertTeamLeadForGroup($courseId, $groupId, $leadUserId);

        $now = $this->timeFactory->getTime();
        $expiresBefore = $expiringDays !== null
            ? $now + $expiringDays * self::SECONDS_PER_DAY
            : null;

        $members = $this->assignmentService->expandGroup($groupId);
        // No expiry cutoff here: the status needs every non-revoked certificate.
        $certs   = $this->certificateMapper->findByCourseIdForUsers($courseId, $members);
        $states  = $this->assignmentService->getStatesForCourseAndUsers($courseId, $members);

        $certsByUserId = $this->newestByUser($certs);

        // Build uid→IUser map from the group for non-cert member display names (one call, lazy)
        $groupUsers = [];
        $group = $this->groupManager->get($groupId);
        if ($group !== null) {
            foreach ($group->getUsers() as $user) {
                $groupUsers[$user->getUID()] = $user;
            }
        }

        $rows = [];
        foreach ($members as $memberUid) {
            if (isset($certsByUserId[$memberUid])) {
                // Cert was earned (revoked already filtered in mapper); valid unless its expiry has passed.
                $cert = $certsByUserId[$memberUid];
                [$frozenName, ] = $this->decodePayload($cert->getCredentialJson());
                $displayName = $this->safeDisplayName($frozenName, $memberUid);
                $expiresAt = $cert->getExpiresAt();
                $status = $this->baseStatus($cert, null, $now);
                $isExpired = $status === 'expired';
                $rows[] = [
                    'member_ref'   => $this->memberRef($memberUid),
                    'display_name' => $displayName,
                    'status'       => $status,
                    'passed_at'    => $cert->getIssuedAt(),
                    'expires_at'   => $expiresAt,
                    'due_date'     => $states[$memberUid]['due_date'] ?? null,
                    'expiring'     => !$isExpired && $expiresAt !== null
                        && $expiresBefore !== null && $expiresAt <= $expiresBefore,
                ];
            } else {
                // No cert — overdue when due_date is in the past and not already passed
                $state     = $states[$memberUid] ?? null;
                $status    = $this->baseStatus(null, $state, $now);

                $user       = $groupUsers[$memberUid] ?? null;
                $rawName    = $user !== null ? $user->getDisplayName() : null;
                $displayName = $this->safeDisplayName($rawName, $memberUid);

                $rows[] = [
                    'member_ref'   => $this->memberRef($memberUid),
                    'display_name' => $displayName,
                    'status'       => $status,
                    'passed_at'    => null,
                    'expires_at'   => null,
                    'due_date'     => $state['due_date'] ?? null,
                    'expiring'     => false,
                ];
            }
        }
        return $rows;
    }

    /**
     * Newest certificate per user (re-certification leaves several rows). Mapper results are
     * unordered across chunks, so pick explicitly: higher issued_at wins, then higher id. Shared by
     * the group report, the coverage report and the expiry list.
     *
     * @param Certificate[] $certs
     * @return array<string, Certificate> keyed by user id
     */
    private function newestByUser(array $certs): array {
        $newest = [];
        foreach ($certs as $cert) {
            $uid = (string)$cert->getUserId();
            $current = $newest[$uid] ?? null;
            if ($current === null
                || (int)$cert->getIssuedAt() > (int)$current->getIssuedAt()
                || ((int)$cert->getIssuedAt() === (int)$current->getIssuedAt() && (int)$cert->getId() > (int)$current->getId())) {
                $newest[$uid] = $cert;
            }
        }
        return $newest;
    }

    /**
     * The member status of the group report: 'passed' | 'expired' | 'overdue' | 'missing'.
     * $cert is the member's newest non-revoked certificate (or null), $state the open assignment
     * period (or null). The coverage report refines exactly this result (coverageStatus()).
     *
     * @param array{status: string, due_date: int|null}|null $state
     */
    private function baseStatus(?Certificate $cert, ?array $state, int $now): string {
        if ($cert !== null) {
            $expiresAt = $cert->getExpiresAt();
            return $expiresAt !== null && $expiresAt < $now ? 'expired' : 'passed';
        }
        $isOverdue = $state !== null
            && $state['due_date'] !== null
            && $state['due_date'] < $now
            && $state['status'] !== 'passed';
        return $isOverdue ? 'overdue' : 'missing';
    }

    /**
     * Coverage status (Lückencheck B2): the group report's status with the renewal and grace
     * windows the rest of the recertification uses (ConfigDefaults, the single parsers).
     *   current      — valid certificate, renewal not open yet (or never expires)
     *   renewal_open — valid, and renewal is open (from the first reminder, renewalOpensAt())
     *   expired      — expired, still inside recert_grace_days
     *   overdue      — expired and past the grace window, or no certificate and the assignment's
     *                  due date has passed
     *   never_passed — no (non-revoked) certificate and nothing overdue
     *
     * @param array{status: string, due_date: int|null}|null $state
     */
    private function coverageStatus(?Certificate $cert, ?array $state, int $now): string {
        $base = $this->baseStatus($cert, $state, $now);
        if ($this->config === null) {
            throw new \LogicException('CertificateReportService needs IConfig for the coverage status');
        }
        if ($cert === null) {
            return $base === 'overdue' ? 'overdue' : 'never_passed';
        }
        $expiresAt = $cert->getExpiresAt();
        if ($expiresAt === null) {
            return 'current';
        }
        if ($base === 'expired') {
            return $now > (int)$expiresAt + ConfigDefaults::graceDays($this->config) * self::SECONDS_PER_DAY
                ? 'overdue'
                : 'expired';
        }
        return $now >= ConfigDefaults::renewalOpensAt($this->config, (int)$expiresAt) ? 'renewal_open' : 'current';
    }

    /**
     * Coverage report (Lückencheck B2): EVERY learner enrolled in the course (role student; the
     * owner and co-instructors are left out), with or without a certificate. The issued-certificates
     * report cannot show who never passed; this list can. One read path for the table and the CSV.
     *
     * Same output rules as the other report rows: display name only (frozen certificate name, else
     * the account's display name, both guarded), an opaque member_ref, never the account id or an
     * email.
     *
     * @param string|null $status one of COVERAGE_STATUSES to keep only those rows; anything else = all
     * GitHub #35 (B4): required_content_confirmed / required_content_total = how many of the course's
     * required contents (documents confirmed as read, videos watched) the learner has completed.
     *
     * @return array{rows: list<array{member_ref: string, display_name: string, status: string, last_certificate_at: int|null, expires_at: int|null, required_pools_passed: int, required_pools_total: int, required_content_confirmed: int, required_content_total: int}>}
     * @throws \OCP\AppFramework\Db\DoesNotExistException course not found (→ 404)
     * @throws ForbiddenException                         caller is not an instructor of the course (→ 403)
     */
    public function getCoverageReport(int $courseId, string $userId, ?string $status): array {
        // Gate BEFORE any read — owner-scoped, IDOR-safe.
        $course = $this->courseService->assertInstructorOfCourse($courseId, $userId);

        $ownerId = (string)$course->getInstructorId();
        $learnerIds = [];
        foreach ($this->courseMemberMapper->findByCourse($courseId) as $member) {
            $uid = (string)$member->getUserId();
            if ($member->getRole() === 'student' && $uid !== '' && $uid !== $ownerId && !in_array($uid, $learnerIds, true)) {
                $learnerIds[] = $uid;
            }
        }
        $filter = in_array($status, self::COVERAGE_STATUSES, true) ? $status : null;
        if ($learnerIds === []) {
            return ['rows' => []];
        }

        $now = $this->timeFactory->getTime();
        $certsByUserId = $this->newestByUser($this->certificateMapper->findByCourseIdForUsers($courseId, $learnerIds));
        $states = $this->assignmentService->getStatesForCourseAndUsers($courseId, $learnerIds);

        // Status first, then the pool progress only for the rows that pass the filter:
        // requiredPoolProgress() reads per learner, so a filtered view must not pay for everyone.
        $statusByUserId = [];
        foreach ($learnerIds as $uid) {
            $rowStatus = $this->coverageStatus($certsByUserId[$uid] ?? null, $states[$uid] ?? null, $now);
            if ($filter === null || $rowStatus === $filter) {
                $statusByUserId[$uid] = $rowStatus;
            }
        }
        if ($statusByUserId === []) {
            return ['rows' => []];
        }
        $keptIds = array_map('strval', array_keys($statusByUserId));
        $pools = $this->passCriteriaService->requiredPoolProgress($courseId, $keptIds);
        [$contentTotal, $contentDone] = $this->requiredContentProgress($courseId, $keptIds);

        $rows = [];
        foreach ($statusByUserId as $uid => $rowStatus) {
            $uid = (string)$uid;
            $cert = $certsByUserId[$uid] ?? null;
            if ($cert !== null) {
                [$frozenName, ] = $this->decodePayload($cert->getCredentialJson());
                $displayName = $this->safeDisplayName($frozenName, $uid);
            } else {
                $user = $this->userManager->get($uid);
                $displayName = $this->safeDisplayName($user !== null ? $user->getDisplayName() : null, $uid);
            }
            $rows[] = [
                'member_ref' => $this->memberRef($uid),
                'display_name' => $displayName,
                'status' => $rowStatus,
                'last_certificate_at' => $cert !== null ? (int)$cert->getIssuedAt() : null,
                'expires_at' => $cert !== null && $cert->getExpiresAt() !== null ? (int)$cert->getExpiresAt() : null,
                'required_pools_passed' => (int)($pools[$uid]['passed'] ?? 0),
                'required_pools_total' => (int)($pools[$uid]['total'] ?? 0),
                'required_content_confirmed' => min($contentTotal, (int)($contentDone[$uid] ?? 0)),
                'required_content_total' => $contentTotal,
            ];
        }
        usort($rows, static fn(array $a, array $b): int => strnatcasecmp($a['display_name'], $b['display_name']));
        return ['rows' => $rows];
    }

    /**
     * GitHub #35 (B4): number of required contents of the course and, per learner, how many of them
     * are completed. Read-only, one query for the registry and one per 999 learners.
     *
     * @param list<string> $userIds
     * @return array{0: int, 1: array<string, int>}
     */
    private function requiredContentProgress(int $courseId, array $userIds): array {
        if ($this->videoMapper === null || $this->progressMapper === null) {
            return [0, []];
        }
        $contentIds = [];
        foreach ($this->videoMapper->findByCourse($courseId) as $video) {
            $contentIds[] = (int)$video->getId();
        }
        if ($contentIds === []) {
            return [0, []];
        }
        return [count($contentIds), $this->progressMapper->countCompletedByUsers($userIds, $contentIds)];
    }

    /**
     * Assert that $leadUserId holds an oversight row for ($courseId, $groupId).
     *
     * Fails CLOSED on empty groupId — never widens to all-groups.
     * Calls RoleService exactly once on the non-empty path; zero times on the empty path.
     *
     * @throws ForbiddenException when groupId is empty or no oversight row exists
     */
    private function assertTeamLeadForGroup(int $courseId, string $groupId, string $leadUserId): void {
        if ($groupId === '' || !$this->roleService->isTeamLeadForGroup($courseId, $leadUserId, $groupId)) {
            throw new ForbiddenException(
                "Not authorised for group '$groupId' in course $courseId"
            );
        }
    }

    /**
     * Dispatch a mandatory compliance reminder to $targetUserId for $courseId.
     *
     * SECURITY: two independent IDOR guards run before any notification is dispatched.
     * (1) assertTeamLeadForGroup — verifies $leadUserId holds an oversight row for
     *     ($courseId, $groupId). Fails closed on empty groupId.
     * (2) target ∈ expandGroup — verifies $targetUserId is actually a member of that group.
     *     Do NOT trust the report GET gated this; the remind POST is a separate attack surface.
     *
     * A foreign target or a group the lead does not oversee both map to ForbiddenException
     * with a generic message (avoids a membership-oracle response body).
     *
     * @throws ForbiddenException when caller has no oversight or target is not in the group
     */
    public function remindMember(int $courseId, string $groupId, string $memberRef, string $leadUserId): void {
        // SECURITY GATE 1 — asserts lead role; also rejects empty groupId (fail-closed).
        $this->assertTeamLeadForGroup($courseId, $groupId, $leadUserId);

        // SECURITY GATE 2 — resolve the opaque member_ref ONLY against members of the group the
        // lead oversees. The client never sends a raw uid (which may be an email); it echoes the
        // member_ref from the report. Resolution is confined to expandGroup, so a ref that matches
        // no member fails closed. hash_equals for timing-safe comparison.
        $targetUserId = null;
        foreach ($this->assignmentService->expandGroup($groupId) as $memberUid) {
            if (hash_equals($this->memberRef($memberUid), $memberRef)) {
                $targetUserId = $memberUid;
                break;
            }
        }
        if ($targetUserId === null) {
            // Generic 403 on denial to avoid leaking membership information.
            throw new ForbiddenException(
                "Not authorised for group '$groupId' in course $courseId"
            );
        }

        // Both guards passed — dispatch mandatory compliance reminder (no opt-out gate).
        $this->reminderService->sendComplianceReminder($targetUserId, $courseId, [
            'lead_user_id' => $leadUserId,
        ]);
    }

    /**
     * Opaque, deterministic handle for a group member used in the report DTO and echoed back by
     * the reminder POST. Replaces the raw uid so a member whose NC uid IS an email
     * (e.g. "alice@example.com") can never leak a plaintext email into the report — the same
     * class of leak the display_name looksLikeEmail guard prevents.
     *
     * A keyless domain-separated hash is deliberate: the ref only ever reaches the authorised
     * team lead, who is already entitled to know the group's membership, so no server secret is
     * needed for oracle-resistance; the distinct label keeps it uncorrelated with the audit chain.
     * remindMember() resolves it back to a uid ONLY among expandGroup() members, so the ref is a
     * capability scoped to a group the caller provably leads — not a global identifier.
     */
    private function memberRef(string $uid): string {
        return substr(hash('sha256', 'learning:group_member_ref:v1:' . $uid), 0, 24);
    }

    /**
     * A human display name that never leaks a raw account identifier. Falls back to the neutral
     * recipient label when the candidate is missing, email-shaped, or exactly equals the uid —
     * the last case matters because NC's getDisplayName() returns the uid itself when no display
     * name is set (common for bulk-provisioned compliance accounts), and a frozen cert name may
     * likewise be the uid. Applied to both the passed (cert) and missing/overdue branches.
     */
    private function safeDisplayName(?string $candidate, string $memberUid): string {
        // Normalise first so a padded/cased variant of the uid (e.g. "jane.doe ") can't slip the
        // equality check. Case-insensitive compare against the uid. We deliberately do NOT block
        // mere containment ("Jane Doe (jane.doe)"): that is an admin-authored display name, not the
        // system leaking an identifier, and containment-blocking would neutralise legitimate names.
        $trimmed = $candidate === null ? '' : trim($candidate);
        if ($trimmed === ''
            || strcasecmp($trimmed, $memberUid) === 0
            || $this->looksLikeEmail($trimmed)) {
            return self::FALLBACK_RECIPIENT;
        }
        return $trimmed;
    }

    /**
     * All (course_id, group_id) scopes this lead is authorised to view.
     *
     * @return list<array{course_id: int, group_id: string}>
     */
    public function myTeamLeadScopes(string $leadUserId): array {
        return $this->roleService->getTeamLeadGroups($leadUserId);
    }

    /**
     * Build the owner-scoped, server-side-filtered report for one course.
     *
     * Authorization runs FIRST (per-course owner gate) before any certificate is read; a foreign
     * instructor gets a ForbiddenException and never sees a row. The expiry window is converted here
     * (this class owns the clock) from a day-count into an absolute unix cutoff that the time-free
     * mapper applies.
     *
     * @param int      $courseId     the certifying course
     * @param string   $userId       the requesting instructor
     * @param int|null $from         issued_at >= from (inclusive)
     * @param int|null $to           issued_at <= to (inclusive)
     * @param int|null $expiringDays only certificates expiring within this many days (never-expiring
     *                               excluded); null = no expiry filter
     * @return array{rows: list<array{display_name: string, passed_at: int, score: string, expires_at: int|null, verification_id: string, expired: bool}>}
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException course not found (→ 404)
     * @throws ForbiddenException                         caller is not an instructor of the course (→ 403)
     */
    public function getCourseReport(int $courseId, string $userId, ?int $from, ?int $to, ?int $expiringDays): array {
        $certs = $this->findReportCertificates($courseId, $userId, $from, $to, $expiringDays);

        // The expiry window includes certificates that already expired (they still need action);
        // each row says which ones, like the group report's 'expired' status (audit #222).
        $now = $this->timeFactory->getTime();
        $rows = [];
        foreach ($certs as $cert) {
            $rows[] = $this->projectRow($cert, $now);
        }

        return ['rows' => $rows];
    }

    /**
     * The certificates behind the report table, as entities: the same gate and the same filters as
     * getCourseReport(), which projects exactly this list. The "Download all (ZIP)" export
     * (GitHub #42) packs this list, so the ZIP always holds the rows the instructor sees.
     *
     * @return Certificate[]
     * @throws \OCP\AppFramework\Db\DoesNotExistException course not found (→ 404)
     * @throws ForbiddenException                         caller is not an instructor of the course (→ 403)
     */
    public function findReportCertificates(int $courseId, string $userId, ?int $from, ?int $to, ?int $expiringDays): array {
        // Gate BEFORE any read — owner-scoped, IDOR-safe.
        $this->courseService->assertInstructorOfCourse($courseId, $userId);

        $expiresBefore = $expiringDays !== null
            ? $this->timeFactory->getTime() + $expiringDays * self::SECONDS_PER_DAY
            : null;

        $certs = $this->certificateMapper->findByCourseId($courseId, $from, $to, $expiresBefore);
        if ($expiresBefore !== null) {
            $certs = $this->withoutSuperseded($courseId, $certs);
        }
        return $certs;
    }

    /**
     * One certificate of THIS course for its instructor (GitHub #42: View / Download in the report).
     *
     * The instructor gate runs first, so a caller who does not teach the course learns nothing about
     * the id (403 before any certificate read). A certificate of another course, an unknown id and a
     * DSGVO-erased tombstone (credential scrubbed, no subject) all answer "not found". A revoked
     * certificate stays retrievable — the caller gets its revoked flag and must label it.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException course or certificate not found (→ 404)
     * @throws ForbiddenException                         caller is not an instructor of the course (→ 403)
     */
    public function getCourseCertificate(int $courseId, string $verificationId, string $userId): Certificate {
        $this->courseService->assertInstructorOfCourse($courseId, $userId);

        // Verification ids are RFC 4122 UUIDv4 (IssuanceService::uuidv4); checked after the gate so
        // a malformed id tells a stranger nothing either, and before any certificate read.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $verificationId) !== 1) {
            throw new \OCP\AppFramework\Db\DoesNotExistException('Malformed verification id');
        }
        $cert = $this->certificateMapper->findByVerificationId($verificationId);
        if ((int)$cert->getCourseId() !== $courseId || $cert->getAnonymizedAt() !== null) {
            throw new \OCP\AppFramework\Db\DoesNotExistException('Certificate not in this course');
        }
        return $cert;
    }

    /**
     * The report row for one certificate (display name, dates, score, expired flag) — the same
     * projection the table uses, for labelling an exported copy.
     *
     * @return array{display_name: string, passed_at: int, score: string, expires_at: int|null, verification_id: string, expired: bool}
     */
    public function describeCertificate(Certificate $cert): array {
        return $this->projectRow($cert, $this->timeFactory->getTime());
    }

    /**
     * The expiry list names who still has to act. A certificate that was renewed (GitHub #35:
     * renewal opens with the first reminder, the old certificate stays valid until its own
     * expiry) or replaced after a recertification period is superseded: it released its slot
     * and a newer certificate of the same learner exists. Listing it as "expiring" would name a
     * learner who already renewed, so it is left out here; the full history (no expiry filter)
     * still lists every certificate.
     *
     * @param Certificate[] $certs
     * @return Certificate[]
     */
    private function withoutSuperseded(int $courseId, array $certs): array {
        $released = array_filter($certs, static fn(Certificate $c): bool => $c->getActiveIdemKey() === null);
        if ($released === []) {
            return $certs;
        }
        // Newest certificate per learner over the whole course, not just the filtered window:
        // the successor of an expiring certificate expires later and is outside the filter.
        $newest = $this->newestByUser($this->certificateMapper->findByCourseId($courseId, null, null, null));
        return array_values(array_filter($certs, static function (Certificate $c) use ($newest): bool {
            if ($c->getActiveIdemKey() !== null) {
                return true;
            }
            $latest = $newest[(string)$c->getUserId()] ?? null;
            return $latest === null || (int)$latest->getId() === (int)$c->getId();
        }));
    }

    /**
     * Course members who passed but hold no certificate (GitHub #35): issuance failed for them, or
     * has not run yet. The instructor sees the same "passed, but the certificate could not be
     * issued" state the learner sees. Read-only — see PassCriteriaService::findPassedWithoutCertificate().
     *
     * Same output rules as the report rows: display name only, never the account id or an email.
     *
     * @return list<array{display_name: string}>
     * @throws \OCP\AppFramework\Db\DoesNotExistException course not found (→ 404)
     * @throws ForbiddenException                         caller is not an instructor of the course (→ 403)
     */
    public function getPassedWithoutCertificate(int $courseId, string $userId): array {
        // Gate BEFORE any read — owner-scoped, IDOR-safe.
        $this->courseService->assertInstructorOfCourse($courseId, $userId);

        $memberIds = array_map(
            static fn($member): string => (string)$member->getUserId(),
            $this->courseMemberMapper->findByCourse($courseId)
        );
        $rows = [];
        foreach ($this->passCriteriaService->findPassedWithoutCertificate($courseId, $memberIds) as $memberUid) {
            $user = $this->userManager->get($memberUid);
            $rows[] = ['display_name' => $this->safeDisplayName($user !== null ? $user->getDisplayName() : null, $memberUid)];
        }
        return $rows;
    }

    /**
     * Project one certificate to the strict DTO: five display fields plus the `expired` flag. The frozen name and score are recovered from
     * the signed VC-JWT payload; a malformed/undecodable credential degrades gracefully to the neutral
     * pseudonym + empty score and never aborts the surrounding report.
     *
     * @return array{display_name: string, passed_at: int, score: string, expires_at: int|null, verification_id: string, expired: bool}
     */
    private function projectRow(Certificate $cert, int $now): array {
        [$frozenName, $score] = $this->decodePayload($cert->getCredentialJson());

        // Same guard as the group report: a frozen name equal to (or empty/email-shaped relative to)
        // the cert's account uid must fall back to the neutral pseudonym — never leak the raw uid.
        $displayName = $this->safeDisplayName($frozenName, $cert->getUserId());

        return [
            'display_name' => $displayName,
            'passed_at' => $cert->getIssuedAt(),
            'score' => $score,
            'expires_at' => $cert->getExpiresAt(),
            'verification_id' => $cert->getVerificationId(),
            'expired' => $cert->getExpiresAt() !== null && (int)$cert->getExpiresAt() < $now,
        ];
    }

    /**
     * Decode the VC-JWT payload (segment 2) to [frozen name, score]. Mirrors the issuer's base64url
     * encoding (SigningService) with strict base64 decode. Any failure → [null, ''] so the row still
     * renders with the neutral fallback.
     *
     * @return array{0: string|null, 1: string}
     */
    private function decodePayload(string $jwt): array {
        try {
            $parts = explode('.', $jwt);
            if (count($parts) < 2) {
                return [null, ''];
            }
            $decoded = base64_decode(strtr($parts[1], '-_', '+/'), true);
            if ($decoded === false) {
                return [null, ''];
            }
            $payload = json_decode($decoded, true);
            if (!is_array($payload)) {
                return [null, ''];
            }

            $name = $payload['credentialSubject']['name'] ?? null;
            $frozenName = is_string($name) ? $name : null;

            $resultDescription = $payload['credentialSubject']['result'][0]['resultDescription'] ?? '';
            $score = '';
            if (is_string($resultDescription) && preg_match('/score:([0-9.]+)/', $resultDescription, $m) === 1) {
                $score = $m[1];
            }

            return [$frozenName, $score];
        } catch (\Throwable $e) {
            return [null, ''];
        }
    }

    /**
     * True when the candidate CONTAINS an email-shaped token anywhere (mirrors IssuanceService): a
     * defence-in-depth re-screen of the frozen name so a legacy/hand-edited credential can't leak an
     * address even though the name was screened at issuance.
     */
    private function looksLikeEmail(string $candidate): bool {
        return preg_match('/[^@\s]+@[^@\s]+\.[^@\s]+/', trim($candidate)) === 1;
    }
}
