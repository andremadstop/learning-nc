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
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\CertificateReportService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\FeedService;
use OCA\Learning\Service\ForbiddenException;
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
            $this->makeTime()
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
            $this->makeTime()
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
            $this->makeTime()
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
            $this->makeTime()
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
            $this->makeTime()
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
            $this->makeTime()
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
            $this->makeTime()
        );

        $rows = $service->getCourseReport(self::COURSE_ID, 'alice', null, null, null)['rows'];
        $this->assertCount(2, $rows, 'a malformed credential must not abort the rest of the report');
        $this->assertSame('Teilnehmer:in', $rows[0]['display_name']);
        $this->assertSame('', $rows[0]['score']);
        $this->assertSame('Clean Name', $rows[1]['display_name']);
        $this->assertSame('55', $rows[1]['score']);
    }
}
