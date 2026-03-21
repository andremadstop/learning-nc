<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\LernprofilService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

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

    public function __construct(
        string $appName,
        IRequest $request,
        ?string $userId,
        LernprofilService $lernprofilService
    ) {
        parent::__construct($appName, $request);
        $this->userId = $userId;
        $this->lernprofilService = $lernprofilService;
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
            return new DataResponse(['error' => 'Could not retrieve learning history'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
