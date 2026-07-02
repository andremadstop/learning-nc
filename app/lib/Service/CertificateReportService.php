<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\Certificate;
use OCA\Learning\Db\CertificateMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;

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

    public function __construct(
        CourseService $courseService,
        CertificateMapper $certificateMapper,
        ITimeFactory $timeFactory,
        RoleService $roleService,
        AssignmentService $assignmentService,
        ReminderService $reminderService,
        IGroupManager $groupManager
    ) {
        $this->courseService = $courseService;
        $this->certificateMapper = $certificateMapper;
        $this->timeFactory = $timeFactory;
        $this->roleService = $roleService;
        $this->assignmentService = $assignmentService;
        $this->reminderService = $reminderService;
        $this->groupManager = $groupManager;
    }

    /**
     * Team-lead-scoped compliance report for a group within a course.
     *
     * SECURITY: assertTeamLeadForGroup is the FIRST statement — before any member read,
     * cert read, or state read. Denial tests lock this with never()-expectations on all
     * data sources (RBAC-02 / assert-first invariant).
     *
     * Row shape: {user_id, display_name, status, passed_at, expires_at, due_date}
     * Status: 'passed' | 'overdue' | 'missing'
     * Display names (both cert-derived and IGroupManager-derived) are guarded by looksLikeEmail.
     *
     * @param int      $courseId     the certifying course
     * @param string   $groupId      the NC group the lead is authorised to view
     * @param string   $leadUserId   the requesting team lead
     * @param int|null $expiringDays optional expiry window (days)
     * @return array<int, array{user_id: string, display_name: string, status: string, passed_at: int|null, expires_at: int|null, due_date: int|null}>
     * @throws ForbiddenException if groupId is empty, or caller holds no oversight for the group/course
     */
    public function getGroupReport(int $courseId, string $groupId, string $leadUserId, ?int $expiringDays): array {
        // SECURITY GATE — MUST be first; all data reads are after this line.
        $this->assertTeamLeadForGroup($courseId, $groupId, $leadUserId);

        $expiresBefore = $expiringDays !== null
            ? $this->timeFactory->getTime() + $expiringDays * self::SECONDS_PER_DAY
            : null;

        $members = $this->assignmentService->expandGroup($groupId);
        $certs   = $this->certificateMapper->findByCourseIdForUsers($courseId, $members, $expiresBefore);
        $states  = $this->assignmentService->getStatesForCourseAndUsers($courseId, $members);

        // Build cert lookup by userId (real DB guarantees these are members; mocks may differ in tests)
        $certsByUserId = [];
        foreach ($certs as $cert) {
            $certsByUserId[$cert->getUserId()] = $cert;
        }

        // Build uid→IUser map from the group for non-cert member display names (one call, lazy)
        $groupUsers = [];
        $group = $this->groupManager->get($groupId);
        if ($group !== null) {
            foreach ($group->getUsers() as $user) {
                $groupUsers[$user->getUID()] = $user;
            }
        }

        $now  = $this->timeFactory->getTime();
        $rows = [];
        foreach ($members as $memberUid) {
            if (isset($certsByUserId[$memberUid])) {
                // Passed: cert was earned (revoked already filtered in mapper)
                $cert = $certsByUserId[$memberUid];
                [$frozenName, ] = $this->decodePayload($cert->getCredentialJson());
                $displayName = ($frozenName === null || $frozenName === '' || $this->looksLikeEmail($frozenName))
                    ? self::FALLBACK_RECIPIENT
                    : $frozenName;
                $rows[] = [
                    'user_id'      => $memberUid,
                    'display_name' => $displayName,
                    'status'       => 'passed',
                    'passed_at'    => $cert->getIssuedAt(),
                    'expires_at'   => $cert->getExpiresAt(),
                    'due_date'     => $states[$memberUid]['due_date'] ?? null,
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
                $rawName    = $user !== null ? $user->getDisplayName() : $memberUid;
                $displayName = $this->looksLikeEmail($rawName) ? self::FALLBACK_RECIPIENT : $rawName;

                $rows[] = [
                    'user_id'      => $memberUid,
                    'display_name' => $displayName,
                    'status'       => $status,
                    'passed_at'    => null,
                    'expires_at'   => null,
                    'due_date'     => $state['due_date'] ?? null,
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
    public function remindMember(int $courseId, string $groupId, string $targetUserId, string $leadUserId): void {
        // SECURITY GATE 1 — asserts lead role; also rejects empty groupId (fail-closed).
        $this->assertTeamLeadForGroup($courseId, $groupId, $leadUserId);

        // SECURITY GATE 2 — asserts target is a member of the group the lead oversees.
        // Generic 403 body on denial to avoid leaking membership information.
        $members = $this->assignmentService->expandGroup($groupId);
        if (!in_array($targetUserId, $members, true)) {
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
     * Project one certificate to the strict 5-field DTO. The frozen name and score are recovered from
     * the signed VC-JWT payload; a malformed/undecodable credential degrades gracefully to the neutral
     * pseudonym + empty score and never aborts the surrounding report.
     *
     * @return array{display_name: string, passed_at: int, score: string, expires_at: int|null, verification_id: string}
     */
    private function projectRow(Certificate $cert): array {
        [$frozenName, $score] = $this->decodePayload($cert->getCredentialJson());

        $displayName = ($frozenName === null || $frozenName === '' || $this->looksLikeEmail($frozenName))
            ? self::FALLBACK_RECIPIENT
            : $frozenName;

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
