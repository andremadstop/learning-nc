<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\DocumentController;
use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseDocument;
use OCA\Learning\Db\CourseDocumentMapper;
use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\DocumentService;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\RoleService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\ISharedStorage;
use OCP\Files\Storage\IStorage;
use OCP\IRequest;
use OCP\Share\IAttributes;
use OCP\Share\IShare;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #35 — course materials.
 *
 * 1. GET /documents handed every course member the raw rows, extracted text included: a learner
 *    received the full text of every extracted PDF, plus paths and errors from the instructor's
 *    private home. The list now carries no extracted text at all and the processing details only
 *    for instructors of THIS course (a global instructor who is a student here gets none).
 * 2. Learners saw file names but could not open them. GET /documents/{documentId}/download serves
 *    the file to course members only, and only a direct child of the course's current material
 *    folder, resolved by its plain name — never a stored path that points elsewhere.
 *
 * A real DocumentService runs over a mocked mapper and file tree, so the checks under test are the
 * production ones.
 */
#[AllowMockObjectsWithoutExpectations]
class DocumentMaterialAccessTest extends TestCase {
    private const COURSE = 7;
    private const OWNER = 'teacher';

    /** @var CourseDocumentMapper&MockObject */
    private $documentMapper;
    /** @var CourseMapper&MockObject */
    private $courseMapper;
    /** @var IRootFolder&MockObject */
    private $rootFolder;
    /** @var CourseService&MockObject */
    private $courseService;
    /** @var RoleService&MockObject */
    private $roleService;
    private Course $course;
    /** @var array<string, File|Folder> children of the material folder by name */
    private array $children = [];
    /** @var array<string, File> other files in the owner's home, by path */
    private array $homeFiles = [];
    /** @var list<string> names requested from the material folder */
    private array $requestedNames = [];
    private bool $pdfAvailable = true;

    protected function setUp(): void {
        $this->course = new Course();
        $this->course->setId(self::COURSE);
        $this->course->setInstructorId(self::OWNER);
        $this->course->setMaterialFolder('/Kurs/Material');

        $this->documentMapper = $this->createMock(CourseDocumentMapper::class);
        $this->courseMapper = $this->createMock(CourseMapper::class);
        $this->courseMapper->method('findById')->willReturnCallback(fn (int $id) => $this->course);
        $this->courseService = $this->createMock(CourseService::class);
        $this->roleService = $this->createMock(RoleService::class);

        $materialFolder = $this->createMock(Folder::class);
        $materialFolder->method('getType')->willReturn(\OCP\Files\Node::TYPE_FOLDER);
        $materialFolder->method('get')->willReturnCallback(function (string $name) {
            $this->requestedNames[] = $name;
            if (!isset($this->children[$name])) {
                throw new NotFoundException($name);
            }
            return $this->children[$name];
        });
        // The owner's home: the material folder, plus any other file of the owner. A resolver that
        // trusted the stored path would find those (and so turn these tests red).
        $home = $this->createMock(Folder::class);
        $home->method('get')->willReturnCallback(function (string $path) use ($materialFolder) {
            if ($path === $this->course->getMaterialFolder()) {
                return $materialFolder;
            }
            $prefix = $this->course->getMaterialFolder() . '/';
            if (str_starts_with($path, $prefix) && isset($this->children[substr($path, strlen($prefix))])) {
                return $this->children[substr($path, strlen($prefix))];
            }
            if (isset($this->homeFiles[$path])) {
                return $this->homeFiles[$path];
            }
            throw new NotFoundException($path);
        });
        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->rootFolder->method('getUserFolder')->willReturnCallback(function (string $uid) use ($home) {
            if ($uid !== self::OWNER) {
                throw new \RuntimeException('files are only read from the course owner\'s home');
            }
            return $home;
        });
    }

    private function service(): DocumentService {
        $test = $this;
        return new class($this->documentMapper, $this->courseMapper, $this->rootFolder, $this->createMock(LoggerInterface::class), $test) extends DocumentService {
            public function __construct(CourseDocumentMapper $m, CourseMapper $c, IRootFolder $r, LoggerInterface $l, private DocumentMaterialAccessTest $test) {
                parent::__construct($m, $c, $r, $l);
            }

            public function isPdfTextExtractionAvailable(): bool {
                return $this->test->pdfAvailable();
            }
        };
    }

