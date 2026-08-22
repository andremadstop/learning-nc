<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Db\UserTelosMapper;
use OCA\Learning\Service\CourseSummaryService;
use OCA\Learning\Service\CourseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http;
use OCP\IRequest;

class SummaryController extends Controller {
    private CourseSummaryService $summaryService;
    private CourseService $courseService;
    private UserTelosMapper $telosMapper;
    private ?string $userId;

    public function __construct(
        string $appName,
        IRequest $request,
        CourseSummaryService $summaryService,
        CourseService $courseService,
        UserTelosMapper $telosMapper,
        ?string $userId
    ) {
        parent::__construct($appName, $request);
        $this->summaryService = $summaryService;
        $this->courseService = $courseService;
        $this->telosMapper = $telosMapper;
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
        // findById() is the course access gate — every other endpoint in this controller passes
        // through it first. This one did not, so any logged-in user could snapshot an arbitrary
        // course id and read back what it contains.
        try {
            $this->courseService->findById($courseId, $this->userId);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'No access to this course'], Http::STATUS_FORBIDDEN);
        }

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

    /**
     * Generate AI narrative portfolio for the student's course end summary.
     * Cached in snapshot on first generation. Returns null narrative if Gemini unconfigured.
     *
     * @NoAdminRequired
     */
    public function generateNarrative(int $courseId): DataResponse {
        try {
            $course = $this->courseService->findById($courseId, $this->userId);
            if (!empty($course['is_instructor'])) {
                return new DataResponse(['error' => 'Students only'], Http::STATUS_FORBIDDEN);
            }
            // DSGVO: Require AI consent before sending data to Gemini
            $telos = $this->telosMapper->findByUserIdOrNull($this->userId);
            if (!$telos || empty($telos->getAiConsentVersion())) {
                return new DataResponse(['narrative' => null, 'cached' => false, 'consent_required' => true]);
            }
            $narrative = $this->summaryService->generateAndCacheNarrative($courseId, $this->userId);
            return new DataResponse(['narrative' => $narrative, 'cached' => $narrative !== null]);
        } catch (\Exception $e) {
            return new DataResponse(['narrative' => null, 'cached' => false]);
        }
    }

    /**
     * Export class summary as CSV (instructor only).
     *
     * @NoAdminRequired
     */
    public function exportCsv(int $courseId): Http\Response {
        try {
            $course = $this->courseService->findById($courseId, $this->userId);
            if (empty($course['is_instructor'])) {
                return new DataResponse(['error' => 'Instructor only'], Http::STATUS_FORBIDDEN);
            }

            $data = $this->summaryService->getClassSummary($courseId);
            $handle = fopen('php://temp', 'r+');
            fputcsv($handle, ['User', 'XP', 'Level', 'Accuracy (%)', 'Mastery (%)', 'Sessions', 'Streak', 'Badges', 'Duels Won', 'Swarm Contributions']);

            foreach ($data['students'] as $uid => $s) {
                fputcsv($handle, [
                    $uid,
                    $s['xp']['total_xp'] ?? 0,
                    $s['xp']['current_level'] ?? 1,
                    $s['sessions']['overall_accuracy'] ?? 0,
                    $s['mastery']['mastery_rate'] ?? 0,
                    $s['sessions']['total_sessions'] ?? 0,
                    $s['streak']['current_streak'] ?? 0,
                    count($s['badges'] ?? []),
                    $s['duels']['wins'] ?? 0,
                    $s['swarm']['approved_contributions'] ?? 0,
                ]);
            }

            rewind($handle);
            $csv = stream_get_contents($handle);
            fclose($handle);

            return new DataDownloadResponse($csv, 'class-summary-course-' . $courseId . '.csv', 'text/csv');
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }
    }
}
