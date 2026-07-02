<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Certificate;
use OCA\Learning\Db\CertificateMapper;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMember;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CoursePoolMapper;
use OCA\Learning\Db\CurriculumScopeMapper;
use OCA\Learning\Service\AssignmentService;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\CertificateReportService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\FeedService;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\ReminderService;
use OCA\Learning\Service\RoleService;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\XpService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Real-logic contract for CertificateReportService::getCourseReport() — the load-bearing
 * DSGVO/IDOR surface of the compliance report (156-01, REPORT-01..04).
 *
 * Strategy: the REAL CourseService runs the ownership gate (mock only its data mappers — never
 * stub assertInstructorOfCourse to a boolean, that would prove nothing). The CertificateMapper
 * and clock are mocked so we can drive a fake "now" and a hand-built VC-JWT payload through the
 * genuine decode + projection logic.
 */
class CertificateReportServiceTest extends TestCase {

    private const COURSE_ID = 7;
    private const NOW = 1_700_000_000;

    /**
     * Build a real CourseService whose ownership outcome is fully determined by the two mocked
     * mappers: findById returns $course (or throws), findByCourseAndUser returns $member (or throws).
     */
    private function makeCourseService(?Course $course, ?CourseMember $member): CourseService {
        $courseMapper = $this->createMock(CourseMapper::class);
        if ($course !== null) {
            $courseMapper->method('findById')->willReturn($course);
        } else {
            $courseMapper->method('findById')->willThrowException(new DoesNotExistException('no course'));
        }

        $courseMemberMapper = $this->createMock(CourseMemberMapper::class);
        if ($member !== null) {
            $courseMemberMapper->method('findByCourseAndUser')->willReturn($member);
        } else {
            $courseMemberMapper->method('findByCourseAndUser')
                ->willThrowException(new DoesNotExistException('no member'));
        }

        return new CourseService(
            $courseMapper,
            $this->createMock(CoursePoolMapper::class),
            $courseMemberMapper,
            $this->createMock(RoleService::class),
            $this->createMock(IDBConnection::class),
            $this->createMock(IGroupManager::class),
            $this->createMock(IUserManager::class),
            $this->createMock(XpService::class),
            $this->createMock(BadgeService::class),
            $this->createMock(StreakService::class),
            $this->createMock(CurriculumScopeMapper::class),
            $this->createMock(FeedService::class)
        );
    }

    private function makeCourse(string $instructorId): Course {
        $course = new Course();
        $course->setId(self::COURSE_ID);
        $course->setInstructorId($instructorId);
        return $course;
    }

    private function makeTime(int $now = self::NOW): ITimeFactory {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn($now);
        return $time;
    }

    /**
     * Return the 4 new constructor mocks as a positional array for spread into
     * `new CertificateReportService(cs, certMapper, time, ...this->makeNewMocks())`.
     *
     * All inert by default — existing getCourseReport tests never exercise these deps.
     *
     * @return array{0: RoleService, 1: AssignmentService, 2: ReminderService, 3: \OCP\IGroupManager}
     */
    private function makeNewMocks(): array {
        return [
            $this->createMock(RoleService::class),
            $this->createMock(AssignmentService::class),
            $this->createMock(ReminderService::class),
            $this->createMock(IGroupManager::class),
        ];
    }

    /**
     * A Certificate whose credential_json is a (fake-signed) compact VC-JWT — header.payload.sig
     * with the payload base64url-encoded exactly as the real issuer emits it (no padding).
     */
    private function makeCert(
        ?string $name,
        string $resultDescription,
        int $issuedAt,
        ?int $expiresAt,
        string $vid
    ): Certificate {
        $payload = [
            'credentialSubject' => [
                'name' => $name,
                'result' => [['resultDescription' => $resultDescription]],
            ],
        ];
        $json = (string)json_encode($payload, JSON_UNESCAPED_SLASHES);
        $b64u = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        return $this->certWithJwt('header.' . $b64u . '.sig', $issuedAt, $expiresAt, $vid);
    }

    private function certWithJwt(string $jwt, int $issuedAt, ?int $expiresAt, string $vid): Certificate {
        $cert = new Certificate();
        $cert->setVerificationId($vid);
        $cert->setUserId('bob@evil.com');
        $cert->setCourseId(self::COURSE_ID);
        $cert->setCredentialJson($jwt);
        $cert->setIssuedAt($issuedAt);
        $cert->setExpiresAt($expiresAt);
        $cert->setRevoked(false);
        return $cert;
    }