    public function pdfAvailable(): bool {
        return $this->pdfAvailable;
    }

    private function controller(string $uid): DocumentController {
        return new DocumentController('learning', $this->createMock(IRequest::class), $this->service(),
            $this->courseService, $this->roleService, $this->createMock(LoggerInterface::class), $uid);
    }

    private static function doc(int $id, string $name, string $type = 'pdf', ?string $path = null, int $courseId = self::COURSE): CourseDocument {
        $doc = new CourseDocument();
        $doc->setId($id);
        $doc->setCourseId($courseId);
        $doc->setFileName($name);
        $doc->setFilePath($path ?? '/Kurs/Material/' . $name);
        $doc->setFileType($type);
        $doc->setStatus('extracted');
        $doc->setExtractedText('SECRET FULL TEXT OF THE PDF');
        $doc->setErrorMessage('File not found: /Kurs/Material/private-notes.pdf');
        $doc->setChunkingStatus('done');
        $doc->setFileSize(12);
        $doc->setCreatedAt(1700000000);
        $doc->setUpdatedAt(1700000001);
        return $doc;
    }

    private function enrolled(string $uid): void {
        $this->courseService->method('assertEnrolledInCourse')->willReturnCallback(
            function (int $courseId, string $user) use ($uid) {
                if ($courseId !== self::COURSE) {
                    throw new DoesNotExistException('course');
                }
                if ($user !== $uid) {
                    throw new ForbiddenException('not enrolled');
                }
                return $this->course;
            });
    }

    private function fileNode(string $content, ?IStorage $storage = null, bool $readable = true): File {
        if ($storage === null) {
            $storage = $this->createMock(IStorage::class);
            $storage->method('instanceOfStorage')->willReturn(false);
        }
        $file = $this->createMock(File::class);
        $file->method('getStorage')->willReturn($storage);
        $file->method('isReadable')->willReturn($readable);
        $file->method('fopen')->willReturnCallback(function () use ($content) {
            $h = fopen('php://memory', 'r+');
            fwrite($h, $content);
            rewind($h);
            return $h;
        });
        $file->method('getSize')->willReturn(strlen($content));
        // must never decide the Content-Type
        $file->method('getMimeType')->willReturn('text/html');
        return $file;
    }

    private function body(Response $response): string {
        $this->assertInstanceOf(ICallbackResponse::class, $response);
        $buffer = '';
        $out = $this->createMock(IOutput::class);
        $out->method('setOutput')->willReturnCallback(function ($chunk) use (&$buffer) {
            $buffer .= $chunk;
        });
        $response->callback($out);
        return $buffer;
    }

    // ---------------------------------------------------------------- list (leak) -------------

    public function testLearnerListHasNoExtractedTextAndNoProcessingDetails(): void {
        $this->enrolled('student');
        $this->courseService->method('canManageCourse')->willReturn(false);
        $this->documentMapper->expects($this->atLeastOnce())->method('findByCourseId')->with(self::COURSE)->willReturn([self::doc(1, 'skript.pdf')]);

        $response = $this->controller('student')->index(self::COURSE);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertFalse($data['can_manage_files']);
        $row = $data['documents'][0];
        $this->assertSame(['id', 'file_name', 'file_type', 'file_size', 'created_at', 'updated_at', 'downloadable'], array_keys($row));
        $this->assertTrue($row['downloadable']);
        $this->assertStringNotContainsString('SECRET', json_encode($data));
        $this->assertStringNotContainsString('private-notes', json_encode($data));
    }

