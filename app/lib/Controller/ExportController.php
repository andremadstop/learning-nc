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
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\IRequest;
use OCP\AppFramework\Http\Attribute\OpenAPI;

#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]
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
     * Opened by browser navigation from the pool window (no request token): read-only GET, access
     * checked by verifyExportAccess(). Without the exemption it answered 412 (persona sweep, #36).
     *
     * @NoAdminRequired
     * @NoCSRFRequired
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
     * Opened by browser navigation from the pool window (no request token): read-only GET, access
     * checked by verifyExportAccess(). Without the exemption it answered 412 (persona sweep, #36).
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function exportJson(int $poolId): Http\Response {
        try {
            $this->questionService->verifyExportAccess($poolId, $this->userId);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Pool not found or no export access'], Http::STATUS_FORBIDDEN);
        }

        $export = $this->dataMobilityService->exportPoolJson($poolId);
        // invalid UTF-8 in imported content becomes U+FFFD instead of failing the whole export;
        // anything json_encode still refuses is a 500 with a message, not a TypeError (audit #199)
        $json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return new DataResponse(['error' => 'Export could not be encoded: ' . json_last_error_msg()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        return new DataDownloadResponse($json, 'pool-' . $poolId . '.json', 'application/json');
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 120, period: 60)]
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
     * Legacy alias of ics#feed: v2.7.0 handed out this URL, and calendars subscribed back then
     * still poll it. New links come from IcsService::buildFeedUrl (learning.ics.feed). Both
     * render through IcsService::renderCalendarForToken, so a fix there covers both (audit #134).
     *
     * @PublicPage
     * @NoCSRFRequired
     *
     * @return DataDownloadResponse<\OCP\AppFramework\Http::STATUS_OK, 'text/calendar; charset=utf-8', array{}>|DataResponse<\OCP\AppFramework\Http::STATUS_FORBIDDEN, array{error: string}, array{}>
     */
    #[AnonRateLimit(limit: 120, period: 600)]
    #[BruteForceProtection(action: IcsService::FEED_THROTTLE_ACTION)]
    public function exportIcsPublic(string $token): Http\Response {
        // Same per-address limit and brute-force action as ics#feed (reasoning there). A v2.7.0
        // token (base64url(uid).hmac) is the stale subscription this alias exists for, so it is
        // refused without feeding the throttle, like a well-formed unknown token.
        $cal = $this->icsService->renderCalendarForToken($token);
        if ($cal === null) {
            $response = new DataResponse(['error' => 'Invalid token'], Http::STATUS_FORBIDDEN);
            if (!IcsService::isWellFormedFeedToken($token) && !IcsService::isLegacyFeedToken($token)) {
                $response->throttle(['action' => IcsService::FEED_THROTTLE_ACTION]);
            }
            return $response;
        }

        return new DataDownloadResponse($cal, 'learning-nc.ics', 'text/calendar; charset=utf-8');
    }

}