    /**
     * Like makeCert but with an explicit userId — needed for group-report tests where the cert's
     * user_id must match an actual member UID in the roster (certWithJwt hardcodes 'bob@evil.com'
     * which intentionally doesn't match any member in those tests).
     */
    private function makeGroupCert(
        string $userId,
        ?string $name,
        string $resultDescription,
        int $issuedAt,
        ?int $expiresAt,
        string $vid
    ): Certificate {
        $payload = [
            'credentialSubject' => [
                'name' => $name,
                'result' => [['resultDescription' => $resultDescription]],
            ],
        ];
        $json = (string)json_encode($payload, JSON_UNESCAPED_SLASHES);
        $b64u = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $cert = new Certificate();
        $cert->setVerificationId($vid);
        $cert->setUserId($userId);
        $cert->setCourseId(self::COURSE_ID);
        $cert->setCredentialJson('header.' . $b64u . '.sig');
        $cert->setIssuedAt($issuedAt);
        $cert->setExpiresAt($expiresAt);
        $cert->setRevoked(false);
        return $cert;
    }

    /**
     * REPORT-04 (load-bearing): an email-shaped frozen name AND an email-shaped account id must both
     * be unreachable in the output. Exactly 5 DTO keys, none named user_id/email, email name → neutral
     * pseudonym; a clean UTF-8 name passes through unchanged.
     */
    public function testNoLeakProjectsFiveSafeFields(): void {
        $certMapper = $this->createMock(CertificateMapper::class);
        $certMapper->method('findByCourseId')->willReturn([
            $this->makeCert('alice@example.com', 'score:87.5; threshold:80', 1000, 1234567890, 'vid-leak'),
            $this->makeCert('Jürgen Müller', 'score:90; threshold:80', 2000, null, 'vid-clean'),
        ]);

        $service = new CertificateReportService(
            $this->makeCourseService($this->makeCourse('alice'), null),
            $certMapper,
            $this->makeTime(),
            ...$this->makeNewMocks()
        );

        $result = $service->getCourseReport(self::COURSE_ID, 'alice', null, null, null);
        $rows = $result['rows'];
        $this->assertCount(2, $rows);

        $leakRow = $rows[0];
        $this->assertSame(
            ['display_name', 'passed_at', 'score', 'expires_at', 'verification_id'],
            array_keys($leakRow),
            'DTO must expose exactly the 5 safe fields, in order'
        );
        $this->assertArrayNotHasKey('user_id', $leakRow);
        $this->assertArrayNotHasKey('email', $leakRow);
        $this->assertSame('Teilnehmer:in', $leakRow['display_name'], 'email-shaped frozen name → neutral fallback');
        $this->assertStringNotContainsString('@', (string)$leakRow['display_name']);
        $this->assertSame('87.5', $leakRow['score']);
        $this->assertSame(1000, $leakRow['passed_at']);
        $this->assertSame(1234567890, $leakRow['expires_at']);
        $this->assertSame('vid-leak', $leakRow['verification_id']);

        $this->assertSame('Jürgen Müller', $rows[1]['display_name'], 'clean UTF-8 name passes through unchanged');
        $this->assertNull($rows[1]['expires_at']);
    }

    /**
     * IDOR (load-bearing): course owned by alice, bob is not a member → ForbiddenException, and the
     * cert mapper is NEVER touched (gate runs BEFORE any read), flowing through the REAL ownership check.
     */
    public function testForeignInstructorIsForbiddenBeforeAnyRead(): void {
        $certMapper = $this->createMock(CertificateMapper::class);
        $certMapper->expects($this->never())->method('findByCourseId');

        $service = new CertificateReportService(
            $this->makeCourseService($this->makeCourse('alice'), null),
            $certMapper,
            $this->makeTime(),
            ...$this->makeNewMocks()
        );

        $this->expectException(ForbiddenException::class);
        $service->getCourseReport(self::COURSE_ID, 'bob', null, null, null);
    }

    /**
     * A co-instructor member (role=instructor) IS allowed (the owner-OR-member gate), proving the gate
     * is the real CourseService logic, not a hard-coded owner check.
     */
    public function testCoInstructorMemberIsAllowed(): void {
        $member = new CourseMember();
        $member->setCourseId(self::COURSE_ID);
        $member->setUserId('bob');
        $member->setRole('instructor');

        $certMapper = $this->createMock(CertificateMapper::class);
        $certMapper->method('findByCourseId')->willReturn([]);

        $service = new CertificateReportService(
            $this->makeCourseService($this->makeCourse('alice'), $member),
            $certMapper,
            $this->makeTime(),
            ...$this->makeNewMocks()
        );

        $this->assertSame(['rows' => []], $service->getCourseReport(self::COURSE_ID, 'bob', null, null, null));
    }

