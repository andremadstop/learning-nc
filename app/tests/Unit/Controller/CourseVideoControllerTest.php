<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\CourseVideoController;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseVideo;
use OCA\Learning\Db\CourseVideoMapper;
use OCA\Learning\Db\VideoProgressMapper;
use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\VideoProgressService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

/**
 * Phase 162 Plan 02 — CourseVideoController registry-CRUD duration gate (VIDEO-09).
 *
 * The one non-obvious, security-relevant branch: a gated VIDEO source (nc-files/vimeo/
 * youtube-nocookie) saved with a null/zero/negative duration_seconds must be REJECTED with 400
 * BEFORE any DB write — otherwise the 95% coverage gate can never be met and would stay silently
 * open. Documents are exempt. We build a REAL VideoProgressService (its isDurationValidForGate is a
 * pure, DB-free method) over mocked deps so the real validation runs, and assert the mapper is never
 * touched on the reject path (authz + insert are downstream of the guard).
 *
 */
#[Group('video-09')]
#[AllowMockObjectsWithoutExpectations]
class CourseVideoControllerTest extends TestCase {
    /** @var CourseVideoMapper&\PHPUnit\Framework\MockObject\MockObject */
    private $videoMapper;
    /** @var CourseService&\PHPUnit\Framework\MockObject\MockObject */
    private $courseService;
    private VideoProgressService $videoProgressService;
    /** @var IRootFolder&\PHPUnit\Framework\MockObject\MockObject */
    private $rootFolder;
    /** @var array<string, string> path => MIME type in the owner's home */
    private array $ownerFiles = ['Videos/intro.mp4' => 'video/mp4', 'Documents/payslip.pdf' => 'application/pdf'];
    /** owners whose home was opened */
    private array $openedHomes = [];

    protected function setUp(): void {
        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->rootFolder->method('getUserFolder')->willReturnCallback(function (string $uid): Folder {
            $this->openedHomes[] = $uid;
            $folder = $this->createMock(Folder::class);
            $folder->method('get')->willReturnCallback(function (string $path): File {
                if (!isset($this->ownerFiles[$path])) {
                    throw new NotFoundException($path);
                }
                $file = $this->createMock(File::class);
                $file->method('getMimeType')->willReturn($this->ownerFiles[$path]);
                return $file;
            });
            return $folder;
        });
        $this->videoMapper = $this->createMock(CourseVideoMapper::class);
        $this->courseService = $this->createMock(CourseService::class);
        // Real service so the pure isDurationValidForGate logic runs; its deps are never called by it.
        $this->videoProgressService = new VideoProgressService(
            $this->createMock(VideoProgressMapper::class),
            $this->createMock(CourseVideoMapper::class),
            $this->createMock(ITimeFactory::class),
            $this->createMock(AuditService::class),
            $this->createMock(IDBConnection::class),
        );
    }

    private function course(string $owner = 'instructor-uid'): Course {
        $course = new Course();
        $course->setId(5);
        $course->setInstructorId($owner);
        return $course;
    }

    private function makeController(string $uid = 'instructor-uid'): CourseVideoController {
        return new CourseVideoController(
            'learning',
            $this->createMock(IRequest::class),
            $this->videoMapper,
            $this->courseService,
            $this->videoProgressService,
            $this->rootFolder,
            $this->createMock(LoggerInterface::class),
            $uid,
        );
    }

    public function testCreateRejectsNcFilesVideoWithNullDuration(): void {
        // Reject path must short-circuit BEFORE authz and BEFORE any insert.
        $this->courseService->expects($this->never())->method('assertInstructorOfCourse');
        $this->videoMapper->expects($this->never())->method('insert');

        $resp = $this->makeController()->create(5, 'nc-files', 'Videos/intro.mp4', 'Intro', null);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
    }

    public function testCreateRejectsNcFilesVideoWithZeroDuration(): void {
        $this->videoMapper->expects($this->never())->method('insert');
        $resp = $this->makeController()->create(5, 'youtube-nocookie', 'dQw4w9WgXcQ', 'Embed', 0);
        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
    }

    public function testCreateAllowsDocumentWithNullDuration(): void {
        // Documents are exempt: validation passes, authz runs, insert persists.
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->createMock(Course::class));
        $this->videoMapper->expects($this->once())->method('insert')->willReturnArgument(0);

        $resp = $this->makeController()->create(5, 'document', 'Docs/policy.pdf', 'Policy', null);

