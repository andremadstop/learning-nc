<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\IcsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\IRequest;

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

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 5, period: 60)]
    public function generate(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        return new DataResponse($this->icsService->ensureCalendarToken($this->userId));
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     */
    public function feed(string $token): Http\Response {
        $calendar = $this->icsService->renderCalendarForToken($token);
        if ($calendar === null) {
            return new DataResponse(['error' => 'Invalid token'], Http::STATUS_FORBIDDEN);
        }

        return new DataDownloadResponse($calendar, 'learning-nc.ics', 'text/calendar; charset=utf-8');
    }
}
