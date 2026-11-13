<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\Certificate;
use OCA\Learning\Db\CertificateMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * DSGVO-safe, owner-scoped compliance report for a certifying course (156-01, REPORT-01..04).
 *
 * One shared read path feeds BOTH the on-screen JSON table and the CSV download, so the two are
 * always the same filtered set. For each issued certificate it decodes the stored, already-signed
 * VC-JWT payload to recover the frozen recipient display name and the achieved score, then projects
 * to a strict 5-field DTO. The account identifier is NEVER read into any output field, and an
 * email-shaped frozen name (defence-in-depth against a hand-edited / legacy row) falls back to a
 * neutral pseudonym — so no plaintext email can reach a report surface.
 */
class CertificateReportService {

    /**
     * Neutral DSGVO fallback recipient name. Mirrors IssuanceService::FALLBACK_RECIPIENT so the
     * report and the signed credential agree on the pseudonym (a backend constant, not a t() key —
     * locale-independent).
     */
    private const FALLBACK_RECIPIENT = 'Teilnehmer:in';

    private const SECONDS_PER_DAY = 86400;

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
        IUserManager $userManager
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
        $certs   = $this->certificateMapper->findByCourseIdForUsers($courseId, $members, null);
        $states  = $this->assignmentService->getStatesForCourseAndUsers($courseId, $members);

        // Newest certificate per member (re-certification leaves several rows). The mapper result is
        // unordered across chunks, so pick explicitly: higher issued_at wins, then higher id.
        $certsByUserId = [];
        foreach ($certs as $cert) {
            $uid = $cert->getUserId();
            $current = $certsByUserId[$uid] ?? null;
            if ($current === null
                || $cert->getIssuedAt() > $current->getIssuedAt()
                || ($cert->getIssuedAt() === $current->getIssuedAt() && (int)$cert->getId() > (int)$current->getId())) {
                $certsByUserId[$uid] = $cert;
            }
        }

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
                $isExpired = $expiresAt !== null && $expiresAt < $now;
                $rows[] = [
                    'member_ref'   => $this->memberRef($memberUid),
                    'display_name' => $displayName,
                    'status'       => $isExpired ? 'expired' : 'passed',
                    'passed_at'    => $cert->getIssuedAt(),
                    'expires_at'   => $expiresAt,
                    'due_date'     => $states[$memberUid]['due_date'] ?? null,
                    'expiring'     => !$isExpired && $expiresAt !== null
                        && $expiresBefore !== null && $expiresAt <= $expiresBefore,
                ];
            } else {
                // No cert — overdue when due_date is in the past and not already passed
                $state     = $states[$memberUid] ?? null;
                $isOverdue = $state !== null
                    && $state['due_date'] !== null
                    && $state['due_date'] < $now
                    && $state['status'] !== 'passed';
                $status    = $isOverdue ? 'overdue' : 'missing';

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
     * @return array{rows: list<array{display_name: string, passed_at: int, score: string, expires_at: int|null, verification_id: string}>}
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException course not found (→ 404)
     * @throws ForbiddenException                         caller is not an instructor of the course (→ 403)
     */
    public function getCourseReport(int $courseId, string $userId, ?int $from, ?int $to, ?int $expiringDays): array {
        // Gate BEFORE any read — owner-scoped, IDOR-safe.
        $this->courseService->assertInstructorOfCourse($courseId, $userId);

        $expiresBefore = $expiringDays !== null
            ? $this->timeFactory->getTime() + $expiringDays * self::SECONDS_PER_DAY
            : null;

        $certs = $this->certificateMapper->findByCourseId($courseId, $from, $to, $expiresBefore);

        $rows = [];
        foreach ($certs as $cert) {
            $rows[] = $this->projectRow($cert);
        }

        return ['rows' => $rows];
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
     * Project one certificate to the strict 5-field DTO. The frozen name and score are recovered from
     * the signed VC-JWT payload; a malformed/undecodable credential degrades gracefully to the neutral
     * pseudonym + empty score and never aborts the surrounding report.
     *
     * @return array{display_name: string, passed_at: int, score: string, expires_at: int|null, verification_id: string}
     */
    private function projectRow(Certificate $cert): array {
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