    /**
     * REPORT-02: from/to are passed to the mapper UNCHANGED, and expiringDays is converted in the
     * SERVICE (which owns the clock) into an ABSOLUTE cutoff = now + N*86400. The mapper is time-free.
     */
    public function testExpiringDaysConvertedToAbsoluteCutoff(): void {
        $certMapper = $this->createMock(CertificateMapper::class);
        $certMapper->expects($this->once())
            ->method('findByCourseId')
            ->with(self::COURSE_ID, 100, 200, self::NOW + 30 * 86400)
            ->willReturn([]);

        $service = new CertificateReportService(
            $this->makeCourseService($this->makeCourse('alice'), null),
            $certMapper,
            $this->makeTime(),
            ...$this->makeNewMocks()
        );

        $service->getCourseReport(self::COURSE_ID, 'alice', 100, 200, 30);
    }

    /**
     * REPORT-02: with no expiringDays the absolute cutoff is null (the mapper's IS NOT NULL / never-
     * expiring exclusion only engages when a cutoff is supplied); from/to still pass through.
     */
    public function testNoExpiringDaysPassesNullCutoff(): void {
        $certMapper = $this->createMock(CertificateMapper::class);
        $certMapper->expects($this->once())
            ->method('findByCourseId')
            ->with(self::COURSE_ID, null, null, null)
            ->willReturn([]);

        $service = new CertificateReportService(
            $this->makeCourseService($this->makeCourse('alice'), null),
            $certMapper,
            $this->makeTime(),
            ...$this->makeNewMocks()
        );

        $service->getCourseReport(self::COURSE_ID, 'alice', null, null, null);
    }

    /**
     * REPORT-01 / Pitfall 5: an empty score renders as '' (UI shows "—"); a present score parses out.
     */
    public function testEmptyScoreYieldsEmptyString(): void {
        $certMapper = $this->createMock(CertificateMapper::class);
        $certMapper->method('findByCourseId')->willReturn([
            $this->makeCert('Clean Name', 'score:; threshold:80', 1000, null, 'vid-noscore'),
        ]);

        $service = new CertificateReportService(
            $this->makeCourseService($this->makeCourse('alice'), null),
            $certMapper,
            $this->makeTime(),
            ...$this->makeNewMocks()
        );

        $rows = $service->getCourseReport(self::COURSE_ID, 'alice', null, null, null)['rows'];
        $this->assertSame('', $rows[0]['score']);
    }

    /**
     * Pitfall 5: an undecodable / dot-less credential JWT must NOT throw — the row is still emitted with
     * the neutral pseudonym, and the rest of the array is intact.
     */
    public function testMalformedJwtFallsBackWithoutAborting(): void {
        $certMapper = $this->createMock(CertificateMapper::class);
        $certMapper->method('findByCourseId')->willReturn([
            $this->certWithJwt('this-is-not-a-jwt', 1000, null, 'vid-broken'),
            $this->makeCert('Clean Name', 'score:55; threshold:50', 2000, null, 'vid-ok'),
        ]);

        $service = new CertificateReportService(
            $this->makeCourseService($this->makeCourse('alice'), null),
            $certMapper,
            $this->makeTime(),
            ...$this->makeNewMocks()
        );

        $rows = $service->getCourseReport(self::COURSE_ID, 'alice', null, null, null)['rows'];
        $this->assertCount(2, $rows, 'a malformed credential must not abort the rest of the report');
        $this->assertSame('Teilnehmer:in', $rows[0]['display_name']);
        $this->assertSame('', $rows[0]['score']);
        $this->assertSame('Clean Name', $rows[1]['display_name']);
        $this->assertSame('55', $rows[1]['score']);
    }

    // ════════════════════════════════════════════════════════════════════════
    // Wave-0 denial tests for getGroupReport / remindMember (RBAC-02 / RBAC-04)
    //
    // All five tests are RED against the skeleton (Wave 0). They flip GREEN
    // in 163-05 (getGroupReport + assertTeamLeadForGroup) and 163-06 (remindMember).
    // The never()-constraints are LOCKED: deny paths must NEVER reach data sources.
    // ════════════════════════════════════════════════════════════════════════

