<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\DataMobilityService;
use OCA\Learning\Service\IcsService;
use OCA\Learning\Service\QuestionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attributes\NoAdminRequired;
use OCP\AppFramework\Http\Attributes\NoCSRFRequired;
use OCP\AppFramework\Http\Attributes\PublicPage;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\IRequest;

class ExportController extends Controller {
    private QuestionService $questionService;
    private DataMobilityService $dataMobilityService;
    private IcsService $icsService;
    private ?string $userId;

    public function __construct(
        string $appName,
        IRequest $request,
        QuestionService $questionService,
        DataMobilityService $dataMobilityService,
        IcsService $icsService,
        ?string $userId
    ) {
        parent::__construct($appName, $request);
        $this->questionService = $questionService;
        $this->dataMobilityService = $dataMobilityService;
        $this->icsService = $icsService;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function exportCsv(int $poolId): Http\Response {
        try {
            $this->questionService->verifyExportAccess($poolId, $this->userId);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Pool not found or no export access'], Http::STATUS_FORBIDDEN);
        }

        $csv = $this->dataMobilityService->exportPoolCsv($poolId);
        return new DataDownloadResponse($csv, 'pool-' . $poolId . '.csv', 'text/csv');
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function exportJson(int $poolId): Http\Response {
        try {
            $this->questionService->verifyExportAccess($poolId, $this->userId);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Pool not found or no export access'], Http::STATUS_FORBIDDEN);
        }

        $export = $this->dataMobilityService->exportPoolJson($poolId);
        $json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return new DataDownloadResponse($json, 'pool-' . $poolId . '.json', 'application/json');
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function exportIcs(): Http\Response {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        $cal = $this->icsService->renderCalendarForUser($this->userId);
        return new DataDownloadResponse($cal, 'learning-nc.ics', 'text/calendar; charset=utf-8');
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function getCalendarToken(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        return new DataResponse($this->icsService->ensureCalendarToken($this->userId));
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 5, period: 60)]
    public function regenerateCalendarToken(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        return new DataResponse($this->icsService->regenerateCalendarToken($this->userId));
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     */
    public function exportIcsPublic(string $token): Http\Response {
        $cal = $this->icsService->renderCalendarForToken($token);
        if ($cal === null) {
            return new DataResponse(['error' => 'Invalid token'], Http::STATUS_FORBIDDEN);
        }

        return new DataDownloadResponse($cal, 'learning-nc.ics', 'text/calendar; charset=utf-8');
    }

}
