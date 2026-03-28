<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Db\CourseMapper;
use OCA\Learning\Service\FeedService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class FeedController extends Controller {
    private FeedService $feedService;
    private CourseMapper $courseMapper;
    private ?string $userId;

    public function __construct(
        string $appName,
        IRequest $request,
        FeedService $feedService,
        CourseMapper $courseMapper,
        ?string $userId
    ) {
        parent::__construct($appName, $request);
        $this->feedService = $feedService;
        $this->courseMapper = $courseMapper;
        $this->userId = $userId;
    }

    /**
     * Get aggregated feed for the current user across all enrolled courses.
     *
     * @NoAdminRequired
     */
    public function index(int $limit = 50, int $offset = 0): DataResponse {
        $items = $this->feedService->getUserFeed($this->userId, $limit, $offset);

        // Enrich items with course_name for global feed display
        $courseNames = [];
        $serialized = array_map(function ($item) use (&$courseNames) {
            $data = $item->jsonSerialize();
            $courseId = $data['course_id'];
            if (!isset($courseNames[$courseId])) {
                try {
                    $course = $this->courseMapper->findById($courseId);
                    $courseNames[$courseId] = $course->getName();
                } catch (\Exception $e) {
                    $courseNames[$courseId] = null;
                }
            }
            $data['course_name'] = $courseNames[$courseId];
            return $data;
        }, $items);

        return new DataResponse([
            'items' => $serialized,
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
