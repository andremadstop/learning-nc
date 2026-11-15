<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\CertificateController;
use OCA\Learning\Controller\CourseCertificateController;
use OCA\Learning\Db\Certificate;
use OCA\Learning\Db\CertificateMapper;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMember;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Db\CoursePoolMapper;
use OCA\Learning\Db\CurriculumScopeMapper;
use OCA\Learning\Service\AssignmentService;
use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\CertificateReportService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\FeedService;
use OCA\Learning\Service\PassCriteriaService;
use OCA\Learning\Service\ReminderService;
use OCA\Learning\Service\RoleService;
use OCA\Learning\Service\StreakService;
use OCA\Learning\Service\XpService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #42: instructors keep copies of the certificates of their course (View / Download /
 * Download all as ZIP in the compliance report).
 *
 * The REAL CertificateReportService and the REAL CourseService run the gate — only their data
 * mappers are mocked — so these tests prove who gets a certificate, not a stubbed boolean:
 *   - instructor of THIS course → the certificate, the learner's own file, an audit event;
 *   - instructor of another course → 403, before any certificate read;
 *   - an enrolled learner (student member) → 403;
 *   - certificate of another course, malformed id, erased tombstone → 404;
 *   - revoked / expired certificates are labelled.
 */
#[AllowMockObjectsWithoutExpectations]
class CourseCertificateControllerTest extends TestCase {
    private const COURSE_ID = 7;
    private const OTHER_COURSE_ID = 8;
    private const NOW = 1_700_000_000;
    private const VID = '11111111-1111-4111-8111-111111111111';
    private const VID_2 = '22222222-2222-4222-8222-222222222222';

    /** @var CertificateMapper&\PHPUnit\Framework\MockObject\MockObject */
    private $certMapper;
    /** @var AuditService&\PHPUnit\Framework\MockObject\MockObject */
    private $audit;
    /** @var IRequest&\PHPUnit\Framework\MockObject\MockObject */
    private $request;

    protected function setUp(): void {
        $this->certMapper = $this->createMock(CertificateMapper::class);
        $this->audit = $this->createMock(AuditService::class);
        $this->request = $this->createMock(IRequest::class);
    }

    /**
     * Course 7 is taught by 'teacher'. 'student' is an enrolled student member of course 7.
     * 'stranger' teaches course 8 only (no membership in 7).
     */
    private function makeController(?string $userId): CourseCertificateController {
        $courseMapper = $this->createMock(CourseMapper::class);
        $courseMapper->method('findById')->willReturnCallback(function (int $id): Course {
            if ($id !== self::COURSE_ID && $id !== self::OTHER_COURSE_ID) {
                throw new DoesNotExistException('no course');
            }
            $course = new Course();
            $course->setId($id);
            $course->setInstructorId($id === self::COURSE_ID ? 'teacher' : 'stranger');
            return $course;
        });
        $memberMapper = $this->createMock(CourseMemberMapper::class);
        $memberMapper->method('findByCourseAndUser')->willReturnCallback(function (int $courseId, string $uid): CourseMember {
            if ($courseId === self::COURSE_ID && $uid === 'student') {
                $member = new CourseMember();
                $member->setCourseId($courseId);
                $member->setUserId($uid);
                $member->setRole('student');
                return $member;
            }
            throw new DoesNotExistException('no member');
        });

        $courseService = new CourseService(
            $courseMapper,
            $this->createMock(CoursePoolMapper::class),
            $memberMapper,
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
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);

        $service = new CertificateReportService(
            $courseService,
            $this->certMapper,
            $time,
            $this->createMock(RoleService::class),
            $this->createMock(AssignmentService::class),
            $this->createMock(ReminderService::class),
            $this->createMock(IGroupManager::class),
            $this->createMock(PassCriteriaService::class),
            $this->createMock(CourseMemberMapper::class),
            $this->createMock(IUserManager::class)
        );

        return new CourseCertificateController(
            'learning',
            $this->request,
            $service,
            $this->audit,
            $this->createMock(LoggerInterface::class),
            $userId,
            $this->createMock(\OCA\Learning\Service\CertLayoutService::class)
        );
    }

    private function makeCert(string $vid, int $courseId = self::COURSE_ID, ?string $name = 'Jürgen Müller', ?int $expiresAt = null): Certificate {
        $payload = ['credentialSubject' => ['name' => $name, 'result' => [['resultDescription' => 'score:90; threshold:80']]]];
        $b64u = rtrim(strtr(base64_encode((string)json_encode($payload)), '+/', '-_'), '=');
        $cert = new Certificate();
        $cert->setVerificationId($vid);
        $cert->setUserId('learner-uid');
        $cert->setCourseId($courseId);
        $cert->setKeyId('key-1');
        $cert->setCredentialJson('header.' . $b64u . '.sig');
        $cert->setRevoked(false);
        $cert->setIssuedAt(self::NOW - 1000);
        $cert->setExpiresAt($expiresAt);
        return $cert;
    }

