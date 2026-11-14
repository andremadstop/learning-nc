<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\DataExportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\IRequest;

class DataExportController extends Controller {
    private DataExportService $dataExportService;
    private ?string $userId;

    public function __construct(
        string $appName,
        IRequest $request,
        DataExportService $dataExportService,
        ?string $userId
    ) {
        parent::__construct($appName, $request);
        $this->dataExportService = $dataExportService;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 3, period: 60)]
    public function myData(): Http\Response {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $payload = $this->dataExportService->exportForUser($this->userId);
        // invalid UTF-8 in stored text becomes U+FFFD instead of failing the whole export; anything
        // json_encode still refuses is a 500 with a message, not a TypeError (audit #199)
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return new DataResponse(['error' => 'Export could not be encoded: ' . json_last_error_msg()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        $filename = 'learning-export-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $this->userId) . '.json';

        return new DataDownloadResponse($json, $filename, 'application/json');
    }
}
