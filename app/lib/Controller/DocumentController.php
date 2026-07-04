<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\DocumentService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\RoleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
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

            $this->documentService->setMaterialFolder($courseId, $folder, $this->userId);

            return new DataResponse(['status' => 'ok', 'folder' => $folder]);
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
            return new DataResponse(array_map(fn($d) => $d->jsonSerialize(), $documents));
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
     * @NoAdminRequired
     */
    public function index(int $courseId): DataResponse {
        try {
            $this->courseService->findById($courseId, $this->userId);
            return new DataResponse($this->documentService->listDocuments($courseId));
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Course not found or no access'], Http::STATUS_FORBIDDEN);
        }
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
            return new DataResponse($doc->jsonSerialize());
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
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
            return new DataResponse(array_map(fn($d) => $d->jsonSerialize(), $documents));
        } catch (\RuntimeException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('DocumentController::extractAll failed: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
