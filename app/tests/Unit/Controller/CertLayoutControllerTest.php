<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\CertificateController;
use OCA\Learning\Controller\CertLayoutController;
use OCA\Learning\Controller\CourseController;
use OCA\Learning\Db\Certificate;
use OCA\Learning\Db\CertificateMapper;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\CourseMemberMapper;
use OCA\Learning\Service\CertLayout;
use OCA\Learning\Service\CertLayoutService;
use OCA\Learning\Service\CourseArchiveService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\ExamAttemptPolicy;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\PassCriteriaService;
use OCA\Learning\Service\RoleService;
use OCA\Learning\Service\ScheduleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #43: who may change the certificate layout, and what the certificate response carries.
 */
#[AllowMockObjectsWithoutExpectations]
class CertLayoutControllerTest extends TestCase {
    private Course $course;
    /** @var Course[] */
    private array $updated = [];

    private function courseController(string $userId): CourseController {
        $this->course = new Course();
        $this->course->setId(7);
        $this->course->setInstructorId('teacher');
        $mapper = $this->createMock(CourseMapper::class);
        $mapper->method('findById')->willReturn($this->course);
        $mapper->method('update')->willReturnCallback(function (Course $c): Course {
            $this->updated[] = $c;
            return $c;
        });
        $members = $this->createMock(CourseMemberMapper::class);
        $members->method('findByCourseAndUser')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('none'));
        return new CourseController(
            'learning',
            $this->createMock(IRequest::class),
            $mapper,
            $members,
            $this->createMock(CourseArchiveService::class),
            $this->createMock(CourseService::class),
            $this->createMock(RoleService::class),
            $this->createMock(ScheduleService::class),
            $this->createMock(PassCriteriaService::class),
            $this->createMock(ExamAttemptPolicy::class),
            $this->createMock(\OCA\Learning\Service\CourseEnrollmentService::class),
            $this->createMock(LoggerInterface::class),
            $userId
        );
    }

    private function saveLayout(string $userId, array $layout): \OCP\AppFramework\Http\DataResponse {
        return $this->courseController($userId)->updateCertConfig(7, null, null, null, null, null, null, null, $layout);
    }

    public function testInstructorSavesTextSwitchesAndSigner(): void {
        $response = $this->saveLayout('teacher', [
            'intro' => 'Hiermit wird bestätigt, dass {student.name} den Kurs {course.title} abgeschlossen hat.',
            'show' => ['logo' => false, 'threshold' => false],
            'signer_name' => 'Maria Muster',
            'signer_role' => 'Leitung Weiterbildung',
        ]);

        $this->assertSame(200, $response->getStatus());
        $stored = CertLayout::decode($this->course->getCertLayout());
        $this->assertSame('Maria Muster', $stored['signer_name']);
        $this->assertFalse($stored['show']['logo']);
        $this->assertTrue($stored['show']['score']);
        $this->assertSame('Leitung Weiterbildung', $response->getData()['certLayout']['signer_role']);
        $this->assertSame('Maria Muster', $this->course->jsonSerialize()['cert_layout']['signer_name']);
    }

    public function testLearnerCannotChangeTheLayout(): void {
        $response = $this->saveLayout('learner', ['intro' => 'gefälscht']);

        $this->assertSame(403, $response->getStatus());
        $this->assertSame([], $this->updated);
        $this->assertNull($this->course->getCertLayout());
    }

    public function testInvalidLayoutSavesNothing(): void {
        $response = $this->saveLayout('teacher', ['intro' => str_repeat('x', CertLayout::INTRO_MAX + 1)]);

        $this->assertSame(400, $response->getStatus());
        $this->assertSame([], $this->updated);
    }

    public function testOmittedLayoutLeavesItUnchanged(): void {
        $controller = $this->courseController('teacher');
        $layout = CertLayout::defaults();
        $layout['intro'] = 'bleibt';
        $this->course->setCertLayout(CertLayout::encode($layout));

        $response = $controller->updateCertConfig(7, true);

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('bleibt', CertLayout::decode($this->course->getCertLayout())['intro']);
    }

    private function layoutController(CourseService $courses, CertLayoutService $layouts, ?string $userId = 'teacher', ?\OCA\Learning\Service\CertLogoLibrary $library = null): CertLayoutController {
        return new CertLayoutController(
            'learning',
            $this->createMock(IRequest::class),
            $courses,
            $layouts,
            $this->createMock(LoggerInterface::class),
            $userId,
            $library ?? $this->createMock(\OCA\Learning\Service\CertLogoLibrary::class),
        );
    }

    public function testNonInstructorCannotImportAnImage(): void {
        $courses = $this->createMock(CourseService::class);
        $courses->method('assertInstructorOfCourse')->willThrowException(new ForbiddenException('No permission'));
        $layouts = $this->createMock(CertLayoutService::class);
        $layouts->expects($this->never())->method('importImage');

        $response = $this->layoutController($courses, $layouts, 'learner')->importImage(7, 'stamp', '/Stempel.png');

        $this->assertSame(403, $response->getStatus());
    }

    public function testNonInstructorCannotSeeTheSettingsPreview(): void {
        $courses = $this->createMock(CourseService::class);
        $courses->method('assertInstructorOfCourse')->willThrowException(new ForbiddenException('No permission'));
        $layouts = $this->createMock(CertLayoutService::class);
        $layouts->expects($this->never())->method('presentationForCourse');

        $this->assertSame(403, $this->layoutController($courses, $layouts, 'learner')->show(7)->getStatus());
    }

    public function testNonInstructorCannotRemoveAnImage(): void {
        $courses = $this->createMock(CourseService::class);
        $courses->method('assertInstructorOfCourse')->willThrowException(new ForbiddenException('No permission'));
        $layouts = $this->createMock(CertLayoutService::class);
        $layouts->expects($this->never())->method('removeImage');

        $this->assertSame(403, $this->layoutController($courses, $layouts, 'learner')->removeImage(7, 'stamp')->getStatus());
    }

    public function testUnknownKindIs400(): void {
        $layouts = $this->createMock(CertLayoutService::class);
        $layouts->expects($this->never())->method('importImage');

        $response = $this->layoutController($this->createMock(CourseService::class), $layouts)->importImage(7, 'logo', '/x.png');

        $this->assertSame(400, $response->getStatus());
    }

    public function testMissingFileIs404AndBadFileIs400(): void {
        $courses = $this->createMock(CourseService::class);
        $courses->method('assertInstructorOfCourse')->willReturn(new Course());
        $layouts = $this->createMock(CertLayoutService::class);
        $layouts->method('importImage')->willReturnOnConsecutiveCalls(
            $this->throwException(new NotFoundException('x')),
            $this->throwException(new \InvalidArgumentException('not_png')),
        );
        $controller = $this->layoutController($courses, $layouts);

        $this->assertSame(404, $controller->importImage(7, 'stamp', '/nope.png')->getStatus());
        $bad = $controller->importImage(7, 'stamp', '/x.png');
        $this->assertSame(400, $bad->getStatus());
        $this->assertSame('not_png', $bad->getData()['error']);
    }

    public function testCertificateShowCarriesItsPresentation(): void {
        $vid = '11111111-1111-4111-8111-111111111111';
        $cert = new Certificate();
        $cert->setVerificationId($vid);
        $cert->setUserId('alice');
        $cert->setCourseId(7);
        $cert->setKeyId('k');
        $cert->setCredentialJson('a.b.c');
        $cert->setRevoked(false);
        $cert->setIssuedAt(1);
        $mapper = $this->createMock(CertificateMapper::class);
        $mapper->method('findByVerificationIdAndUserId')->willReturn($cert);
        $layouts = $this->createMock(CertLayoutService::class);
        $layouts->expects($this->once())->method('presentationForCertificate')->with($cert)
            ->willReturn(['intro' => 'x', 'stamp' => 'data:image/png;base64,QUJD']);
        $controller = new CertificateController(
            'learning',
            $this->createMock(IRequest::class),
            $mapper,
            $this->createMock(CourseService::class),
            $this->createMock(ITimeFactory::class),
            'alice',
            $this->createMock(\OCA\Learning\Service\AuditService::class),
            new \OCA\Learning\Tests\Support\FakeDbConnection(),
            $layouts,
        );

        $data = $controller->show($vid)->getData();

        $this->assertSame('data:image/png;base64,QUJD', $data['presentation']['stamp']);
        $this->assertArrayNotHasKey('user_id', $data);
    }
}