    /**
     * RBAC-02 (RED): getGroupReport for a group the caller does NOT lead → ForbiddenException.
     *
     * ASSERT-FIRST invariant (Research Pitfall 3): expandGroup and findByCourseIdForUsers
     * are constrained to never(). The deny path must throw BEFORE any read — not after.
     * Skeleton no-op returns [] without throwing → RED until 163-05 adds assertTeamLeadForGroup.
     */
    public function testGroupReportForeignGroupThrows(): void {
        $assignmentService = $this->createMock(AssignmentService::class);
        $assignmentService->expects($this->never())->method('expandGroup');

        $certMapper = $this->createMock(CertificateMapper::class);
        $certMapper->expects($this->never())->method('findByCourseIdForUsers');

        $service = new CertificateReportService(
            $this->makeCourseService($this->makeCourse('alice'), null),
            $certMapper,
            $this->makeTime(),
            $this->createMock(RoleService::class),   // isTeamLeadForGroup returns false by default
            $assignmentService,
            $this->createMock(ReminderService::class),
            $this->createMock(IGroupManager::class)
        );

        $this->expectException(ForbiddenException::class);
        // bob holds no oversight row for 'dept-a' in course 42 → must throw before any read
        $service->getGroupReport(42, 'dept-a', 'bob', null);
    }

    /**
     * RBAC-02 (RED): empty groupId must fail closed — never treated as "all groups".
     *
     * ASSERT-FIRST invariant: same never()-constraints. Skeleton no-op returns [] → RED.
     * Real impl (163-05) must validate groupId !== '' and throw BEFORE any DB access.
     */
    public function testGroupReportEmptyGroupIdFailsClosed(): void {
        $assignmentService = $this->createMock(AssignmentService::class);
        $assignmentService->expects($this->never())->method('expandGroup');

        $certMapper = $this->createMock(CertificateMapper::class);
        $certMapper->expects($this->never())->method('findByCourseIdForUsers');

        $service = new CertificateReportService(
            $this->makeCourseService($this->makeCourse('alice'), null),
            $certMapper,
            $this->makeTime(),
            $this->createMock(RoleService::class),
            $assignmentService,
            $this->createMock(ReminderService::class),
            $this->createMock(IGroupManager::class)
        );

        $this->expectException(ForbiddenException::class);
        // empty groupId must fail closed — not resolved to all-groups
        $service->getGroupReport(42, '', 'alice', null);
    }

