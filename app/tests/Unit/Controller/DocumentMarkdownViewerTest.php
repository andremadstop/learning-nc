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
use OCA\Learning\Service\MaterialShareService;
use OCA\Learning\Service\RequiredDocumentService;
use OCA\Learning\Service\RoleService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * GitHub #110 — Markdown materials open in the stock Text viewer.
 *
 * GET /documents/{documentId}/download used to serve every Markdown file as text/plain, so a
 * learner saw the raw source. It now redirects to the file page (/f/{fileid}) — Nextcloud's
 * own Text app renders it read-only — and keeps the plain text response as the fallback for
 * every reason the viewer is not there. The checks of GitHub #35/#86 are unchanged and run
 * before the redirect, and the route still carries no request token.
 *
 * A real DocumentService runs over a mocked file tree, so the resolution under test is the
 * production one; MaterialShareService is mocked because it decides the URL.
 */
#[AllowMockObjectsWithoutExpectations]
class DocumentMarkdownViewerTest extends TestCase {
    private const COURSE = 7;
    private const OWNER = 'teacher';
    private const VIEWER = '/f/77';

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
    /** @var MaterialShareService&MockObject */
    private $materialShares;
    private Course $course;
    /** @var array<string, File|Folder> children of the material folder by name */
    private array $children = [];
    /** @var array<string, Folder> further folders of the owner's home by path */
    private array $homeFolders = [];
    /** @var list<string> names requested from the material folder */
    private array $requestedNames = [];

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
        $this->materialShares = $this->createMock(MaterialShareService::class);