    // ---- Rights -------------------------------------------------------------------------------

    public function testInstructorOfAnotherCourseGets403BeforeAnyCertificateRead(): void {
        $this->certMapper->expects($this->never())->method('findByVerificationId');
        $this->certMapper->expects($this->never())->method('findByCourseId');
        $this->audit->expects($this->never())->method('logEvent');

        $controller = $this->makeController('stranger');

        $this->assertSame(Http::STATUS_FORBIDDEN, $controller->show(self::COURSE_ID, self::VID)->getStatus());
        $this->assertSame(Http::STATUS_FORBIDDEN, $controller->download(self::COURSE_ID, self::VID)->getStatus());
        $this->assertSame(Http::STATUS_FORBIDDEN, $controller->exportZip(self::COURSE_ID)->getStatus());
    }

    public function testEnrolledLearnerGets403(): void {
        $this->certMapper->expects($this->never())->method('findByVerificationId');
        $this->certMapper->expects($this->never())->method('findByCourseId');
        $this->audit->expects($this->never())->method('logEvent');

        $controller = $this->makeController('student');

        $this->assertSame(Http::STATUS_FORBIDDEN, $controller->show(self::COURSE_ID, self::VID)->getStatus());
        $this->assertSame(Http::STATUS_FORBIDDEN, $controller->download(self::COURSE_ID, self::VID)->getStatus());
        $this->assertSame(Http::STATUS_FORBIDDEN, $controller->exportZip(self::COURSE_ID)->getStatus());
    }

    public function testMalformedIdIsGatedFirstThen404(): void {
        $this->certMapper->expects($this->never())->method('findByVerificationId');
        $this->assertSame(Http::STATUS_FORBIDDEN, $this->makeController('stranger')->show(self::COURSE_ID, 'nope')->getStatus());
        $this->assertSame(Http::STATUS_NOT_FOUND, $this->makeController('teacher')->show(self::COURSE_ID, 'nope')->getStatus());
    }