    /** The global instructor role is no right in a course where the user is a student. */
    public function testGlobalInstructorWhoIsAStudentHereGetsTheLearnerList(): void {
        $this->enrolled('colleague');
        $this->roleService->method('isInstructor')->willReturn(true);
        $this->courseService->expects($this->once())->method('canManageCourse')->with(self::COURSE, 'colleague')->willReturn(false);
        $this->documentMapper->method('findByCourseId')->willReturn([self::doc(1, 'skript.pdf')]);

        $data = $this->controller('colleague')->index(self::COURSE)->getData();

        $this->assertArrayNotHasKey('status', $data['documents'][0]);
        $this->assertArrayNotHasKey('file_path', $data['documents'][0]);
        $this->assertStringNotContainsString('SECRET', json_encode($data));
    }

    public function testCourseInstructorSeesStatusButNeverTheExtractedText(): void {
        $this->enrolled(self::OWNER);
        $this->courseService->method('canManageCourse')->willReturn(true);
        $this->documentMapper->method('findByCourseId')->willReturn([self::doc(1, 'skript.pdf')]);
        $this->pdfAvailable = false;

        $data = $this->controller(self::OWNER)->index(self::COURSE)->getData();

        $row = $data['documents'][0];
        $this->assertSame('extracted', $row['status']);
        $this->assertSame('done', $row['chunking_status']);
        $this->assertArrayHasKey('error_message', $row);
        $this->assertArrayNotHasKey('extracted_text', $row);
        $this->assertTrue($data['can_manage_files'], 'the owner manages the folder');
        $this->assertFalse($data['pdf_text_extraction'], 'the page learns up front that PDFs cannot be read');
    }

