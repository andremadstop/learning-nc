<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\CertificateController;
use OCA\Learning\Db\Certificate;
use OCA\Learning\Db\CertificateMapper;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMember;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CoursePoolMapper;
use OCA\Learning\Db\CurriculumScopeMapper;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\FeedService;
use OCA\Learning\Service\RoleService;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\XpService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Phase 157 Plan 03 — CertificateController::revoke() (VERIFY-05 write side).
 *
 * The owner gate is the REAL CourseService (mock only its 12 data deps — never stub
 * assertInstructorOfCourse to a boolean, that would prove nothing). The CertificateMapper and the
 * clock are mocked so the revoke write path can be observed:
 *   - tombstone fields set together (revoked=true + revoked_at=now + active_idem_key=NULL);
 *   - idempotent: a repeat revoke keeps the FIRST revoked_at;
 *   - the owner gate runs BEFORE any write (mapper->update never() on a non-owner);
 *   - malformed UUID → 404 with no DB lookup; userId null → 401.
 *
 * @group cert-revoke
 */
class CertificateRevokeTest extends TestCase {

    private const COURSE_ID = 7;
    private const NOW = 1_700_000_000;
    private const VID = '11111111-1111-4111-8111-111111111111';

    /** @var CertificateMapper&\PHPUnit\Framework\MockObject\MockObject */
    private $mapperMock;
    /** @var IRequest&\PHPUnit\Framework\MockObject\MockObject */
    private $requestMock;

    protected function setUp(): void {
        $this->mapperMock = $this->createMock(CertificateMapper::class);
        $this->requestMock = $this->createMock(IRequest::class);
    }

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

    private function makeCert(?int $revokedAt = null): Certificate {
        $cert = new Certificate();
        $cert->setVerificationId(self::VID);
        $cert->setUserId('student');
        $cert->setCourseId(self::COURSE_ID);
        $cert->setKeyId('key-1');
        $cert->setCredentialJson('h.p.s');
        $cert->setRevoked($revokedAt !== null);
        $cert->setRevokedAt($revokedAt);
        $cert->setActiveIdemKey('student:' . self::COURSE_ID);
        $cert->setIssuedAt(1000);
        $cert->setExpiresAt(null);
        return $cert;
    }

    private function makeController(CourseService $courseService, ITimeFactory $time, ?string $userId): CertificateController {
        return new CertificateController(
            'learning',
            $this->requestMock,
            $this->mapperMock,
            $courseService,
            $time,
            $userId
        );
    }

    /**
     * The owner revokes: revoked=true, revoked_at=now, active_idem_key=NULL — all set on the SAME
     * entity that is handed to update() exactly once (atomic tombstone write).
     */
    public function testRevokeSetsTombstoneFields(): void {
        $cert = $this->makeCert();
        $this->mapperMock->method('findByVerificationId')->with(self::VID)->willReturn($cert);

        $updated = null;
        $this->mapperMock->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (Certificate $c) use (&$updated) {
                $updated = $c;
                return $c;
            });

        $resp = $this->makeController(
            $this->makeCourseService($this->makeCourse('alice'), null),
            $this->makeTime(),
            'alice'
        )->revoke(self::VID);

        $this->assertInstanceOf(JSONResponse::class, $resp);
        $this->assertSame(['revoked' => true, 'verification_id' => self::VID], $resp->getData());

        $this->assertNotNull($updated);
        $this->assertTrue($updated->getRevoked());
        $this->assertSame(self::NOW, $updated->getRevokedAt());
        $this->assertNull($updated->getActiveIdemKey(), 'active_idem_key freed (R2-2) so re-issue stays possible');
    }

    /**
     * Idempotent: an already-revoked cert (revoked_at=T0) revoked again at T1 keeps T0 — the FIRST
     * revocation time is NOT overwritten; active_idem_key is still nulled.
     */
    public function testRevokeIdempotentKeepsFirstDate(): void {
        $firstRevokedAt = 1_650_000_000;
        $cert = $this->makeCert($firstRevokedAt);
        $this->mapperMock->method('findByVerificationId')->with(self::VID)->willReturn($cert);

        $updated = null;
        $this->mapperMock->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (Certificate $c) use (&$updated) {
                $updated = $c;
                return $c;
            });

        $this->makeController(
            $this->makeCourseService($this->makeCourse('alice'), null),
            $this->makeTime(self::NOW), // T1 > T0
            'alice'
        )->revoke(self::VID);

        $this->assertNotNull($updated);
        $this->assertTrue($updated->getRevoked());
        $this->assertSame($firstRevokedAt, $updated->getRevokedAt(), 'first revoked_at must be kept on a repeat revoke');
        $this->assertNull($updated->getActiveIdemKey());
    }

    /**
     * Owner gate runs BEFORE any write: a non-owner (course owned by alice, bob not a member) → the
     * REAL CourseService throws ForbiddenException → uniform 404, and update() is NEVER called.
     */
    public function testOwnerGateRunsBeforeWrite(): void {
        $cert = $this->makeCert();
        $this->mapperMock->method('findByVerificationId')->with(self::VID)->willReturn($cert);
        $this->mapperMock->expects($this->never())->method('update');

        $resp = $this->makeController(
            $this->makeCourseService($this->makeCourse('alice'), null),
            $this->makeTime(),
            'bob'
        )->revoke(self::VID);

        $this->assertInstanceOf(JSONResponse::class, $resp);
        $this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
        $this->assertArrayNotHasKey('verification_id', $resp->getData());
    }

    /**
     * A malformed (non-UUIDv4) verification id → 404 BEFORE any DB lookup: the mapper is never queried.
     */
    public function testMalformedUuid404(): void {
        $this->mapperMock->expects($this->never())->method('findByVerificationId');
        $this->mapperMock->expects($this->never())->method('update');

        $resp = $this->makeController(
            $this->makeCourseService($this->makeCourse('alice'), null),
            $this->makeTime(),
            'alice'
        )->revoke('../../etc/passwd');

        $this->assertInstanceOf(JSONResponse::class, $resp);
        $this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
    }

    /**
     * An unknown verification id (mapper throws) → uniform 404, no write.
     */
    public function testUnknownVerificationId404(): void {
        $this->mapperMock->method('findByVerificationId')
            ->willThrowException(new DoesNotExistException('no such cert'));
        $this->mapperMock->expects($this->never())->method('update');

        $resp = $this->makeController(
            $this->makeCourseService($this->makeCourse('alice'), null),
            $this->makeTime(),
            'alice'
        )->revoke(self::VID);

        $this->assertInstanceOf(JSONResponse::class, $resp);
        $this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
    }

    /**
     * Unauthenticated (userId null) → 401 before any lookup or write.
     */
    public function testUnauthenticated401(): void {
        $this->mapperMock->expects($this->never())->method('findByVerificationId');
        $this->mapperMock->expects($this->never())->method('update');

        $resp = $this->makeController(
            $this->makeCourseService($this->makeCourse('alice'), null),
            $this->makeTime(),
            null
        )->revoke(self::VID);

        $this->assertInstanceOf(JSONResponse::class, $resp);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $resp->getStatus());
    }
}