        $materialFolder = $this->createMock(Folder::class);
        $materialFolder->method('getType')->willReturn(\OCP\Files\Node::TYPE_FOLDER);
        $materialFolder->method('get')->willReturnCallback(function (string $name) {
            $this->requestedNames[] = $name;
            if (!isset($this->children[$name])) {
                throw new NotFoundException($name);
            }
            return $this->children[$name];
        });
        $home = $this->createMock(Folder::class);
        $home->method('get')->willReturnCallback(function (string $path) use ($materialFolder) {
            if ($path === $this->course->getMaterialFolder()) {
                return $materialFolder;
            }
            if (isset($this->homeFolders[$path])) {
                return $this->homeFolders[$path];
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
        return new DocumentService($this->documentMapper, $this->courseMapper, $this->rootFolder, $this->createMock(LoggerInterface::class));
    }

    /** @param array<string, string|null> $params query parameters of the request */
    private function controller(string $uid, array $params = [], ?MaterialShareService $shares = null, ?RequiredDocumentService $required = null): DocumentController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, $default = null) => array_key_exists($key, $params) ? $params[$key] : $default);
        return new DocumentController('learning', $request, $this->service(),
            $this->courseService, $this->roleService, $this->createMock(LoggerInterface::class), $uid,
            $required, $shares ?? $this->materialShares);
    }

    private static function doc(int $id, string $name, string $type = 'markdown'): CourseDocument {
        $doc = new CourseDocument();
        $doc->setId($id);
        $doc->setCourseId(self::COURSE);
        $doc->setFileName($name);
        $doc->setFilePath('/Kurs/Material/' . $name);
        $doc->setFileType($type);
        $doc->setStatus('extracted');
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

    private function fileNode(string $content): File {
        $storage = $this->createMock(\OCP\Files\Storage\IStorage::class);
        $storage->method('instanceOfStorage')->willReturn(false);
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn(77);
        $file->method('isReadable')->willReturn(true);
        $file->method('getStorage')->willReturn($storage);
        $file->method('fopen')->willReturnCallback(function () use ($content) {
            $h = fopen('php://memory', 'r+');
            fwrite($h, $content);
            rewind($h);
            return $h;
        });
        $file->method('getSize')->willReturn(strlen($content));
        $file->method('getMimeType')->willReturn('text/markdown');
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

    // ---------------------------------------------------------------- the redirect ----------

    /** A member clicking a Markdown material lands in the Text viewer, not in the raw source. */
    public function testMarkdownOpensInTheTextViewer(): void {
        $this->enrolled('student');
        $this->documentMapper->method('findById')->willReturn(self::doc(12, 'notes.md'));
        $this->children['notes.md'] = $this->fileNode('# Hi');
        $this->materialShares->expects($this->once())->method('textViewUrl')
            ->with($this->course, 'student', $this->anything())
            ->willReturn(self::VIEWER);

        $response = $this->controller('student')->download(self::COURSE, 12);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(self::VIEWER, $response->getHeaders()['Location']);
        $this->assertSame(['notes.md'], $this->requestedNames, 'resolved by plain name before the redirect');
    }

    /** GitHub #86: the open is recorded on the redirect exactly as it was on the served file. */
    public function testTheOpenIsRecordedBeforeTheRedirect(): void {
        $this->course->setOpenBeforeConfirm(true);
        $this->enrolled('student');
        $this->courseService->method('canManageCourse')->with(self::COURSE, 'student')->willReturn(false);
        $this->documentMapper->method('findById')->willReturn(self::doc(12, 'notes.md'));
        $file = $this->fileNode('# Hi');
        $this->children['notes.md'] = $file;
        $this->materialShares->method('textViewUrl')->willReturn(self::VIEWER);
        $required = $this->createMock(RequiredDocumentService::class);
        $required->expects($this->once())->method('recordOpen')
            ->with($this->course, 12, DocumentService::fileVersion($file), 'student')->willReturn(true);

        $response = $this->controller('student', [], null, $required)->download(self::COURSE, 12);

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    /** ?raw=1 asks for the old plain text response and never reaches the viewer. */
    public function testRawKeepsThePlainTextResponse(): void {
        $this->enrolled('student');
        $this->documentMapper->method('findById')->willReturn(self::doc(12, 'notes.md'));
        $this->children['notes.md'] = $this->fileNode('# Hi <script>alert(1)</script>');
        $this->materialShares->expects($this->never())->method('textViewUrl');

        $response = $this->controller('student')->download(self::COURSE, 12, '1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('text/plain; charset=utf-8', $response->getHeaders()['Content-Type']);
        $this->assertSame('nosniff', $response->getHeaders()['X-Content-Type-Options']);
        $this->assertSame('# Hi <script>alert(1)</script>', $this->body($response));
    }

    /** Text app missing, sharing refused, viewer route gone: the link must not dead-end. */
    public function testWithoutAViewerThePlainTextIsServed(): void {
        $this->enrolled('student');
        $this->documentMapper->method('findById')->willReturn(self::doc(12, 'notes.md'));
        $this->children['notes.md'] = $this->fileNode('# Hi');
        $this->materialShares->method('textViewUrl')->willReturn(null);

        $response = $this->controller('student')->download(self::COURSE, 12);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('text/plain; charset=utf-8', $response->getHeaders()['Content-Type']);
        $this->assertSame('# Hi', $this->body($response));
    }

    /** PDFs open inline as before and never reach the share logic. */
    public function testPdfIsNeverSentToTheViewer(): void {
        $this->enrolled('student');
        $this->documentMapper->method('findById')->willReturn(self::doc(11, 'skript.pdf', 'pdf'));
        $this->children['skript.pdf'] = $this->fileNode('%PDF-1.7 bytes');
        $this->materialShares->expects($this->never())->method('textViewUrl');

        $response = $this->controller('student')->download(self::COURSE, 11);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('application/pdf', $response->getHeaders()['Content-Type']);
        $this->assertSame('%PDF-1.7 bytes', $this->body($response));
    }

    /** The checks of GitHub #35 run before the redirect: a non-member reaches no viewer. */
    public function testANonMemberNeverReachesTheViewer(): void {
        $this->enrolled('student');
        $this->documentMapper->expects($this->never())->method('findById');
        $this->materialShares->expects($this->never())->method('textViewUrl');

        $response = $this->controller('stranger')->download(self::COURSE, 12);

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $this->assertSame([], $this->requestedNames);
    }

    /** A document row outside the current folder is 404 before any viewer URL is built. */
    public function testAFileOutsideTheMaterialFolderIsNotFoundBeforeTheViewer(): void {
        $this->enrolled('student');
        $this->documentMapper->method('findById')->willReturn(self::doc(14, 'gehalt.pdf', 'pdf'));
        $this->materialShares->expects($this->never())->method('textViewUrl');

        $response = $this->controller('student')->download(self::COURSE, 14);

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }

    // ---------------------------------------------------------------- folder change ---------

    /** Linking another folder ends this course's shares of the old one (#110). */
    public function testLinkingAnotherFolderRevokesTheSharesOfTheOldOne(): void {
        $this->roleService->method('isInstructor')->willReturn(true);
        $newFolder = $this->createMock(Folder::class);
        $newFolder->method('getType')->willReturn(\OCP\Files\Node::TYPE_FOLDER);
        $this->homeFolders['/Neu'] = $newFolder;
        $this->courseMapper->expects($this->once())->method('update')->willReturnArgument(0);
        $this->materialShares->expects($this->once())->method('revokeCourseShares')
            ->with(self::COURSE, '/Kurs/Material');

        $response = $this->controller(self::OWNER, ['folder' => '/Neu'])->setFolder(self::COURSE);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('/Neu', $response->getData()['folder']);
    }

    /** Relinking the same folder changes nothing, so nothing is revoked. */
    public function testRelinkingTheSameFolderRevokesNothing(): void {
        $this->roleService->method('isInstructor')->willReturn(true);
        $this->courseMapper->method('update')->willReturnArgument(0);
        $this->materialShares->expects($this->never())->method('revokeCourseShares');

        $response = $this->controller(self::OWNER, ['folder' => '/Kurs/Material'])->setFolder(self::COURSE);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }
}
