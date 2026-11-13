<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Db\CourseDocument;
use OCA\Learning\Service\DocumentService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\RoleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class DocumentController extends Controller {
    private DocumentService $documentService;
    private CourseService $courseService;
    private RoleService $roleService;
    private LoggerInterface $logger;
    private ?string $userId;

    public function __construct(
        string $appName,
        IRequest $request,
        DocumentService $documentService,
        CourseService $courseService,
        RoleService $roleService,
        LoggerInterface $logger,
        ?string $userId
    ) {
        parent::__construct($appName, $request);
        $this->documentService = $documentService;
        $this->courseService = $courseService;
        $this->roleService = $roleService;
        $this->logger = $logger;
        $this->userId = $userId;
    }

    /**
     * Set the material folder for a course.
     *
     * @NoAdminRequired
     */
    public function setFolder(int $courseId): DataResponse {
        try {
            if (!$this->roleService->isInstructor($this->userId)) {
                return new DataResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
            }

            $folder = $this->request->getParam('folder');
            if ($folder === null || $folder === '') {
                return new DataResponse(['error' => 'Missing folder parameter'], Http::STATUS_BAD_REQUEST);
            }

            if (!is_string($folder)) {
                return new DataResponse(['error' => 'Missing folder parameter'], Http::STATUS_BAD_REQUEST);
            }

            // the stored, normalized spelling (GitHub #35: picker and text field agree)
            $stored = $this->documentService->setMaterialFolder($courseId, $folder, $this->userId);

            return new DataResponse(['status' => 'ok', 'folder' => $stored]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => 'The home folder cannot be the material folder'], Http::STATUS_BAD_REQUEST);
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => 'Folder not found'], Http::STATUS_NOT_FOUND);
        } catch (\RuntimeException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (\Exception $e) {
            $this->logger->error('DocumentController::setFolder failed: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get the material folder for a course.
     *
     * @NoAdminRequired
     */
    public function getFolder(int $courseId): DataResponse {
        try {
            $this->courseService->findById($courseId, $this->userId);
            $folder = $this->documentService->getMaterialFolder($courseId);
            return new DataResponse(['folder' => $folder]);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * Scan the material folder for new documents.
     *
     * @NoAdminRequired
     */
    public function scan(int $courseId): DataResponse {
        try {
            if (!$this->roleService->isInstructor($this->userId)) {
                return new DataResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
            }

            $documents = $this->documentService->scanFolder($courseId, $this->userId);
            return new DataResponse($this->documentService->projectDocuments($courseId, $documents, true));
        } catch (\RuntimeException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('DocumentController::scan failed: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * List all documents for a course.
     *
     * GitHub #35: the processing details (status, error, path) are for the instructors of THIS
     * course, not for everyone with the global instructor role, and the extracted text is in no
     * list at all (DocumentService::projectDocuments). The envelope carries what the page needs
     * to stay honest: whether the viewer can manage the files (they live in the owner's home)
     * and whether this server can extract text from PDFs at all.
     *
     * @NoAdminRequired
     */
    public function index(int $courseId): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            $course = $this->courseService->assertEnrolledInCourse($courseId, $this->userId);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Course not found or no access'], Http::STATUS_FORBIDDEN);
        }
        try {
            $forInstructor = $this->courseService->canManageCourse($courseId, $this->userId);
            return new DataResponse([
                'documents' => $this->documentService->listDocuments($courseId, $forInstructor),
                'can_manage_files' => $course->getInstructorId() === $this->userId,
                'pdf_text_extraction' => $this->documentService->isPdfTextExtractionAvailable(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('DocumentController::index failed: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Open a material file (GitHub #35): learners could see the file names but not open them.
     *
     * Security, in this order: logged in; member or instructor of THIS course (403 otherwise);
     * the document belongs to this course; the file is a direct child of the course's current
     * material folder, resolved by its plain name in the owner's home — never a path from the
     * request (DocumentService::resolveMaterialFile; 404 otherwise). The Content-Type comes from
     * the registered file type, not from the file: PDF is shown inline, Markdown as plain text,
     * with nosniff, so an uploaded file cannot become an HTML page of this origin.
     *
     * @NoCSRFRequired is needed because the browser opens the file through a plain link, which
     * carries no request token. The endpoint only reads.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[UserRateLimit(limit: 120, period: 60)]
    public function download(int $courseId, int $documentId): Response {
        if ($this->userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            $course = $this->courseService->assertEnrolledInCourse($courseId, $this->userId);
        } catch (ForbiddenException $e) {
            return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        try {
            [$doc, $file] = $this->documentService->resolveMaterialFile($course, $documentId);
            $stream = $file->fopen('r');
        } catch (NotFoundException $e) {
            return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            $this->logger->error('DocumentController::download failed: ' . $e->getMessage(), ['app' => 'learning']);
            return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        if (!is_resource($stream)) {
            return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $headers = [
            'Content-Type' => self::contentTypeFor($doc),
            'Content-Disposition' => self::inlineDisposition($doc->getFileName()),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ];
        $size = $file->getSize();
        if (is_int($size) || is_float($size)) {
            $headers['Content-Length'] = (string)(int)$size;
        }
        return self::streamResponse($stream, $headers);
    }

    /** Whitelist by the registered type; the file's own mime type is not trusted. */
    private static function contentTypeFor(CourseDocument $doc): string {
        return $doc->getFileType() === 'pdf' ? 'application/pdf' : 'text/plain; charset=utf-8';
    }

    /** RFC 6266: an ASCII fallback name plus the exact name as filename*. */
    public static function inlineDisposition(string $fileName): string {
        $fallback = preg_replace('/[^A-Za-z0-9._ -]/u', '_', $fileName) ?? 'file';
        if ($fallback === '') {
            $fallback = 'file';
        }
        return 'inline; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($fileName);
    }

    /**
     * The whole file through the in-repo ICallbackResponse idiom (there is no OCP StreamResponse
     * for a resource here; see VideoStreamController).
     *
     * @param resource             $stream
     * @param array<string,string> $headers
     */
    private static function streamResponse($stream, array $headers): Response {
        return new class($stream, $headers) extends Response implements ICallbackResponse {
            /**
             * @param resource             $stream
             * @param array<string,string> $headers
             */
            public function __construct(private $stream, array $headers) {
                parent::__construct(Http::STATUS_OK, $headers);
            }

            public function callback(IOutput $output): void {
                while (!feof($this->stream)) {
                    $chunk = fread($this->stream, 8192);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $output->setOutput($chunk);
                }
                fclose($this->stream);
            }
        };
    }

    /**
     * Extract text from a single document.
     *
     * @NoAdminRequired
     */
    public function extract(int $courseId, int $documentId): DataResponse {
        try {
            if (!$this->roleService->isInstructor($this->userId)) {
                return new DataResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
            }

            $doc = $this->documentService->extractText($documentId, $this->userId);
            $rows = $this->documentService->projectDocuments($doc->getCourseId(), [$doc], true);
            return new DataResponse($rows[0]);
        } catch (DoesNotExistException $e) {
            return new DataResponse(['error' => 'Document not found'], Http::STATUS_NOT_FOUND);
        } catch (\RuntimeException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('DocumentController::extract failed: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Extract text from all uploaded documents in a course.
     *
     * @NoAdminRequired
     */
    public function extractAll(int $courseId): DataResponse {
        try {
            if (!$this->roleService->isInstructor($this->userId)) {
                return new DataResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
            }

            $documents = $this->documentService->extractAll($courseId, $this->userId);
            return new DataResponse($this->documentService->projectDocuments($courseId, $documents, true));
        } catch (\RuntimeException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('DocumentController::extractAll failed: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
