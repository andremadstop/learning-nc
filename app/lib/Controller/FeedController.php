<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\FeedService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class FeedController extends Controller {
    private FeedService $feedService;
    private ?string $userId;

    public function __construct(
        string $appName,
        IRequest $request,
        FeedService $feedService,
        ?string $userId
    ) {
        parent::__construct($appName, $request);
        $this->feedService = $feedService;
        $this->userId = $userId;
    }

    /**
     * Get aggregated feed for the current user across all enrolled courses.
     *
     * @NoAdminRequired
     */
    public function index(): DataResponse {
        $items = $this->feedService->getUserFeed($this->userId);
        return new DataResponse([
            'items' => array_map(fn($item) => $item->jsonSerialize(), $items),
        ]);
    }

    /**
     * Get feed items for a specific course.
     *
     * @NoAdminRequired
     */
    public function courseFeed(int $courseId): DataResponse {
        $items = $this->feedService->getCourseFeed($courseId);
        return new DataResponse([
            'items' => array_map(fn($item) => $item->jsonSerialize(), $items),
        ]);
    }
}