    public function testUnauthenticatedGets401(): void {
        $controller = $this->makeController(null);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->show(self::COURSE_ID, self::VID)->getStatus());
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->download(self::COURSE_ID, self::VID)->getStatus());
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->exportZip(self::COURSE_ID)->getStatus());
    }

    /** The teacher of course 8 asks for a course-7 certificate through course 8: not in that course. */
    public function testCertificateOfAnotherCourseThroughOwnCourseIs404(): void {
        $this->certMapper->method('findByVerificationId')->willReturn($this->makeCert(self::VID, self::COURSE_ID));
        $this->audit->expects($this->never())->method('logEvent');

        $controller = $this->makeController('stranger');

        $this->assertSame(Http::STATUS_NOT_FOUND, $controller->show(self::OTHER_COURSE_ID, self::VID)->getStatus());
        $this->assertSame(Http::STATUS_NOT_FOUND, $controller->download(self::OTHER_COURSE_ID, self::VID)->getStatus());
    }

    public function testErasedTombstoneIs404(): void {
        $cert = $this->makeCert(self::VID);
        $cert->setAnonymizedAt(self::NOW - 10);
        $this->certMapper->method('findByVerificationId')->willReturn($cert);

        $this->assertSame(Http::STATUS_NOT_FOUND, $this->makeController('teacher')->show(self::COURSE_ID, self::VID)->getStatus());
    }

    public function testUnknownIdIs404(): void {
        $this->certMapper->method('findByVerificationId')->willThrowException(new DoesNotExistException('none'));
        $this->assertSame(Http::STATUS_NOT_FOUND, $this->makeController('teacher')->download(self::COURSE_ID, self::VID)->getStatus());
    }

    // ---- View ---------------------------------------------------------------------------------

    public function testInstructorViewsCertificateWithStateAndWithoutAccountId(): void {
        $this->certMapper->method('findByVerificationId')->willReturn($this->makeCert(self::VID));
        $this->audit->expects($this->once())->method('logEvent')->with(
            CourseCertificateController::AUDIT_EVENT,
            'teacher',
            ['course_id' => self::COURSE_ID, 'action' => 'view', 'verification_id' => self::VID]
        );

        $resp = $this->makeController('teacher')->show(self::COURSE_ID, self::VID);

        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $data = $resp->getData();
        $this->assertSame(self::VID, $data['verification_id']);
        $this->assertNotEmpty($data['credential_json']);
        $this->assertFalse($data['revoked']);
        $this->assertFalse($data['expired']);
        $this->assertArrayNotHasKey('user_id', $data);
        $this->assertArrayNotHasKey('key_id', $data);
    }

    public function testRevokedAndExpiredCertificatesAreLabelled(): void {
        $cert = $this->makeCert(self::VID, self::COURSE_ID, 'Ana', self::NOW - 5);
        $cert->setRevoked(true);
        $cert->setRevokedAt(self::NOW - 3);
        $this->certMapper->method('findByVerificationId')->willReturn($cert);

        $data = $this->makeController('teacher')->show(self::COURSE_ID, self::VID)->getData();

        $this->assertTrue($data['revoked']);
        $this->assertSame(self::NOW - 3, $data['revoked_at']);
        $this->assertTrue($data['expired']);
    }

    // ---- Download -----------------------------------------------------------------------------

    /** The instructor gets the learner's own file: same bytes, same name, same media type. */
    public function testDownloadIsTheLearnersFile(): void {
        $cert = $this->makeCert(self::VID);
        $this->certMapper->method('findByVerificationId')->willReturn($cert);
        $this->certMapper->method('findByVerificationIdAndUserId')->willReturn($cert);
        $this->audit->expects($this->once())->method('logEvent')->with(
            CourseCertificateController::AUDIT_EVENT,
            'teacher',
            ['course_id' => self::COURSE_ID, 'action' => 'download', 'verification_id' => self::VID, 'format' => 'jsonld']
        );

        $learner = (new CertificateController(
            'learning',
            $this->request,
            $this->certMapper,
            $this->createMock(CourseService::class),
            $this->createMock(ITimeFactory::class),
            'learner-uid',
            $this->createMock(AuditService::class),
            new \OCA\Learning\Tests\Support\FakeDbConnection(),
            $this->createMock(\OCA\Learning\Service\CertLayoutService::class)
        ))->download(self::VID);
        $instructor = $this->makeController('teacher')->download(self::COURSE_ID, self::VID);

        $this->assertInstanceOf(DataDownloadResponse::class, $learner);
        $this->assertInstanceOf(DataDownloadResponse::class, $instructor);
        $this->assertSame($learner->render(), $instructor->render());
        $this->assertSame($learner->getHeaders()['Content-Disposition'], $instructor->getHeaders()['Content-Disposition']);
        $this->assertSame($learner->getHeaders()['Content-Type'], $instructor->getHeaders()['Content-Type']);
        $this->assertStringContainsString('EnvelopedVerifiableCredential', $instructor->render());
    }

    // ---- ZIP ----------------------------------------------------------------------------------

    public function testZipHoldsTheFilteredReportRowsAsLearnerFiles(): void {
        if (!class_exists(\ZipArchive::class)) {
            $this->fail('ext-zip missing in the test runtime');
        }
        $this->request->method('getParam')->willReturnMap([
            ['from', null, '100'],
            ['to', null, null],
            ['expiringDays', null, null],
        ]);
        $valid = $this->makeCert(self::VID, self::COURSE_ID, 'Jürgen Müller');
        $expired = $this->makeCert(self::VID_2, self::COURSE_ID, '../../etc/passwd', self::NOW - 1);
        $this->certMapper->expects($this->once())->method('findByCourseId')
            ->with(self::COURSE_ID, 100, null, null)
            ->willReturn([$valid, $expired]);
        $this->audit->expects($this->once())->method('logEvent')->with(
            CourseCertificateController::AUDIT_EVENT,
            'teacher',
            ['course_id' => self::COURSE_ID, 'action' => 'download_zip', 'count' => 2, 'verification_ids' => [self::VID, self::VID_2]]
        );

        $resp = $this->makeController('teacher')->exportZip(self::COURSE_ID);

        $this->assertInstanceOf(DataDownloadResponse::class, $resp);
        $this->assertSame('application/zip', $resp->getHeaders()['Content-Type']);
        $tmp = (string)tempnam(sys_get_temp_dir(), 'zip-test-');
        file_put_contents($tmp, $resp->render());
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($tmp));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string)$zip->getNameIndex($i);
        }
        $this->assertSame([
            'Jürgen_Müller_' . self::VID . '.json',
            'etc_passwd_' . self::VID_2 . '_expired.json',
        ], $names);
        $this->assertSame(
            \OCA\Learning\Service\CertificateArtifact::content($valid->getCredentialJson(), 'jsonld'),
            $zip->getFromIndex(0)
        );
        $zip->close();
        unlink($tmp);
    }

    public function testEmptyReportGivesNoZip(): void {
        $this->certMapper->method('findByCourseId')->willReturn([]);
        $this->audit->expects($this->never())->method('logEvent');
        $this->assertSame(Http::STATUS_NOT_FOUND, $this->makeController('teacher')->exportZip(self::COURSE_ID)->getStatus());
    }
}