    /**
     * RBAC-02 (RED): group report must include ALL members — cert holders (passed),
     * overdue members (due_date < now, not passed), and missing members (no cert, no overdue).
     *
     * Non-cert rows use IGroupManager::get()->getUsers() for display names.
     * Skeleton returns [] → assertNotEmpty fails → RED until 163-05.
     */
    public function testGroupReportIncludesMembersWithoutCert(): void {
        $now = self::NOW;

        // Bob has a passing cert; carol is overdue; dave has no cert and no overdue assignment.
        // Rule-1 fix: certWithJwt hardcodes userId='bob@evil.com'; for group-report tests the
        // cert's userId must match the member UID 'bob' so the service can build the certsByUserId
        // lookup correctly. makeGroupCert accepts an explicit userId.
        $certMapper = $this->createMock(CertificateMapper::class);
        $certMapper->method('findByCourseIdForUsers')
            ->willReturn([
                $this->makeGroupCert('bob', 'Robert', 'score:90; threshold:80', $now - 1000, null, 'vid-bob'),
            ]);

        $assignmentService = $this->createMock(AssignmentService::class);
        $assignmentService->method('expandGroup')->with('team-x')->willReturn(['bob', 'carol', 'dave']);
        $assignmentService->method('getStatesForCourseAndUsers')
            ->willReturn([
                'carol' => ['status' => 'assigned', 'due_date' => $now - 86400], // overdue
                'dave'  => ['status' => 'assigned', 'due_date' => null],          // missing
            ]);

        $roleService = $this->createMock(RoleService::class);
        $roleService->method('isTeamLeadForGroup')->willReturn(true);

        // IGroupManager provides display names for non-cert members
        $carolUser = $this->createMock(\OCP\IUser::class);
        $carolUser->method('getUID')->willReturn('carol');
        $carolUser->method('getDisplayName')->willReturn('Carol Smith');

        $daveUser = $this->createMock(\OCP\IUser::class);
        $daveUser->method('getUID')->willReturn('dave');
        $daveUser->method('getDisplayName')->willReturn('Dave Jones');

        $groupMock = $this->createMock(\OCP\IGroup::class);
        $groupMock->method('getUsers')->willReturn([$carolUser, $daveUser]);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('get')->with('team-x')->willReturn($groupMock);

        $service = new CertificateReportService(
            $this->makeCourseService($this->makeCourse('alice'), null),
            $certMapper,
            $this->makeTime(),
            $roleService,
            $assignmentService,
            $this->createMock(ReminderService::class),
            $groupManager
        );

        $rows = $service->getGroupReport(10, 'team-x', 'alice', null);

        $this->assertNotEmpty($rows, 'getGroupReport must include all group members');

        // Rows are keyed by the opaque member_ref (raw uid is never exposed). Recompute the ref
        // with the SAME algorithm the service uses to look up each member's status.
        $ref = static fn (string $u): string => substr(hash('sha256', 'learning:group_member_ref:v1:' . $u), 0, 24);
        $byRef = [];
        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('user_id', $row, 'raw uid must never appear in a report row');
            $this->assertArrayHasKey('member_ref', $row);
            $byRef[$row['member_ref']] = $row;
        }
        $this->assertSame('passed',  $byRef[$ref('bob')]['status'] ?? null);
        $this->assertSame('overdue', $byRef[$ref('carol')]['status'] ?? null);
        $this->assertSame('missing', $byRef[$ref('dave')]['status'] ?? null);
    }

    /**
     * RBAC-02 / DSGVO (GREEN + LOCKED): no row value in the group report may contain an
     * email-shaped token. Mirrors looksLikeEmail intent for non-cert member rows.
     *
     * Skeleton returns [] → no rows → no email found → GREEN trivially.
     * Real impl (163-05) must sanitise display names from IGroupManager the same way
     * getCourseReport sanitises frozen names from VC-JWTs. Must stay GREEN post-impl.
     */
    public function testGroupReportDtoCarriesNoEmail(): void {
        // Worst case: the member's NC uid IS an email AND their display name is email-shaped.
        // Both must be unreachable in the DTO (uid → opaque member_ref; name → neutral fallback).
        $emailUser = $this->createMock(\OCP\IUser::class);
        $emailUser->method('getUID')->willReturn('attacker@example.com'); // email-shaped UID
        $emailUser->method('getDisplayName')->willReturn('victim@example.com'); // email-shaped name

        $group = $this->createMock(\OCP\IGroup::class);
        $group->method('getUsers')->willReturn([$emailUser]);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('get')->willReturn($group);

        $assignmentService = $this->createMock(AssignmentService::class);
        $assignmentService->method('expandGroup')->willReturn(['attacker@example.com']);
        $assignmentService->method('getStatesForCourseAndUsers')->willReturn([]);

        $certMapper = $this->createMock(CertificateMapper::class);
        $certMapper->method('findByCourseIdForUsers')->willReturn([]);

        $roleService = $this->createMock(RoleService::class);
        $roleService->method('isTeamLeadForGroup')->willReturn(true);

        $service = new CertificateReportService(
            $this->makeCourseService($this->makeCourse('alice'), null),
            $certMapper,
            $this->makeTime(),
            $roleService,
            $assignmentService,
            $this->createMock(ReminderService::class),
            $groupManager
        );

        $rows = $service->getGroupReport(10, 'team-x', 'alice', null);

        // Must actually produce the member row (not vacuously empty) so the scan is meaningful.
        $this->assertNotEmpty($rows, 'the email-uid member must appear in the report');
        $this->assertArrayHasKey('member_ref', $rows[0]);
        $this->assertArrayNotHasKey('user_id', $rows[0], 'raw email-shaped uid must not be exposed');

        // Scan ALL string values in every row for email patterns — none may match
        foreach ($rows as $row) {
            foreach ($row as $value) {
                if (is_string($value)) {
                    $this->assertDoesNotMatchRegularExpression(
                        '/[^@\s]+@[^@\s]+\.[^@\s]+/',
                        $value,
                        'Group report DTO must not expose any email-shaped value'
                    );
                }
            }
        }
    }

    /**
     * RBAC-02 / DSGVO (GREEN + LOCKED): when NC has no display name set, getDisplayName() returns
     * the raw uid. The report must NOT surface that account identifier — display_name falls back
     * to the neutral recipient label when the candidate equals the uid (common for bulk-created
     * compliance accounts). Non-email uid, so this is NOT caught by the email guard alone.
     */
    public function testGroupReportDisplayNameNeverRawUid(): void {
        $noNameUser = $this->createMock(\OCP\IUser::class);
        $noNameUser->method('getUID')->willReturn('jane.doe');
        $noNameUser->method('getDisplayName')->willReturn('jane.doe'); // unset → NC returns the uid

        $group = $this->createMock(\OCP\IGroup::class);
        $group->method('getUsers')->willReturn([$noNameUser]);
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('get')->willReturn($group);

        $assignmentService = $this->createMock(AssignmentService::class);
        $assignmentService->method('expandGroup')->willReturn(['jane.doe']);
        $assignmentService->method('getStatesForCourseAndUsers')->willReturn([]);

        $certMapper = $this->createMock(CertificateMapper::class);
        $certMapper->method('findByCourseIdForUsers')->willReturn([]);

        $roleService = $this->createMock(RoleService::class);
        $roleService->method('isTeamLeadForGroup')->willReturn(true);

        $service = new CertificateReportService(
            $this->makeCourseService($this->makeCourse('alice'), null),
            $certMapper,
            $this->makeTime(),
            $roleService,
            $assignmentService,
            $this->createMock(ReminderService::class),
            $groupManager
        );

        $rows = $service->getGroupReport(10, 'team-x', 'alice', null);

        $this->assertNotEmpty($rows);
        $this->assertSame('Teilnehmer:in', $rows[0]['display_name'],
            'display_name equal to the uid must fall back to the neutral recipient label');
        $this->assertNotSame('jane.doe', $rows[0]['display_name'], 'raw uid must never be the display name');
    }

    /**
     * RBAC-04 (RED): remindMember with a targetUid that is NOT in the lead's group → ForbiddenException.
     *
     * ASSERT-FIRST invariant: sendComplianceReminder must NEVER be dispatched on the deny path.
     * Skeleton no-op returns void without throwing → RED until 163-06.
     */
    public function testRemindMemberForeignTargetThrows(): void {
        $reminderService = $this->createMock(ReminderService::class);
        $reminderService->expects($this->never())->method('sendComplianceReminder');

        $assignmentService = $this->createMock(AssignmentService::class);
        $assignmentService->method('expandGroup')->willReturn(['alice', 'charlie']); // 'dave' not in group

        $roleService = $this->createMock(RoleService::class);
        $roleService->method('isTeamLeadForGroup')->willReturn(true); // lead is valid, but target is foreign

        $service = new CertificateReportService(
            $this->makeCourseService($this->makeCourse('alice'), null),
            $this->createMock(CertificateMapper::class),
            $this->makeTime(),
            $roleService,
            $assignmentService,
            $reminderService,
            $this->createMock(IGroupManager::class)
        );

        $this->expectException(ForbiddenException::class);
        // A bogus member_ref that resolves to NO member of 'dept-a' → ForbiddenException;
        // sendComplianceReminder never called (assert-first, no membership oracle).
        $service->remindMember(42, 'dept-a', 'bogus-ref-not-a-member', 'bob');
    }

    /**
     * RBAC-04 (GREEN + LOCKED): a valid member_ref (the one the report DTO emits for a member)
     * resolves back to that member's uid and dispatches. This locks the emit/resolve symmetry —
     * if getGroupReport and remindMember ever compute the ref differently, reminders silently
     * no-op and this test fails.
     */
    public function testRemindMemberResolvesValidMemberRef(): void {
        $ref = static fn (string $u): string => substr(hash('sha256', 'learning:group_member_ref:v1:' . $u), 0, 24);

        $reminderService = $this->createMock(ReminderService::class);
        $reminderService->expects($this->once())
            ->method('sendComplianceReminder')
            ->with('charlie', 42, $this->anything());

        $assignmentService = $this->createMock(AssignmentService::class);
        $assignmentService->method('expandGroup')->willReturn(['alice', 'charlie']);

        $roleService = $this->createMock(RoleService::class);
        $roleService->method('isTeamLeadForGroup')->willReturn(true);

        $service = new CertificateReportService(
            $this->makeCourseService($this->makeCourse('alice'), null),
            $this->createMock(CertificateMapper::class),
            $this->makeTime(),
            $roleService,
            $assignmentService,
            $reminderService,
            $this->createMock(IGroupManager::class)
        );

        // The ref for 'charlie' must resolve to 'charlie' and dispatch exactly once.
        $service->remindMember(42, 'dept-a', $ref('charlie'), 'bob');
    }
}
