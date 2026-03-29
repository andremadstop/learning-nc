<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\CourseSummaryService;
use OCA\Learning\Service\CourseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http;
use OCP\IRequest;

class SummaryController extends Controller {
    private CourseSummaryService $summaryService;
    private CourseService $courseService;
    private ?string $userId;

    public function __construct(
        string $appName,
        IRequest $request,
        CourseSummaryService $summaryService,
        CourseService $courseService,
        ?string $userId
    ) {
        parent::__construct($appName, $request);
        $this->summaryService = $summaryService;
        $this->courseService = $courseService;
        $this->userId = $userId;
    }

    /**
     * Student: get own course summary. Instructor: get class summary.
     *
     * @NoAdminRequired
     */
    public function getSummary(int $courseId): DataResponse {
        try {
            $course = $this->courseService->findById($courseId, $this->userId);
            $isInstructor = !empty($course['is_instructor']);

            if ($isInstructor) {
                $data = $this->summaryService->getClassSummary($courseId);
            } else {
                $data = $this->summaryService->getStudentSummary($courseId, $this->userId);
            }

            return new DataResponse($data);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }
    }

    /**
     * Create a permanent snapshot of the student's course summary.
     *
     * @NoAdminRequired
     */
    public function createSnapshot(int $courseId): DataResponse {
        try {
            $id = $this->summaryService->createSnapshot($courseId, $this->userId);
            return new DataResponse(['id' => $id], Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get an existing snapshot.
     *
     * @NoAdminRequired
     */
    public function getSnapshot(int $courseId): DataResponse {
        $snapshot = $this->summaryService->getSnapshot($courseId, $this->userId);
        if (!$snapshot) {
            return new DataResponse(['error' => 'No snapshot found'], Http::STATUS_NOT_FOUND);
        }
        return new DataResponse($snapshot);
    }
}