        $this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
    }

    public function testCreateAllowsNcFilesVideoWithPositiveDuration(): void {
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course());
        $this->videoMapper->expects($this->once())->method('insert')->willReturnArgument(0);

        $resp = $this->makeController()->create(5, 'nc-files', 'Videos/intro.mp4', 'Intro', 120);

        $this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
        /** @var CourseVideo $entity */
        $entity = $resp->getData();
        $this->assertSame(120, $entity->getDurationSeconds());
        $this->assertSame('nc-files', $entity->getSourceType());
    }

    public function testUpdateRejectsSettingGatedVideoDurationToZero(): void {
        // Existing nc-files row; instructor tries to null out the gate by setting duration 0 → 400,
        // and the row is never persisted. Validates the RESULTING state (plan: "same duration validation").
        $existing = new CourseVideo();
        $existing->setCourseId(5);
        $existing->setSourceType('nc-files');
        $existing->setVideoRef('Videos/intro.mp4');
        $existing->setDurationSeconds(120);
        $this->videoMapper->method('findById')->willReturn($existing);
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course());
        $this->videoMapper->expects($this->never())->method('update');

        $resp = $this->makeController()->update(9, null, null, null, 0);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
    }

    // ---- audit #15: nc-files rows stream from the OWNER's home to every member ----------------

    public function testCoInstructorCannotAddAFileFromTheOwnersHome(): void {
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course('owner-uid'));
        $this->videoMapper->expects($this->never())->method('insert');

        $resp = $this->makeController('co-instructor')->create(5, 'nc-files', 'Documents/payslip.pdf', 'x', 1);

        $this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
        $this->assertSame([], $this->openedHomes, 'the owner\'s home is not even opened');
    }

    public function testOwnerCannotRegisterAFileThatIsNoVideo(): void {
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course());
        $this->videoMapper->expects($this->never())->method('insert');

        $resp = $this->makeController()->create(5, 'nc-files', 'Documents/payslip.pdf', 'x', 1);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
    }

    public function testOwnerCannotRegisterAMissingFile(): void {
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course());
        $this->videoMapper->expects($this->never())->method('insert');

        $resp = $this->makeController()->create(5, 'nc-files', 'Videos/gone.mp4', 'x', 60);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
    }

    public function testCoInstructorCanStillAddAnEmbed(): void {
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course('owner-uid'));
        $this->videoMapper->expects($this->once())->method('insert')->willReturnArgument(0);

        $resp = $this->makeController('co-instructor')->create(5, 'youtube-nocookie', 'dQw4w9WgXcQ', 'Embed', 60);

        $this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
    }

    private function existingRow(string $sourceType, string $ref): CourseVideo {
        $existing = new CourseVideo();
        $existing->setCourseId(5);
        $existing->setSourceType($sourceType);
        $existing->setVideoRef($ref);
        $existing->setDurationSeconds(120);
        return $existing;
    }

    public function testCoInstructorCannotRepointAnNcFilesRow(): void {
        $this->videoMapper->method('findById')->willReturn($this->existingRow('nc-files', 'Videos/intro.mp4'));
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course('owner-uid'));
        $this->videoMapper->expects($this->never())->method('update');

        $resp = $this->makeController('co-instructor')->update(9, null, 'Documents/payslip.pdf');

        $this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
    }

    public function testCoInstructorCannotTurnAnEmbedIntoAnNcFilesRow(): void {
        // The old ref, now read as a path in the owner's home.
        $this->videoMapper->method('findById')->willReturn($this->existingRow('vimeo', 'Documents/payslip.pdf'));
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course('owner-uid'));
        $this->videoMapper->expects($this->never())->method('update');

        $resp = $this->makeController('co-instructor')->update(9, 'nc-files');

        $this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
    }

    public function testCoInstructorCanRenameAnNcFilesRow(): void {
        $this->videoMapper->method('findById')->willReturn($this->existingRow('nc-files', 'Videos/intro.mp4'));
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course('owner-uid'));
        $this->videoMapper->expects($this->once())->method('update')->willReturnArgument(0);

        $resp = $this->makeController('co-instructor')->update(9, null, null, 'New title');

        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
    }

    public function testOwnerCanRepointToAnotherVideo(): void {
        $this->ownerFiles['Videos/part2.mp4'] = 'video/webm';
        $this->videoMapper->method('findById')->willReturn($this->existingRow('nc-files', 'Videos/intro.mp4'));
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course());
        $this->videoMapper->expects($this->once())->method('update')->willReturnArgument(0);

        $resp = $this->makeController()->update(9, null, 'Videos/part2.mp4');

        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $this->assertSame(['instructor-uid'], $this->openedHomes);
    }

    public function testCreateRejectsWhenUnauthenticated(): void {
        $controller = new CourseVideoController(
            'learning',
            $this->createMock(IRequest::class),
            $this->videoMapper,
            $this->courseService,
            $this->videoProgressService,
            $this->rootFolder,
            $this->createMock(LoggerInterface::class),
            null,
        );
        $resp = $controller->create(5, 'nc-files', 'Videos/intro.mp4', 'Intro', 120);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $resp->getStatus());
    }
}
