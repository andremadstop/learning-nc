<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\LernprofilService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * LernprofilController — Phase 22: Persönlicher Lernbot
 *
 * Exposes the user's learning profile via REST API.
 *
 * GET /api/profile           — full profile (weakest topics + history + summary)
 * GET /api/profile/weakest   — top 5 weakest pools
 * GET /api/profile/history   — daily learning history
 */
class LernprofilController extends Controller {
    private ?string $userId;
    private LernprofilService $lernprofilService;
    private LoggerInterface $logger;

    public function __construct(
        string $appName,
        IRequest $request,
        ?string $userId,
        LernprofilService $lernprofilService,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
        $this->userId = $userId;
        $this->lernprofilService = $lernprofilService;
        $this->logger = $logger;
    }

    /**
     * Returns the full learning profile: weakest topics, history summary, overall stats.
     *
     * @NoAdminRequired
     * @param int|null $courseId  Optional course filter
     * @param int      $days      History window in days (default 30)
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function profile(?int $courseId = null, int $days = 30): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $weakest = $this->lernprofilService->getWeakestTopics($this->userId, $courseId, 5);
            $history = $this->lernprofilService->getLernhistorie($this->userId, $days);
            $profile = $this->lernprofilService->aggregateProfile($this->userId, $courseId);

            return new DataResponse([
                'weakest_topics' => $weakest,
                'lernhistorie' => $history,
                'summary' => $profile['summary'],
                'cached_at' => $profile['cached_at'],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('LernprofilController::profile failed: ' . $e->getMessage(), ['exception' => $e, 'app' => 'learning']);
            return new DataResponse(['error' => 'Profile aggregation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Returns the top N weakest pools for the user.
     *
     * @NoAdminRequired
     * @param int|null $courseId Optional course filter
     * @param int      $limit    Max topics to return (1-20, default 5)
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function weakestTopics(?int $courseId = null, int $limit = 5): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $topics = $this->lernprofilService->getWeakestTopics($this->userId, $courseId, $limit);
            return new DataResponse(['topics' => $topics]);
        } catch (\Throwable $e) {
            $this->logger->error('LernprofilController::weakest failed: ' . $e->getMessage(), ['exception' => $e, 'app' => 'learning']);
            return new DataResponse(['error' => 'Could not retrieve weakest topics'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Returns the daily learning history for the user.
     *
     * @NoAdminRequired
     * @param int $days History window in days (1-365, default 30)
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function lernhistorie(int $days = 30): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $history = $this->lernprofilService->getLernhistorie($this->userId, $days);
            return new DataResponse($history);
        } catch (\Throwable $e) {
            $this->logger->error('LernprofilController::history failed: ' . $e->getMessage(), ['exception' => $e, 'app' => 'learning']);
            return new DataResponse(['error' => 'Could not retrieve learning history'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Returns a cheat-sheet of trouble-spot questions (accuracy <50%)
     * across all enrolled courses, enriched with correct answers and explanations.
     *
     * @NoAdminRequired
     * @param int $limit Max questions (1-100, default 50)
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function cheatSheet(int $limit = 50): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $data = $this->lernprofilService->getCheatSheet($this->userId, $limit);
            return new DataResponse($data);
        } catch (\Throwable $e) {
            $this->logger->error('LernprofilController::cheatSheet failed: ' . $e->getMessage(), ['exception' => $e, 'app' => 'learning']);
            return new DataResponse(['error' => 'Cheat sheet generation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Returns skill-map data: all pools enriched with course grouping.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function skillMap(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $profile = $this->lernprofilService->aggregateProfile($this->userId);
            $pools = $profile['pools'];

            // Enrich pools with course_id and course_name
            $enrichedPools = $this->lernprofilService->enrichPoolsWithCourseData($this->userId, $pools);

            // Extract unique courses
            $coursesMap = [];
            foreach ($enrichedPools as $pool) {
                if (isset($pool['course_id'])) {
                    $coursesMap[$pool['course_id']] = $pool['course_name'] ?? ('Course ' . $pool['course_id']);
                }
            }
            $courses = [];
            foreach ($coursesMap as $id => $name) {
                $courses[] = ['id' => $id, 'name' => $name];
            }

            return new DataResponse([
                'courses' => $courses,
                'pools' => $enrichedPools,
                'summary' => $profile['summary'],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('LernprofilController::skillMap failed: ' . $e->getMessage(), ['exception' => $e, 'app' => 'learning']);
            return new DataResponse(['error' => 'Skill map data could not be retrieved'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
