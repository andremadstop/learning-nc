<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\CourseVideoController;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseDocument;
use OCA\Learning\Db\CourseDocumentMapper;
use OCA\Learning\Db\CourseVideo;
use OCA\Learning\Db\CourseVideoMapper;
use OCA\Learning\Db\VideoProgressMapper;
use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\VideoProgressService;
use OCA\Learning\Service\ComplianceEventTypes;
use OCA\Learning\Service\RequiredDocumentService;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Db\DocConfirmMapper;
use OCA\Learning\Service\DocumentService;
use OCA\Learning\Service\PassCriteriaService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCP\AppFramework\Db\DoesNotExistException;
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
    /** @var CourseDocumentMapper&\PHPUnit\Framework\MockObject\MockObject */
    private $documentMapper;
    /** @var array<int, CourseDocument> material documents by id */
    private array $documents = [];
    /** @var AuditService&\PHPUnit\Framework\MockObject\MockObject */
    private $audit;
    private FakeDbConnection $db;

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
        $this->documents = [
            31 => $this->document(31, 5, '/Kurs/policy.pdf', 'policy.pdf'),
            // another course's material
            32 => $this->document(32, 6, '/Kurs/policy.pdf', 'policy.pdf'),
            // registered from an earlier material folder: no longer in the current one
            33 => $this->document(33, 5, '/Alt/old.pdf', 'old.pdf'),
        ];
        $this->documentMapper = $this->createMock(CourseDocumentMapper::class);
        $this->documentMapper->method('findById')->willReturnCallback(function (int $id): CourseDocument {
            if (!isset($this->documents[$id])) {
                throw new DoesNotExistException('no document');
            }
            return $this->documents[$id];
        });
        $this->videoMapper = $this->createMock(CourseVideoMapper::class);
        $this->audit = $this->createMock(AuditService::class);
        $this->db = new FakeDbConnection();
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
        $course->setMaterialFolder('/Kurs');
        return $course;
    }

    private function document(int $id, int $courseId, string $path, string $name): CourseDocument {
        $doc = new CourseDocument();
        $doc->setId($id);
        $doc->setCourseId($courseId);
        $doc->setFilePath($path);
        $doc->setFileName($name);
        return $doc;
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
            $this->documentMapper,
            new RequiredDocumentService(
                $this->createMock(DocConfirmMapper::class),
                $this->videoMapper,
                $this->createMock(CourseMapper::class),
                $this->createMock(DocumentService::class),
                $this->createMock(PassCriteriaService::class),
                $this->audit,
                $this->createMock(ITimeFactory::class),
                $this->db,
            ),
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
        // Documents are exempt from the duration: validation passes, authz runs, insert persists.
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course());
        $this->videoMapper->expects($this->once())->method('insert')->willReturnArgument(0);

        $resp = $this->makeController()->create(5, 'document', '31', 'Policy', null);

        $this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
        $this->assertSame('31', $resp->getData()->getVideoRef());
    }

    // ---- GitHub #35 (B4): a document row names a document of this course's material folder ----

    /** @return array<string, array{0: string}> */
    public static function refusedDocumentRefs(): array {
        return [
            'a free path in the home' => ['Documents/payslip.pdf'],
            'an absolute path' => ['/Documents/payslip.pdf'],
            'path traversal out of the material folder' => ['/Kurs/../Documents/payslip.pdf'],
            'traversal only' => ['../../etc/passwd'],
            'another course\'s document' => ['32'],
            'a document of an earlier material folder' => ['33'],
            'an unknown document id' => ['999'],
            'zero' => ['0'],
            'a negative id' => ['-31'],
            'an id with a path tail' => ['31/../32'],
            'empty' => [''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedDocumentRefs')]
    public function testCreateRefusesADocumentOutsideTheMaterialFolder(string $ref): void {
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course());
        $this->videoMapper->expects($this->never())->method('insert');

        $resp = $this->makeController()->create(5, 'document', $ref, 'Policy', null);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
        $this->assertSame([], $this->openedHomes, 'no home is opened for a document row');
    }

    public function testACoInstructorCanAddAMaterialDocument(): void {
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course('owner-uid'));
        $this->videoMapper->expects($this->once())->method('insert')->willReturnArgument(0);

        $resp = $this->makeController('co-instructor')->create(5, 'document', ' 31 ', 'Policy', null);

        $this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
        $this->assertSame('31', $resp->getData()->getVideoRef());
    }

    /** @return array<string, array{0: string}> */
    public static function unknownSourceTypes(): array {
        return [['Document'], ['pdf'], ['nc-file'], ['']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unknownSourceTypes')]
    public function testCreateRefusesAnUnknownSourceType(string $type): void {
        $this->courseService->expects($this->never())->method('assertInstructorOfCourse');
        $this->videoMapper->expects($this->never())->method('insert');

        $resp = $this->makeController()->create(5, $type, 'Documents/payslip.pdf', 'x', null);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
    }

    public function testUpdateRefusesRepointingADocumentToAPath(): void {
        $this->videoMapper->method('findById')->willReturn($this->existingRow('document', '31'));
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course());
        $this->videoMapper->expects($this->never())->method('update');

        $resp = $this->makeController()->update(9, null, '/Kurs/../Documents/payslip.pdf');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
    }

    public function testUpdateRefusesTurningAnEmbedIntoADocumentWithItsOldRef(): void {
        $this->videoMapper->method('findById')->willReturn($this->existingRow('vimeo', '76979871'));
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course());
        $this->videoMapper->expects($this->never())->method('update');

        $resp = $this->makeController()->update(9, 'document');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
    }

    public function testUpdateRefusesAnUnknownSourceType(): void {
        $this->videoMapper->method('findById')->willReturn($this->existingRow('document', '31'));
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course());
        $this->videoMapper->expects($this->never())->method('update');

        $resp = $this->makeController()->update(9, 'pdf');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
    }

    /**
     * GitHub #75 (review): a document row keeps its document. Pointing it at another one handed
     * the confirmations of the first document to the second, without a trace in the compliance
     * chain; that goes through the "Required" column now (optional, then tick the other).
     */
    public function testUpdateRefusesPointingADocumentRowAtAnotherDocument(): void {
        $this->documents[34] = $this->document(34, 5, '/Kurs/rules.md', 'rules.md');
        $this->videoMapper->method('findById')->willReturn($this->existingRow('document', '31'));
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course());
        $this->videoMapper->expects($this->never())->method('update');

        $resp = $this->makeController()->update(9, null, '34');

        $this->assertSame(Http::STATUS_CONFLICT, $resp->getStatus());
        $this->assertSame('document_row_fixed', $resp->getData()['code']);
    }

    public function testUpdateRefusesTurningADocumentRowIntoAVideo(): void {
        $this->videoMapper->method('findById')->willReturn($this->existingRow('document', '31'));
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course());
        $this->videoMapper->expects($this->never())->method('update');

        $resp = $this->makeController()->update(9, 'vimeo', '76979871', null, 120);

        $this->assertSame(Http::STATUS_CONFLICT, $resp->getStatus());
    }

    public function testUpdateStillRenamesADocumentRow(): void {
        $this->videoMapper->method('findById')->willReturn($this->existingRow('document', '31'));
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course());
        $this->videoMapper->expects($this->once())->method('update')->willReturnArgument(0);

        $resp = $this->makeController()->update(9, null, ' 31 ', 'Policy 2026');

        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $this->assertSame('Policy 2026', $resp->getData()->getTitle());
    }

    /**
     * GitHub #75 (review): DELETE /api/course-videos/{id} on a required document ends the count
     * of its confirmations; it is recorded like unticking it in the "Required" column.
     */
    public function testDeletingADocumentRowIsRecordedInTheComplianceChain(): void {
        $this->videoMapper->method('findById')->willReturn($this->requiredRow(8, 31));
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course());
        $this->videoMapper->expects($this->once())->method('delete');
        $this->audit->expects($this->once())->method('logComplianceEvent')->with(
            ComplianceEventTypes::CONTENT_REQUIRED_CHANGED,
            'instructor-uid',
            ['course_id' => 5, 'required' => false, 'document_ids' => [31], 'content_ids' => [8]]
        );

        $this->assertSame(Http::STATUS_NO_CONTENT, $this->makeController()->destroy(8)->getStatus());
    }

    public function testDeletingAVideoRowWritesNoComplianceEvent(): void {
        $this->videoMapper->method('findById')->willReturn($this->existingRow('vimeo', '76979871'));
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course());
        $this->videoMapper->expects($this->once())->method('delete');
        $this->audit->expects($this->never())->method('logComplianceEvent');

        $this->assertSame(Http::STATUS_NO_CONTENT, $this->makeController()->destroy(8)->getStatus());
    }

    /** POST .../videos with a document: recorded like ticking it in the "Required" column. */
    public function testAddingADocumentRowIsRecordedInTheComplianceChain(): void {
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course());
        $this->videoMapper->method('findDocumentRowsByCourse')->willReturn([]);
        $this->videoMapper->method('insert')->willReturnCallback(static function (CourseVideo $v): CourseVideo {
            $v->setId(12);
            return $v;
        });
        $this->audit->expects($this->once())->method('logComplianceEvent')->with(
            ComplianceEventTypes::CONTENT_REQUIRED_CHANGED,
            'instructor-uid',
            ['course_id' => 5, 'required' => true, 'document_ids' => [31], 'content_ids' => [12]]
        );

        $resp = $this->makeController()->create(5, 'document', '31', 'Policy', null);

        $this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
        $this->assertSame(12, $resp->getData()->getId());
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

    /** @return array<string, array{string, string}> */
    public static function invalidEmbedIds(): array {
        return [
            'youtube short url' => ['youtube-nocookie', 'https://youtu.be/dQw4w9WgXcQ'],
            'youtube watch url' => ['youtube-nocookie', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
            'youtube path' => ['youtube-nocookie', 'dQw4w9WgXc/'],
            'youtube query' => ['youtube-nocookie', 'dQw4w9W?gXc'],
            'youtube trailing newline' => ['youtube-nocookie', "dQw4w9WgXcQ\n"],
            'youtube too short' => ['youtube-nocookie', 'dQw4w9WgXc'],
            'vimeo url' => ['vimeo', 'https://vimeo.com/76979871'],
            'vimeo letters' => ['vimeo', '7697a871'],
            'vimeo too long' => ['vimeo', '1234567890123'],
            'empty' => ['vimeo', ''],
        ];
    }

    /**
     * GitHub #35 (B4): the players put the ref into /embed/{id} and /video/{id}. A whole URL loads
     * no player, the video never completes and a gated exam stays locked for every learner.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidEmbedIds')]
    public function testCreateRefusesAnEmbedRefThatIsNoProviderId(string $type, string $ref): void {
        $this->videoMapper->expects($this->never())->method('insert');

        $resp = $this->makeController()->create(5, $type, $ref, 'Embed', 300);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
    }

    public function testCreateAcceptsValidProviderIds(): void {
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course());
        $this->videoMapper->expects($this->exactly(2))->method('insert')->willReturnArgument(0);

        $this->assertSame(Http::STATUS_CREATED, $this->makeController()->create(5, 'youtube-nocookie', 'a-B_c1D2e3F', 'Embed', 300)->getStatus());
        $this->assertSame(Http::STATUS_CREATED, $this->makeController()->create(5, 'vimeo', '76979871', 'Embed', 300)->getStatus());
    }

    public function testUpdateRefusesRepointingAnEmbedToAUrl(): void {
        $this->videoMapper->method('findById')->willReturn($this->existingRow('youtube-nocookie', 'dQw4w9WgXcQ'));
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course());
        $this->videoMapper->expects($this->never())->method('update');

        $resp = $this->makeController()->update(9, null, 'https://youtu.be/dQw4w9WgXcQ');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
    }

    public function testUpdateRefusesTurningAnNcFilesRowIntoAnEmbedWithItsPath(): void {
        $this->videoMapper->method('findById')->willReturn($this->existingRow('nc-files', 'Videos/intro.mp4'));
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course());
        $this->videoMapper->expects($this->never())->method('update');

        $resp = $this->makeController()->update(9, 'vimeo');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
    }

    /** An existing row with an old, non-conforming ref can still be renamed: only a new target is checked. */
    public function testUpdateKeepsRenamingAnOldEmbedRow(): void {
        $this->videoMapper->method('findById')->willReturn($this->existingRow('vimeo', 'https://vimeo.com/76979871'));
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course());
        $this->videoMapper->expects($this->once())->method('update')->willReturnArgument(0);

        $resp = $this->makeController()->update(9, null, null, 'Neuer Titel');

        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
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

    // ---- GitHub #75: a document is required once per course ----------------------------------

    private function requiredRow(int $id, int $documentId): CourseVideo {
        $row = new CourseVideo();
        $row->setId($id);
        $row->setCourseId(5);
        $row->setSourceType('document');
        $row->setVideoRef((string)$documentId);
        $row->setDocumentId($documentId);
        return $row;
    }

    public function testCreateRefusesADocumentThatIsRequiredAlready(): void {
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course());
        $this->videoMapper->method('findDocumentRowsByCourse')->willReturn([31 => $this->requiredRow(8, 31)]);
        $this->videoMapper->expects($this->never())->method('insert');

        $resp = $this->makeController()->create(5, 'document', '31', 'Policy', null);

        $this->assertSame(Http::STATUS_CONFLICT, $resp->getStatus());
    }

    public function testCreateAnswersConflictWhenAConcurrentRequestAddedItFirst(): void {
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course());
        $this->videoMapper->method('findDocumentRowsByCourse')->willReturn([]);
        $this->videoMapper->method('insert')->willThrowException(
            new \OCP\DB\Exception(\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION, 'learn_cv_course_doc_uq')
        );

        $resp = $this->makeController()->create(5, 'document', '31', 'Policy', null);

        $this->assertSame(Http::STATUS_CONFLICT, $resp->getStatus());
    }

    public function testCreateStoresTheDocumentId(): void {
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course());
        $this->videoMapper->method('findDocumentRowsByCourse')->willReturn([]);
        $this->videoMapper->expects($this->once())->method('insert')->willReturnArgument(0);

        $resp = $this->makeController()->create(5, 'document', '31', 'Policy', null);

        $this->assertSame(31, $resp->getData()->getDocumentId());
    }

    public function testTickingRequiredAddsOnlyDocumentsThatAreNotRequiredYet(): void {
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course());
        $this->documents[34] = $this->document(34, 5, '/Kurs/handbook.pdf', 'handbook.pdf');
        $this->videoMapper->method('findDocumentRowsByCourse')->willReturn([31 => $this->requiredRow(8, 31)]);
        $this->videoMapper->method('findByCourse')->willReturn([$this->requiredRow(8, 31)]);
        $inserted = [];
        $this->videoMapper->method('insert')->willReturnCallback(function (CourseVideo $v) use (&$inserted) {
            $inserted[] = $v;
            return $v;
        });

        $resp = $this->makeController()->setDocumentsRequired(5, [31, 34], true);

        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
        $this->assertCount(1, $inserted, 'the document required already is not added again');
        $this->assertSame(34, $inserted[0]->getDocumentId());
        $this->assertSame('handbook.pdf', $inserted[0]->getTitle());
    }

    public function testTickingRequiredRefusesAForeignDocumentAndWritesNothing(): void {
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course());
        $this->videoMapper->method('findDocumentRowsByCourse')->willReturn([]);
        $this->videoMapper->method('findByCourse')->willReturn([]);
        $this->videoMapper->expects($this->never())->method('insert');

        // 32 belongs to another course
        $resp = $this->makeController()->setDocumentsRequired(5, [31, 32], true);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
    }

    public function testUntickingRemovesTheRegistryRow(): void {
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course());
        $row = $this->requiredRow(8, 31);
        $this->videoMapper->method('findDocumentRowsByCourse')->willReturn([31 => $row]);
        $this->videoMapper->method('findByCourse')->willReturn([]);
        $this->videoMapper->expects($this->once())->method('delete')->with($row);

        $resp = $this->makeController()->setDocumentsRequired(5, [31, 34], false);

        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
    }

    /**
     * GitHub #75 (review): making a document optional ends the count of its confirmations, so
     * the change is on record in the compliance chain with the instructor as actor.
     */
    public function testUntickingIsRecordedInTheComplianceChain(): void {
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course());
        $this->videoMapper->method('findDocumentRowsByCourse')->willReturn([31 => $this->requiredRow(8, 31)]);
        $this->videoMapper->method('findByCourse')->willReturn([]);
        $this->audit->expects($this->once())->method('logComplianceEvent')->with(
            ComplianceEventTypes::CONTENT_REQUIRED_CHANGED,
            'instructor-uid',
            ['course_id' => 5, 'required' => false, 'document_ids' => [31], 'content_ids' => [8]]
        );

        $resp = $this->makeController()->setDocumentsRequired(5, [31, 34], false);

        $this->assertSame(Http::STATUS_OK, $resp->getStatus());
    }

    /** A row from before 5.14 knows its document only by video_ref: the chain names it anyway. */
    public function testUntickingALegacyRowRecordsItsDocument(): void {
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course());
        $legacy = $this->requiredRow(9, 34);
        $legacy->setDocumentId(null);
        $this->videoMapper->method('findDocumentRowsByCourse')->willReturn([34 => $legacy]);
        $this->videoMapper->method('findByCourse')->willReturn([]);
        $this->audit->expects($this->once())->method('logComplianceEvent')->with(
            ComplianceEventTypes::CONTENT_REQUIRED_CHANGED,
            'instructor-uid',
            ['course_id' => 5, 'required' => false, 'document_ids' => [34], 'content_ids' => [9]]
        );

        $this->assertSame(Http::STATUS_OK, $this->makeController()->setDocumentsRequired(5, [34], false)->getStatus());
    }

    public function testTickingIsRecordedWithTheNewRows(): void {
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course());
        $this->videoMapper->method('findDocumentRowsByCourse')->willReturn([]);
        $this->videoMapper->method('findByCourse')->willReturn([]);
        $this->videoMapper->method('insert')->willReturnCallback(static function (CourseVideo $v): CourseVideo {
            $v->setId(12);
            return $v;
        });
        $this->audit->expects($this->once())->method('logComplianceEvent')->with(
            ComplianceEventTypes::CONTENT_REQUIRED_CHANGED,
            'instructor-uid',
            ['course_id' => 5, 'required' => true, 'document_ids' => [31], 'content_ids' => [12]]
        );

        $this->assertSame(Http::STATUS_OK, $this->makeController()->setDocumentsRequired(5, [31], true)->getStatus());
    }

    public function testNothingChangedWritesNoEvent(): void {
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course());
        $this->videoMapper->method('findDocumentRowsByCourse')->willReturn([31 => $this->requiredRow(8, 31)]);
        $this->videoMapper->method('findByCourse')->willReturn([]);
        $this->audit->expects($this->never())->method('logComplianceEvent');

        $this->assertSame(Http::STATUS_OK, $this->makeController()->setDocumentsRequired(5, [31], true)->getStatus());
    }

    /** No audit, no change: a failing compliance append undoes the removal (500). */
    public function testAFailedAuditAppendRollsTheChangeBack(): void {
        $this->courseService->method('assertInstructorOfCourse')->willReturn($this->course());
        $this->videoMapper->method('findDocumentRowsByCourse')->willReturn([31 => $this->requiredRow(8, 31)]);
        $this->audit->method('logComplianceEvent')->willThrowException(new \RuntimeException('chain'));

        $resp = $this->makeController()->setDocumentsRequired(5, [31], false);

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $resp->getStatus());
        $this->assertSame(1, $this->db->rollBackCalls);
        $this->assertSame(0, $this->db->commitCalls);
    }

    public function testRequiredColumnIsForInstructorsOnly(): void {
        $this->courseService->method('assertInstructorOfCourse')->willThrowException(new \OCA\Learning\Service\ForbiddenException('no'));
        $this->videoMapper->expects($this->never())->method('insert');

        $resp = $this->makeController('student')->setDocumentsRequired(5, [31], true);

        $this->assertSame(Http::STATUS_FORBIDDEN, $resp->getStatus());
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
            $this->documentMapper,
        );
        $resp = $controller->create(5, 'nc-files', 'Videos/intro.mp4', 'Intro', 120);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $resp->getStatus());
    }
}