    public function testNonMemberGetsNoList(): void {
        $this->enrolled('student');
        $this->documentMapper->expects($this->never())->method('findByCourseId');

        $response = $this->controller('stranger')->index(self::COURSE);

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    public function testRowFromAnEarlierFolderIsListedButNotDownloadable(): void {
        $this->enrolled('student');
        $this->courseService->method('canManageCourse')->willReturn(false);
        $this->documentMapper->method('findByCourseId')->willReturn([
            self::doc(1, 'alt.pdf', 'pdf', '/Alter/Ordner/alt.pdf'),
            self::doc(2, 'neu.md', 'markdown'),
        ]);

        $rows = $this->controller('student')->index(self::COURSE)->getData()['documents'];

        $this->assertFalse($rows[0]['downloadable']);
        $this->assertTrue($rows[1]['downloadable']);
    }

    // ---------------------------------------------------------------- download ---------------

    public function testMemberOpensAPdfInline(): void {
        $this->enrolled('student');
        $this->documentMapper->expects($this->once())->method('findById')->with(11)->willReturn(self::doc(11, 'Skript "Teil 1" ä.pdf'));
        $this->children['Skript "Teil 1" ä.pdf'] = $this->fileNode('%PDF-1.7 bytes');

        $response = $this->controller('student')->download(self::COURSE, 11);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $headers = $response->getHeaders();
        $this->assertSame('application/pdf', $headers['Content-Type']);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertStringStartsWith('inline; filename="Skript _Teil 1_ _.pdf"', $headers['Content-Disposition']);
        $this->assertStringContainsString("filename*=UTF-8''Skript%20%22Teil%201%22%20%C3%A4.pdf", $headers['Content-Disposition']);
        $this->assertSame('%PDF-1.7 bytes', $this->body($response));
        $this->assertSame(['Skript "Teil 1" ä.pdf'], $this->requestedNames, 'resolved by plain name inside the folder');
    }

    /** Markdown is served as plain text: an uploaded file must not become an HTML page here. */
    public function testMarkdownIsServedAsPlainTextNotByTheFileMimeType(): void {
        $this->enrolled('student');
        $this->documentMapper->method('findById')->willReturn(self::doc(12, 'notes.md', 'markdown'));
        $this->children['notes.md'] = $this->fileNode('# Hi <script>alert(1)</script>');

        $response = $this->controller('student')->download(self::COURSE, 12);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('text/plain; charset=utf-8', $response->getHeaders()['Content-Type']);
    }

    public function testNonMemberIsRefusedBeforeAnyFileIsTouched(): void {
        $this->enrolled('student');
        $this->documentMapper->expects($this->never())->method('findById');
        $this->rootFolder->expects($this->never())->method('getUserFolder');

        $response = $this->controller('stranger')->download(self::COURSE, 11);

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }

    /** IDOR: a document of another course through this course's URL. */
    public function testDocumentOfAnotherCourseIsNotFound(): void {
        $this->enrolled('student');
        $this->documentMapper->method('findById')->willReturn(self::doc(13, 'fremd.pdf', 'pdf', null, 99));
        $this->children['fremd.pdf'] = $this->fileNode('other course');

        $response = $this->controller('student')->download(self::COURSE, 13);

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame([], $this->requestedNames);
    }

    /** The folder was changed: the old row points outside the current material folder. */
    public function testFileOutsideTheCurrentMaterialFolderIsNotFound(): void {
        $this->enrolled('student');
        $this->documentMapper->method('findById')->willReturn(self::doc(14, 'gehalt.pdf', 'pdf', '/Privat/gehalt.pdf'));
        $this->homeFiles['/Privat/gehalt.pdf'] = $this->fileNode('salary');

        $response = $this->controller('student')->download(self::COURSE, 14);

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame([], $this->requestedNames, 'nothing is resolved for a row outside the folder');
    }

    public function testPathTraversalInTheFileNameIsNotFound(): void {
        $this->enrolled('student');
        $this->documentMapper->method('findById')->willReturn(self::doc(15, '../geheim.pdf'));
        $this->children['../geheim.pdf'] = $this->fileNode('secret');
        $this->homeFiles['/Kurs/Material/../geheim.pdf'] = $this->fileNode('secret');

        $response = $this->controller('student')->download(self::COURSE, 15);

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame([], $this->requestedNames);
    }

    public function testAFolderWithTheRegisteredNameIsNotServed(): void {
        $this->enrolled('student');
        $this->documentMapper->method('findById')->willReturn(self::doc(16, 'sub.pdf'));
        $this->children['sub.pdf'] = $this->createMock(Folder::class);

        $this->assertSame(Http::STATUS_NOT_FOUND, $this->controller('student')->download(self::COURSE, 16)->getStatus());
    }

    public function testDeletedFileAndUnsetFolderAreNotFound(): void {
        $this->enrolled('student');
        $this->documentMapper->method('findById')->willReturn(self::doc(17, 'weg.pdf'));

        $this->assertSame(Http::STATUS_NOT_FOUND, $this->controller('student')->download(self::COURSE, 17)->getStatus());

        $this->course->setMaterialFolder(null);
        $this->assertSame(Http::STATUS_NOT_FOUND, $this->controller('student')->download(self::COURSE, 17)->getStatus());
    }

    public function testUnknownDocumentAndUnknownCourseAreNotFound(): void {
        $this->enrolled('student');
        $this->documentMapper->method('findById')->willThrowException(new DoesNotExistException('doc'));

        $this->assertSame(Http::STATUS_NOT_FOUND, $this->controller('student')->download(self::COURSE, 404)->getStatus());
        $this->assertSame(Http::STATUS_NOT_FOUND, $this->controller('student')->download(12345, 1)->getStatus());
    }

    /** The route parameters are what the controller reads: course for the gate, document for the row. */
    public function testRouteParametersReachTheGateAndTheLookup(): void {
        $this->courseService->expects($this->once())->method('assertEnrolledInCourse')
            ->with(self::COURSE, 'student')->willReturn($this->course);
        $this->documentMapper->expects($this->once())->method('findById')->with(21)
            ->willReturn(self::doc(21, 'a.pdf'));
        $this->children['a.pdf'] = $this->fileNode('x');

        $this->assertSame(Http::STATUS_OK, $this->controller('student')->download(self::COURSE, 21)->getStatus());
    }

    public function testDownloadRouteIsRegisteredForGet(): void {
        $routes = require dirname(__DIR__, 3) . '/appinfo/routes.php';
        $match = array_values(array_filter($routes['routes'], static fn (array $r) => $r['name'] === 'document#download'));
        $this->assertCount(1, $match);
        $this->assertSame('/api/courses/{courseId}/documents/{documentId}/download', $match[0]['url']);
        $this->assertSame('GET', $match[0]['verb']);
    }

    /** A plain link carries no request token: the endpoint must be reachable without one. */
    public function testDownloadNeedsNoCsrfTokenAndNoAdmin(): void {
        $doc = (string)(new \ReflectionMethod(DocumentController::class, 'download'))->getDocComment();
        $this->assertStringContainsString('@NoCSRFRequired', $doc);
        $this->assertStringContainsString('@NoAdminRequired', $doc);
    }

    // ---------------------------------------------------------------- view-only shares -------

    /**
     * A storage of a share that was given to the course owner, with the "download" attribute.
     * $download null means the share has no attributes at all (download allowed).
     */
    private function sharedStorage(?bool $download): ISharedStorage {
        $attributes = null;
        if ($download !== null) {
            $attributes = $this->createMock(IAttributes::class);
            $attributes->method('getAttribute')->willReturnCallback(
                static fn (string $scope, string $key) => ($scope === 'permissions' && $key === 'download') ? $download : null);
        }
        $share = $this->createMock(IShare::class);
        $share->method('getAttributes')->willReturn($attributes);
        $storage = $this->createMock(ISharedStorage::class);
        $storage->method('instanceOfStorage')->willReturnCallback(static fn (string $class) => $class === ISharedStorage::class);
        $storage->method('getShare')->willReturn($share);
        return $storage;
    }

    /**
     * The owner linked a folder that was shared to them "view only": NC refuses the owner the
     * download in WebDAV, so the course members must not get the bytes through this endpoint.
     */
    public function testFileOfAViewOnlyShareIsNotServed(): void {
        $this->enrolled('student');
        $this->documentMapper->method('findById')->willReturn(self::doc(41, 'nur-ansehen.pdf'));
        $this->children['nur-ansehen.pdf'] = $this->fileNode('protected', $this->sharedStorage(false));

        $this->assertSame(Http::STATUS_NOT_FOUND, $this->controller('student')->download(self::COURSE, 41)->getStatus());
    }

    public function testFileOfAShareThatAllowsDownloadIsServed(): void {
        $this->enrolled('student');
        $this->documentMapper->method('findById')->willReturnCallback(
            fn (int $id) => self::doc($id, $id === 42 ? 'erlaubt.pdf' : 'ohne-attribute.pdf'));
        $this->children['erlaubt.pdf'] = $this->fileNode('ok', $this->sharedStorage(true));
        $this->children['ohne-attribute.pdf'] = $this->fileNode('ok', $this->sharedStorage(null));

        $this->assertSame(Http::STATUS_OK, $this->controller('student')->download(self::COURSE, 42)->getStatus());
        $this->assertSame(Http::STATUS_OK, $this->controller('student')->download(self::COURSE, 43)->getStatus());
    }

    public function testUnreadableFileIsNotServed(): void {
        $this->enrolled('student');
        $this->documentMapper->method('findById')->willReturn(self::doc(44, 'gesperrt.pdf'));
        $this->children['gesperrt.pdf'] = $this->fileNode('locked', null, false);

        $this->assertSame(Http::STATUS_NOT_FOUND, $this->controller('student')->download(self::COURSE, 44)->getStatus());
    }

    // ---------------------------------------------------------------- folder spelling --------

    /**
     * A folder typed as 'Kurs/Material' and later picked as '/Kurs/Material' (the file picker's
     * form) is the same folder: the rows of the old spelling stay open for the learners.
     */
    public function testRowOfTheUnslashedSpellingStaysDownloadable(): void {
        $this->enrolled('student');
        $this->courseService->method('canManageCourse')->willReturn(false);
        $legacy = self::doc(51, 'alt.pdf', 'pdf', 'Kurs/Material/alt.pdf');
        $this->documentMapper->method('findByCourseId')->willReturn([$legacy]);
        $this->documentMapper->method('findById')->willReturn($legacy);
        $this->children['alt.pdf'] = $this->fileNode('old row');

        $rows = $this->controller('student')->index(self::COURSE)->getData()['documents'];
        $this->assertTrue($rows[0]['downloadable']);
        $this->assertSame(Http::STATUS_OK, $this->controller('student')->download(self::COURSE, 51)->getStatus());
    }

    /** The whole home was never meant to be a material folder: nothing of it is served. */
    public function testCourseWhoseFolderIsTheHomeServesNothing(): void {
        $this->enrolled('student');
        $this->courseService->method('canManageCourse')->willReturn(false);
        $this->course->setMaterialFolder('/');
        $doc = self::doc(52, 'privat.pdf', 'pdf', '/privat.pdf');
        $this->documentMapper->method('findByCourseId')->willReturn([$doc]);
        $this->documentMapper->method('findById')->willReturn($doc);
        $this->homeFiles['/privat.pdf'] = $this->fileNode('home root');

        $this->assertFalse($this->controller('student')->index(self::COURSE)->getData()['documents'][0]['downloadable']);
        $this->assertSame(Http::STATUS_NOT_FOUND, $this->controller('student')->download(self::COURSE, 52)->getStatus());
    }

    private function folderController(string $folder): DocumentController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(static fn (string $key) => $key === 'folder' ? $folder : null);
        $this->roleService->method('isInstructor')->willReturn(true);
        return new DocumentController('learning', $request, $this->service(),
            $this->courseService, $this->roleService, $this->createMock(LoggerInterface::class), self::OWNER);
    }

