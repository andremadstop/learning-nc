<?php
declare(strict_types=1);
namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\CertificateReportController;
use OCA\Learning\Controller\CourseController;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMember;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Service\CertificateReportService;
use OCA\Learning\Service\CourseArchiveService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\ExamAttemptPolicy;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\KeyService;
use OCA\Learning\Service\PassCriteriaService;
use OCA\Learning\Service\PassResult;
use OCA\Learning\Service\RoleService;
use OCA\Learning\Service\ScheduleService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #35: GET /api/courses/{courseId}/pass-status and the instructor's cert report.
 *
 * pass-status reads only the route parameter courseId; the user is the session user. It carries
 * the per-pool progress, the certificate, the issuance error and the enforced retake rules, and
 * keeps its IDOR guard (previously untested): a non-member gets 403 and nothing is evaluated.
 */
#[AllowMockObjectsWithoutExpectations]
class PassStatusControllerTest extends TestCase {

    private function makeCourseController(PassCriteriaService $pass, bool $member, ?string $userId = 'alice', ?IConfig $config = null, bool $courseExists = true): CourseController {
        $course = new Course();
        $course->setId(7);
        $course->setInstructorId('teacher');
        $mapper = $this->createMock(CourseMapper::class);
        if ($courseExists) {
            $mapper->method('findById')->willReturn($course);
        } else {
            $mapper->method('findById')->willThrowException(new DoesNotExistException('gone'));
        }
        $members = $this->createMock(CourseMemberMapper::class);
        if ($member) {
            $members->method('findByCourseAndUser')->willReturn(new CourseMember());
        } else {
            $members->method('findByCourseAndUser')->willThrowException(new DoesNotExistException('none'));
        }
        if ($config === null) {
            $config = $this->createMock(IConfig::class);
            $config->method('getAppValue')->willReturnArgument(2); // admin left the defaults
        }

        return new CourseController(
            'learning',
            $this->createMock(IRequest::class),
            $mapper,
            $members,
            $this->createMock(CourseArchiveService::class),
            $this->createMock(CourseService::class),
            $this->createMock(RoleService::class),
            $this->createMock(ScheduleService::class),
            $pass,
            new ExamAttemptPolicy($config),
            $this->createMock(LoggerInterface::class),
            $userId
        );
    }

    public function testMemberGetsPoolsCertificateAndAttemptPolicy(): void {
        $pass = $this->createMock(PassCriteriaService::class);
        $pass->expects($this->once())->method('evaluate')->with('alice', 7)->willReturn(new PassResult(
            true, 81, 80, true, 1700000000, true, PassResult::REQUIREMENT_REQUIRED_POOLS,
            [['pool_id' => 3, 'title' => 'Brandschutz', 'best_score' => 81, 'passed' => true]],
            null, PassResult::ISSUANCE_SIGNING_UNAVAILABLE,
        ));

        $response = $this->makeCourseController($pass, true)->getPassStatus(7);
        $data = $response->getData();

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($data['passed']);
        $this->assertSame('required_pools', $data['requirement']);
        $this->assertSame(81, $data['pools'][0]['best_score']);
        $this->assertFalse($data['certificate_issued']);
        $this->assertSame('signing_unavailable', $data['issuance_error']);
        $this->assertSame(['limit_per_day' => 5, 'cooldown_minutes' => 10, 'scope' => 'pool'], $data['attempt_policy']);
    }

    // The shown retake rules are the enforced ones, including the clamp TrainingService applies.
    public function testAttemptPolicyUsesTheEnforcedClamp(): void {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnMap([
            ['learning', 'exam_attempt_limit_per_day', '5', '999'],
            ['learning', 'exam_attempt_cooldown_minutes', '10', '0'],
        ]);
        $pass = $this->createMock(PassCriteriaService::class);
        $pass->method('evaluate')->willReturn(new PassResult(false, null, 80, true, null));

        $data = $this->makeCourseController($pass, true, config: $config)->getPassStatus(7)->getData();

        $this->assertSame(50, $data['attempt_policy']['limit_per_day']);
        $this->assertSame(0, $data['attempt_policy']['cooldown_minutes']);
    }

    public function testNonMemberGets403AndNothingIsEvaluated(): void {
        $pass = $this->createMock(PassCriteriaService::class);
        $pass->expects($this->never())->method('evaluate');

        $this->assertSame(403, $this->makeCourseController($pass, false)->getPassStatus(7)->getStatus());
    }

    public function testMissingCourseGets404(): void {
        $pass = $this->createMock(PassCriteriaService::class);
        $pass->expects($this->never())->method('evaluate');

        $this->assertSame(404, $this->makeCourseController($pass, true, courseExists: false)->getPassStatus(7)->getStatus());
    }

    private function makeReportController(CertificateReportService $report, KeyService $keys, IRequest $request): CertificateReportController {
        return new CertificateReportController('learning', $request, $report, $keys, $this->createMock(LoggerInterface::class), 'teacher');
    }

    /**
     * The instructor's compliance report says whether certificates can be issued at all. The
     * controller reads the filter params from/to/expiringDays and passes them through unchanged.
     */
    public function testCertReportCarriesSigningAvailability(): void {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnMap([
            ['from', null, '100'],
            ['to', null, '200'],
            ['expiringDays', null, '30'],
        ]);
        $report = $this->createMock(CertificateReportService::class);
        $report->expects($this->once())->method('getCourseReport')->with(7, 'teacher', 100, 200, 30)->willReturn(['rows' => []]);
        $report->expects($this->once())->method('getPassedWithoutCertificate')->with(7, 'teacher')
            ->willReturn([['display_name' => 'Jane Doe']]);
        $keys = $this->createMock(KeyService::class);
        $keys->method('canSign')->willReturn(false);

        $response = $this->makeReportController($report, $keys, $request)->certReport(7);

        $this->assertSame(200, $response->getStatus());
        $this->assertSame([
            'rows' => [],
            'signing_available' => false,
            'passed_without_certificate' => [['display_name' => 'Jane Doe']],
        ], $response->getData());
    }

    // GitHub #35 (review): the passed-without-certificate list is a read path — its failure
    // degrades to null ("could not be checked"), the report itself still loads.
    public function testCertReportSurvivesFailingPassedWithoutCertificateCheck(): void {
        $report = $this->createMock(CertificateReportService::class);
        $report->method('getCourseReport')->willReturn(['rows' => []]);
        $report->method('getPassedWithoutCertificate')->willThrowException(new \RuntimeException('db gone'));
        $keys = $this->createMock(KeyService::class);
        $keys->method('canSign')->willReturn(true);

        $response = $this->makeReportController($report, $keys, $this->createMock(IRequest::class))->certReport(7);

        $this->assertSame(200, $response->getStatus());
        $this->assertArrayHasKey('passed_without_certificate', $response->getData());
        $this->assertNull($response->getData()['passed_without_certificate']);
        $this->assertStringNotContainsString('db gone', json_encode($response->getData()));
    }

    // A foreign instructor gets 403 before the key state is looked at.
    public function testCertReportForbiddenDoesNotProbeTheKey(): void {
        $report = $this->createMock(CertificateReportService::class);
        $report->method('getCourseReport')->willThrowException(new ForbiddenException('no'));
        $keys = $this->createMock(KeyService::class);
        $keys->expects($this->never())->method('canSign');
        $report->expects($this->never())->method('getPassedWithoutCertificate');

        $response = $this->makeReportController($report, $keys, $this->createMock(IRequest::class))->certReport(7);

        $this->assertSame(403, $response->getStatus());
    }
}
