<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\PoolBulkImportService;
use OCA\Learning\Service\RoleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Import of whole pools in the Pools tab (GitHub #34): one JSON file in the format of
 * `occ learning:import-pool-json` creates every pool in it with its questions. The import is
 * PoolBulkImportService, the one occ uses. With dry_run the same checks run and nothing is
 * written — the preview in the interface shows exactly that answer.
 */
class PoolImportController extends Controller {
    /** Pools one import may create. */
    public const MAX_POOLS = 50;
    /** Question items over all pools of one import (the question import allows 500 per request). */
    public const MAX_QUESTIONS = 5000;

    public function __construct(
        string $appName,
        IRequest $request,
        private ?string $userId,
        private RoleService $roleService,
        private PoolBulkImportService $importer,
        private IConfig $config,
        private IL10N $l10n,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     *
     * POST /api/pools/import — instructors only.
     *
     * Request: `jsonData` (the file content as text) or a multipart upload `file`; `file_name`
     * (optional, the file's name — names the pool of a bare question list or an export, as occ
     * uses the file name); `dry_run` (true: check only, write nothing).
     */
    #[UserRateLimit(limit: 5, period: 60)]
    public function importPools(string $jsonData = ''): DataResponse {
        if ($this->userId === null || !$this->roleService->isInstructor($this->userId)) {
            return new DataResponse(['error' => $this->l10n->t('Only instructors can import pools')], Http::STATUS_FORBIDDEN);
        }

        // An unreadable dry_run must not turn a preview into a real import.
        $dryRun = filter_var($this->request->getParam('dry_run', false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($dryRun === null) {
            return new DataResponse(['error' => $this->l10n->t('dry_run must be true or false')], Http::STATUS_BAD_REQUEST);
        }

        $maxBytes = $this->getMaxImportBytes();
        $fileName = $this->request->getParam('file_name');
        $fileName = is_string($fileName) ? $fileName : '';

        $file = $this->request->getUploadedFile('file');
        if (is_array($file) && isset($file['error'])) {
            if ($file['error'] !== UPLOAD_ERR_OK) {
                return new DataResponse(['error' => $this->l10n->t('No file uploaded or upload error')], Http::STATUS_BAD_REQUEST);
            }
            if ((int)($file['size'] ?? 0) > $maxBytes) {
                return new DataResponse(['error' => $this->l10n->t('File too large (max %s MB)', [(int)($maxBytes / 1024 / 1024)])], Http::STATUS_BAD_REQUEST);
            }
            $content = is_string($file['tmp_name'] ?? null) ? file_get_contents($file['tmp_name']) : false;
            if ($content === false || $content === '') {
                return new DataResponse(['error' => $this->l10n->t('Could not read uploaded file')], Http::STATUS_BAD_REQUEST);
            }
            $jsonData = $content;
            if ($fileName === '' && is_string($file['name'] ?? null)) {
                $fileName = $file['name'];
            }
        }

        if (strlen($jsonData) > $maxBytes) {
            return new DataResponse(['error' => $this->l10n->t('JSON data too large (max %s MB)', [(int)($maxBytes / 1024 / 1024)])], Http::STATUS_BAD_REQUEST);
        }
        if (trim($jsonData) === '') {
            return new DataResponse(['error' => $this->l10n->t('No data to import')], Http::STATUS_BAD_REQUEST);
        }

        $data = json_decode(self::normalizeText($jsonData), true);
        if (!is_array($data)) {
            return new DataResponse(['error' => $this->l10n->t('Invalid JSON — check for missing brackets, commas, or quotes')], Http::STATUS_BAD_REQUEST);
        }

        $document = PoolBulkImportService::asPoolsDocument($data, $this->fallbackPoolName($fileName));
        if ($document === null) {
            return new DataResponse(['error' => $this->l10n->t('JSON must contain a "pools" array, a "questions" array, or be a list of questions')], Http::STATUS_BAD_REQUEST);
        }
        $poolCount = count(PoolBulkImportService::poolsOf($document));
        if ($poolCount === 0) {
            return new DataResponse(['error' => $this->l10n->t('The file contains no pools')], Http::STATUS_BAD_REQUEST);
        }
        if ($poolCount > self::MAX_POOLS) {
            return new DataResponse(['error' => $this->l10n->t('Maximum %s pools per import', [self::MAX_POOLS])], Http::STATUS_BAD_REQUEST);
        }
        if (PoolBulkImportService::questionCount($document) > self::MAX_QUESTIONS) {
            return new DataResponse(['error' => $this->l10n->t('Maximum %s questions per import', [self::MAX_QUESTIONS])], Http::STATUS_BAD_REQUEST);
        }

        $pools = $this->importer->import($document, $this->userId, $dryRun, $this->l10n);

        if ($dryRun) {
            return new DataResponse([
                'dry_run' => true,
                'pools' => array_map(static fn (array $p): array => [
                    'index' => $p['index'],
                    'name' => $p['name'],
                    'questions_total' => $p['questions_total'],
                    'importable' => $p['importable'],
                    'errors' => [...$p['errors'], ...$p['question_errors']],
                    'warnings' => $p['warnings'],
                ], $pools),
                'total_pools' => count($pools),
                'total_questions' => array_sum(array_column($pools, 'questions_total')),
                'total_importable' => array_sum(array_column($pools, 'importable')),
            ], Http::STATUS_OK);
        }

        $created = count(array_filter($pools, static fn (array $p): bool => $p['id'] !== null));
        return new DataResponse([
            'dry_run' => false,
            'pools' => array_map(static fn (array $p): array => [
                'index' => $p['index'],
                'id' => $p['id'],
                'name' => $p['name'],
                'questions_total' => $p['questions_total'],
                'imported' => $p['imported'],
                'errors' => [...$p['errors'], ...$p['question_errors']],
                'warnings' => $p['warnings'],
            ], $pools),
            'created_pools' => $created,
            'imported_questions' => array_sum(array_column($pools, 'imported')),
        ], $created > 0 ? Http::STATUS_CREATED : Http::STATUS_BAD_REQUEST);
    }

    private function getMaxImportBytes(): int {
        $maxMb = (int)$this->config->getAppValue('learning', 'max_import_size_mb', '2');
        $maxMb = max(1, min(10, $maxMb));
        return $maxMb * 1024 * 1024;
    }

    /** Name of the one pool of a bare question list or an export: the file name, as occ takes it. */
    private function fallbackPoolName(string $fileName): string {
        $name = trim(pathinfo(basename(str_replace('\\', '/', $fileName)), PATHINFO_FILENAME));
        $name = mb_substr($name, 0, PoolBulkImportService::NAME_MAX_LENGTH);
        return $name !== '' ? $name : $this->l10n->t('Imported pool');
    }

    /** UTF-8 BOM off, Windows-1252 to UTF-8 — as the question import reads a file. */
    private static function normalizeText(string $text): string {
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            $converted = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
            if ($converted !== false) {
                $text = $converted;
            }
        }
        return $text;
    }
}
