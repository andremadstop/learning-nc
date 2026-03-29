<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Db\RagChunkMapper;
use OCA\Learning\Service\RagImportService;
use OCA\Learning\Service\RoleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class RagImportController extends Controller {
    private RagImportService $importService;
    private RagChunkMapper $chunkMapper;
    private RoleService $roleService;
    private LoggerInterface $logger;
    private ?string $userId;

    private const MAX_STUDENT_CHUNKS_PER_COURSE = 50;

    public function __construct(
        string $appName,
        IRequest $request,
        RagImportService $importService,
        RagChunkMapper $chunkMapper,
        RoleService $roleService,
        LoggerInterface $logger,
        ?string $userId
    ) {
        parent::__construct($appName, $request);
        $this->importService = $importService;
        $this->chunkMapper = $chunkMapper;
        $this->roleService = $roleService;
        $this->logger = $logger;
        $this->userId = $userId;
    }

    /**
     * Import pasted text/markdown as RAG chunks.
     *
     * @NoAdminRequired
     */
    public function importText(int $courseId): DataResponse {
        try {
            if (!$this->roleService->isInstructor($this->userId)) {
                return new DataResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
            }

            $title = $this->request->getParam('title');
            $text = $this->request->getParam('text');

            if (!is_string($title) || trim($title) === '') {
                return new DataResponse(['error' => 'Missing title parameter'], Http::STATUS_BAD_REQUEST);
            }
            if (!is_string($text) || trim($text) === '') {
                return new DataResponse(['error' => 'Missing text parameter'], Http::STATUS_BAD_REQUEST);
            }
            if (mb_strlen($title) > 255) {
                return new DataResponse(['error' => 'Title must be 255 characters or less'], Http::STATUS_BAD_REQUEST);
            }

            $result = $this->importService->importText($courseId, trim($title), $text);
            return new DataResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('RagImportController::importText failed: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Import an uploaded .md or .txt file as RAG chunks.
     *
     * @NoAdminRequired
     */
    public function importFile(int $courseId): DataResponse {
        try {
            if (!$this->roleService->isInstructor($this->userId)) {
                return new DataResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
            }

            $file = $this->request->getUploadedFile('file');
            if ($file === null || !isset($file['tmp_name'])) {
                return new DataResponse(['error' => 'No file uploaded'], Http::STATUS_BAD_REQUEST);
            }

            $name = $file['name'] ?? 'unknown.txt';
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['md', 'txt'], true)) {
                return new DataResponse(['error' => 'Only .md and .txt files are allowed'], Http::STATUS_BAD_REQUEST);
            }

            $size = $file['size'] ?? 0;
            if ($size > 5 * 1024 * 1024) {
                return new DataResponse(['error' => 'File too large (max 5 MB)'], Http::STATUS_BAD_REQUEST);
            }

            $content = file_get_contents($file['tmp_name']);
            if ($content === false) {
                return new DataResponse(['error' => 'Failed to read uploaded file'], Http::STATUS_INTERNAL_SERVER_ERROR);
            }

            $result = $this->importService->importText($courseId, $name, $content);
            return new DataResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('RagImportController::importFile failed: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * List web-imported knowledge sources for a course.
     *
     * @NoAdminRequired
     */
    public function listImported(int $courseId): DataResponse {
        try {
            if (!$this->roleService->isInstructor($this->userId)) {
                return new DataResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
            }

            return new DataResponse($this->importService->listImported($courseId));
        } catch (\Exception $e) {
            $this->logger->error('RagImportController::listImported failed: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Delete web-imported knowledge chunks by title.
     *
     * @NoAdminRequired
     */
    public function deleteImported(int $courseId, string $title): DataResponse {
        try {
            if (!$this->roleService->isInstructor($this->userId)) {
                return new DataResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
            }

            $deleted = $this->importService->deleteImported($courseId, $title);
            return new DataResponse(['deleted' => $deleted]);
        } catch (\Exception $e) {
            $this->logger->error('RagImportController::deleteImported failed: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Student contributes a knowledge note to the shared pool.
     *
     * @NoAdminRequired
     */
    public function contributeNote(int $courseId): DataResponse {
        try {
            $title = $this->request->getParam('title');
            $text = $this->request->getParam('text');

            if (!is_string($title) || trim($title) === '') {
                return new DataResponse(['error' => 'Missing title parameter'], Http::STATUS_BAD_REQUEST);
            }
            if (!is_string($text) || trim($text) === '') {
                return new DataResponse(['error' => 'Missing text parameter'], Http::STATUS_BAD_REQUEST);
            }
            if (mb_strlen($title) > 255) {
                return new DataResponse(['error' => 'Title must be 255 characters or less'], Http::STATUS_BAD_REQUEST);
            }

            $existing = $this->chunkMapper->countByUserIdAndCourseId($this->userId, $courseId);
            if ($existing >= self::MAX_STUDENT_CHUNKS_PER_COURSE) {
                return new DataResponse(['error' => 'Chunk limit reached (max ' . self::MAX_STUDENT_CHUNKS_PER_COURSE . ')'], Http::STATUS_FORBIDDEN);
            }

            $result = $this->importService->importText($courseId, trim($title), $text, $this->userId, 'student');
            return new DataResponse($result, Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('RagImportController::contributeNote failed: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * List pending contributions for moderation (instructor only).
     *
     * @NoAdminRequired
     */
    public function listPending(int $courseId): DataResponse {
        try {
            if (!$this->roleService->isInstructor($this->userId)) {
                return new DataResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
            }

            $chunks = $this->chunkMapper->findByCourseIdAndStatus($courseId, 'pending');
            return new DataResponse(array_map(fn($c) => $c->jsonSerialize(), $chunks));
        } catch (\Exception $e) {
            $this->logger->error('RagImportController::listPending failed: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Moderate a contribution (approve/reject).
     *
     * @NoAdminRequired
     */
    public function moderate(int $courseId, int $chunkId): DataResponse {
        try {
            if (!$this->roleService->isInstructor($this->userId)) {
                return new DataResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
            }

            $action = $this->request->getParam('action');
            if (!in_array($action, ['approve', 'reject'], true)) {
                return new DataResponse(['error' => 'Invalid action (approve|reject)'], Http::STATUS_BAD_REQUEST);
            }

            /** @var \OCA\Learning\Db\RagChunk $chunk */
            $chunk = $this->chunkMapper->findById($chunkId);

            if ($chunk->getCourseId() !== $courseId) {
                return new DataResponse(['error' => 'Chunk not in this course'], Http::STATUS_NOT_FOUND);
            }

            $chunk->setStatus($action === 'approve' ? 'approved' : 'rejected');
            $this->chunkMapper->update($chunk);

            return new DataResponse(['status' => $chunk->getStatus()]);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Chunk not found'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            $this->logger->error('RagImportController::moderate failed: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * List own contributions in a course (student view).
     *
     * @NoAdminRequired
     */
    public function myContributions(int $courseId): DataResponse {
        try {
            $chunks = $this->chunkMapper->findByUserIdAndCourseId($this->userId, $courseId);
            return new DataResponse(array_map(fn($c) => $c->jsonSerialize(), $chunks));
        } catch (\Exception $e) {
            $this->logger->error('RagImportController::myContributions failed: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
