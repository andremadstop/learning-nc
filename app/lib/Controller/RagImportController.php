<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Db\RagChunkMapper;
use OCA\Learning\Service\GeminiService;
use OCA\Learning\Service\RagImportService;
use OCA\Learning\Service\AuditService;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\CourseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class RagImportController extends Controller {
    private RagImportService $importService;
    private RagChunkMapper $chunkMapper;
    private AuditService $auditService;
    private BadgeService $badgeService;
    private LoggerInterface $logger;
    private ?string $userId;
    private CourseService $courseService;

    private const MAX_STUDENT_CHUNKS_PER_COURSE = 50;

    public function __construct(
        string $appName,
        IRequest $request,
        RagImportService $importService,
        RagChunkMapper $chunkMapper,
        AuditService $auditService,
        BadgeService $badgeService,
        LoggerInterface $logger,
        ?string $userId,
        CourseService $courseService
    ) {
        parent::__construct($appName, $request);
        $this->importService = $importService;
        $this->chunkMapper = $chunkMapper;
        $this->auditService = $auditService;
        $this->badgeService = $badgeService;
        $this->logger = $logger;
        $this->userId = $userId;
        $this->courseService = $courseService;
    }

    /**
     * AUDIT MED-03: instructor endpoints must be scoped to THIS course, not the global
     * instructor role (an instructor of course A could otherwise import/list/delete/moderate
     * RAG content of course B). canManageCourse() is the course owner/instructor check used
     * elsewhere (e.g. updateCertConfig).
     */
    private function canManageCourse(int $courseId): bool {
        return $this->userId !== null
            && $this->courseService->canManageCourse($courseId, $this->userId);
    }

    /**
     * Import pasted text/markdown as RAG chunks.
     *
     * @NoAdminRequired
     */
    public function importText(int $courseId): DataResponse {
        try {
            if (!$this->canManageCourse($courseId)) {
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
            if (!$this->canManageCourse($courseId)) {
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
            if (!$this->canManageCourse($courseId)) {
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
            if (!$this->canManageCourse($courseId)) {
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
        // AUDIT MED-02: only course members may contribute to a course's shared RAG pool.
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            $this->courseService->findById($courseId, $this->userId);
        } catch (\Throwable $e) {
            return new DataResponse(['error' => 'Course not found or no access'], Http::STATUS_FORBIDDEN);
        }
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
            if (mb_strlen($text) > 5000) {
                return new DataResponse(['error' => 'Text must be 5000 characters or less'], Http::STATUS_BAD_REQUEST);
            }

            // SEC: Check for prompt injection patterns in student contributions
            $combined = $title . ' ' . $text;
            if (GeminiService::containsInjectionPattern($combined)) {
                $this->logger->warning('RagImportController: injection pattern in contribution', [
                    'app' => 'learning',
                    'user_id' => $this->userId,
                    'title' => mb_substr($title, 0, 50),
                ]);
                return new DataResponse(['error' => 'Content contains disallowed patterns'], Http::STATUS_BAD_REQUEST);
            }

            $piiWarnings = $this->importService->detectPii($combined);

            $existing = $this->chunkMapper->countByUserIdAndCourseId($this->userId, $courseId);
            if ($existing >= self::MAX_STUDENT_CHUNKS_PER_COURSE) {
                return new DataResponse(['error' => 'Chunk limit reached (max ' . self::MAX_STUDENT_CHUNKS_PER_COURSE . ')'], Http::STATUS_FORBIDDEN);
            }

            $result = $this->importService->importText(
                $courseId,
                trim($title),
                $text,
                $this->userId,
                RagImportService::SOURCE_TYPE_STUDENT
            );

            // Check swarm badge after successful student contribution
            $this->badgeService->checkAndAward($this->userId, 'swarm_contribution', []);

            if ($piiWarnings !== []) {
                $result['warning'] = 'Potential personal data detected. Please review your contribution before it is approved.';
                $result['pii_warnings'] = $piiWarnings;
            }

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
            if (!$this->canManageCourse($courseId)) {
                return new DataResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
            }

            $chunks = $this->chunkMapper->findByCourseIdAndStatus($courseId, 'pending');
            return new DataResponse(array_map(function ($chunk) {
                $data = $chunk->jsonSerialize();
                $combined = trim((string)($data['source_file'] ?? '') . ' ' . (string)($data['text'] ?? ''));
                $warnings = $combined !== '' ? $this->importService->detectPii($combined) : [];
                if ($warnings !== []) {
                    $data['pii_warnings'] = $warnings;
                }
                return $data;
            }, $chunks));
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
            if (!$this->canManageCourse($courseId)) {
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

            $previousStatus = $chunk->getStatus();
            $newStatus = $action === 'approve' ? 'approved' : 'rejected';

            $chunk->setStatus($newStatus);
            $this->chunkMapper->update($chunk);

            $this->auditService->logEvent('swarm_moderation', (string)$this->userId, [
                'action' => $action,
                'previous_status' => $previousStatus,
                'new_status' => $newStatus,
                'chunk_id' => $chunkId,
                'course_id' => $courseId,
                'source_file' => $chunk->getSourceFile(),
                'chapter' => $chunk->getChapter(),
                'contributor_user_id' => $chunk->getUserId(),
                'source_type' => $chunk->getSourceType(),
            ]);

            return new DataResponse(['status' => $newStatus]);
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
