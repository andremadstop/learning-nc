<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\IcsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\IRequest;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;

#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]
class IcsController extends Controller {
    private IcsService $icsService;
    private ?string $userId;

    public function __construct(
        string $appName,
        IRequest $request,
        IcsService $icsService,
        ?string $userId
    ) {
        parent::__construct($appName, $request);
        $this->icsService = $icsService;
        $this->userId = $userId;
    }

    #[NoAdminRequired]
    #[UserRateLimit(limit: 5, period: 60)]
    public function generate(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        return new DataResponse($this->icsService->ensureCalendarToken($this->userId));
    }

    /**
     * @return DataDownloadResponse<\OCP\AppFramework\Http::STATUS_OK, 'text/calendar; charset=utf-8', array{}>|DataResponse<\OCP\AppFramework\Http::STATUS_FORBIDDEN, array{error: string}, array{}>
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 120, period: 600)]
    public function feed(string $token): Http\Response {
        // Polled by calendar services without a session, so the limit is per address: 120 fetches
        // in 10 minutes leave room for a class behind one NAT whose calendars poll every 5 minutes
        // (Apple 5–60 min, Thunderbird from 1 min) and for shared fetchers such as Google's (one
        // poll per subscription every 8–24 h). There is deliberately no brute-force throttle:
        // tokens carry 256 bits and anything that is not 64 lowercase hex is refused before any
        // lookup, so there is nothing to guess, while Nextcloud's throttle would refuse the whole
        // action for an address (valid tokens included) after a handful of bad requests and let one
        // person behind a school NAT or a proxy lock everyone there out of their feed. The legacy
        // alias export#exportIcsPublic has the same limit.
        $calendar = $this->icsService->renderCalendarForToken($token);
        if ($calendar === null) {
            return new DataResponse(['error' => 'Invalid token'], Http::STATUS_FORBIDDEN);
        }

        return new DataDownloadResponse($calendar, 'learning-nc.ics', 'text/calendar; charset=utf-8');
    }
}
