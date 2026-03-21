<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\LernbotFileService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * LernbotFilesController — Phase 23: NC Files Integration
 *
 * REST API for managing Lernbot notes in the user's /Learning/ folder.
 *
 * GET  /api/lernbot/files  — list .md notes (optional ?subfolder= param)
 * POST /api/lernbot/note   — create or update a Markdown note
 */
class LernbotFilesController extends Controller {
    private ?string $userId;
    private LernbotFileService $fileService;

    public function __construct(
        string $appName,
        IRequest $request,
        ?string $userId,
        LernbotFileService $fileService
    ) {
        parent::__construct($appName, $request);
        $this->userId = $userId;
        $this->fileService = $fileService;
    }

    /**
     * List .md notes in /Learning/ or a subfolder.
     *
     * @NoAdminRequired
     * @param string|null $subfolder  Optional subfolder name (e.g. 'Zusammenfassungen')
     */
    #[UserRateLimit(limit: 60, period: 60)]
    public function listFiles(?string $subfolder = null): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $notes = $this->fileService->listNotes($this->userId, $subfolder ?? '');
            return new DataResponse(['notes' => $notes, 'subfolder' => $subfolder ?? '']);
        } catch (\Throwable $e) {
            return new DataResponse(
                ['error' => 'Could not list notes'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Create or update a Markdown note in /Learning/.
     *
     * Expected JSON body:
     * {
     *   "subfolder": "Zusammenfassungen",   // optional, '' = /Learning/ root
     *   "filename":  "VLAN-Grundlagen",     // .md appended automatically if missing
     *   "body":      "# VLAN Grundlagen\n[[Switch-Konfiguration]] ...",
     *   "meta": {                            // optional YAML frontmatter fields
     *     "topic":   "VLAN",
     *     "chapter": "Netzwerk-Grundlagen",
     *     "status":  "schwach",
     *     "source":  "learning-nc"
     *   }
     * }
     *
     * Returns:
     * { "path": "/Learning/Zusammenfassungen/VLAN-Grundlagen.md", "action": "created"|"updated" }
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function writeNote(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $subfolder = (string)($this->request->getParam('subfolder', '') ?? '');
        $filename  = (string)($this->request->getParam('filename', '') ?? '');
        $body      = (string)($this->request->getParam('body', '') ?? '');
        $meta      = $this->request->getParam('meta', []);

        if (!is_array($meta)) {
            $meta = [];
        }

        // Validate filename
        $filename = trim($filename);
        if ($filename === '') {
            return new DataResponse(['error' => 'filename is required'], Http::STATUS_BAD_REQUEST);
        }
        if (str_contains($filename, '/') || str_contains($filename, '\\') || str_contains($filename, '..')) {
            return new DataResponse(
                ['error' => 'filename must not contain path separators or ".."'],
                Http::STATUS_BAD_REQUEST
            );
        }

        // Validate subfolder (no absolute paths, no traversal)
        $subfolder = trim($subfolder, '/');
        if (str_contains($subfolder, '..') || str_contains($subfolder, '\\')) {
            return new DataResponse(
                ['error' => 'subfolder must not contain ".." or backslashes'],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            // Detect if the file already exists for the response action
            $notes = $this->fileService->listNotes($this->userId, $subfolder);
            $normalizedFilename = str_ends_with(strtolower($filename), '.md')
                ? $filename
                : $filename . '.md';
            $existingNames = array_column($notes, 'name');
            $action = in_array($normalizedFilename, $existingNames, true) ? 'updated' : 'created';

            $this->fileService->writeNote($this->userId, $subfolder, $filename, $body, $meta);

            $path = '/Learning/'
                . ($subfolder !== '' ? $subfolder . '/' : '')
                . $normalizedFilename;

            return new DataResponse(['path' => $path, 'action' => $action]);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return new DataResponse(
                ['error' => 'Could not write note'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }
}