    public function testTheHomeFolderCannotBeLinked(): void {
        $this->courseMapper->expects($this->never())->method('update');

        foreach (['/', '//', '///'] as $folder) {
            $response = $this->folderController($folder)->setFolder(self::COURSE);
            $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), "folder '$folder'");
        }
    }

    /** The body field 'folder' is read and stored in the one spelling the rows are compared in. */
    public function testLinkedFolderIsStoredNormalized(): void {
        $stored = [];
        $this->courseMapper->expects($this->once())->method('update')->willReturnCallback(function (Course $c) use (&$stored) {
            $stored[] = $c->getMaterialFolder();
            return $c;
        });
        // (the home mock serves the material folder only under the normalized spelling)
        $response = $this->folderController('Kurs//Material/')->setFolder(self::COURSE);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('/Kurs/Material', $response->getData()['folder']);
        $this->assertSame(['/Kurs/Material'], $stored);
    }

    // ---------------------------------------------------------------- extraction ------------

    /** Without pdftotext, "extract all" left every PDF marked as failed. */
    public function testExtractAllLeavesPdfsAloneWithoutPdftotext(): void {
        $this->pdfAvailable = false;
        $pdf = self::doc(31, 'a.pdf');
        $pdf->setStatus('uploaded');
        $md = self::doc(32, 'b.md', 'markdown');
        $md->setStatus('uploaded');
        $this->documentMapper->method('findByCourseId')->willReturn([$pdf, $md]);
        $this->documentMapper->method('findById')->willReturnCallback(fn (int $id) => $id === 31 ? $pdf : $md);
        $mdFile = $this->createMock(File::class);
        $mdFile->method('getContent')->willReturn("---\ntitle: x\n---\nBody");
        $home = $this->createMock(Folder::class);
        $home->method('get')->willReturnCallback(function (string $path) use ($mdFile) {
            if ($path === '/Kurs/Material/b.md') {
                return $mdFile;
            }
            if ($path === '/Kurs/Material') {
                $folder = $this->createMock(Folder::class);
                $folder->method('getType')->willReturn(\OCP\Files\Node::TYPE_FOLDER);
                $folder->method('getDirectoryListing')->willReturn([]);
                return $folder;
            }
            throw new NotFoundException($path);
        });
        $root = $this->createMock(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($home);
        $this->rootFolder = $root;

        $this->service()->extractAll(self::COURSE, self::OWNER);

        $this->assertSame('uploaded', $pdf->getStatus(), 'the PDF is not marked as failed');
        $this->assertSame('extracted', $md->getStatus());
    }

    public function testInlineDispositionKeepsAsciiFallbackSafe(): void {
        $header = DocumentController::inlineDisposition("a\"b\r\nc.pdf");
        $this->assertStringNotContainsString("\r", $header);
        $this->assertStringNotContainsString("\n", $header);
        $this->assertStringStartsWith('inline; filename="a_b__c.pdf"', $header);
    }
}
