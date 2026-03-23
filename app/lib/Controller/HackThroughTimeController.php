<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\HackThroughTimeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\IRequest;

/**
 * HackThroughTimeController — REST API for the Zeitreise game mode (Phase 48).
 *
 * Routes (all require authentication via @NoAdminRequired):
 *
 *   GET  /api/zeitreise/epochs                          -> listEpochs
 *   GET  /api/zeitreise/progress                        -> getUserProgress
 *   POST /api/zeitreise/epochs/{epochId}/start           -> startEpoch
 *   GET  /api/zeitreise/epochs/{epochId}/museum          -> getMuseumFacts
 *   POST /api/zeitreise/epochs/{epochId}/museum/viewed   -> markMuseumViewed
 *   GET  /api/zeitreise/epochs/{epochId}/skill-check     -> getSkillCheck
 *   POST /api/zeitreise/epochs/{epochId}/skill-check     -> submitSkillCheck
 *   GET  /api/zeitreise/score                            -> getOverallScore
 */
class HackThroughTimeController extends Controller {

    private HackThroughTimeService $service;
    private ?string $userId;

    public function __construct(
        string                  $appName,
        IRequest                $request,
        HackThroughTimeService  $service,
        ?string                 $userId
    ) {
        parent::__construct($appName, $request);
        $this->service = $service;
        $this->userId  = $userId;
    }

    // ─── Epoch Discovery ────────────────────────────────────────────────────

    /**
     * List all 7 epochs with metadata.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function listEpochs(): DataResponse {
        try {
            return new DataResponse($this->service->listEpochs());
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to list epochs'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ─── Progress ───────────────────────────────────────────────────────────

    /**
     * Get all epoch progress for the current user.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function getUserProgress(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            return new DataResponse($this->service->getUserProgress($this->userId));
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to load progress'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ─── Epoch Lifecycle ────────────────────────────────────────────────────

    /**
     * Start (or restart) an epoch.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function startEpoch(string $epochId, string $characterClass = 'phreaker'): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            $result = $this->service->startEpoch($this->userId, $epochId, $characterClass);
            return new DataResponse($result, Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to start epoch'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ─── Museum ─────────────────────────────────────────────────────────────

    /**
     * Get museum facts for an epoch.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function getMuseumFacts(string $epochId): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            return new DataResponse($this->service->getMuseumFacts($this->userId, $epochId));
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to load museum facts'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Mark a museum fact as viewed.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function markMuseumViewed(string $epochId, string $factId = ''): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        if ($factId === '') {
            return new DataResponse(['error' => 'factId is required'], Http::STATUS_BAD_REQUEST);
        }
        try {
            return new DataResponse($this->service->markMuseumViewed($this->userId, $epochId, $factId));
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to mark fact viewed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ─── Skill Check ────────────────────────────────────────────────────────

    /**
     * Get skill-check questions for an epoch.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function getSkillCheck(string $epochId): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            return new DataResponse($this->service->getSkillCheck($this->userId, $epochId));
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to load skill check'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Submit skill-check answers for grading.
     *
     * POST body: answers = JSON string: [{question_id: int, answer_id: int}, ...]
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function submitSkillCheck(string $epochId, string $answers = '[]'): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $answersDecoded = json_decode($answers, true);
        if (!is_array($answersDecoded)) {
            return new DataResponse(['error' => 'answers must be a valid JSON array'], Http::STATUS_BAD_REQUEST);
        }
        if (count($answersDecoded) === 0) {
            return new DataResponse(['error' => 'answers array must not be empty'], Http::STATUS_BAD_REQUEST);
        }
        if (count($answersDecoded) > 20) {
            return new DataResponse(['error' => 'Too many answers (max 20)'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $result = $this->service->submitSkillCheck($this->userId, $epochId, $answersDecoded);
            return new DataResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to submit skill check'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ─── Overall Score ──────────────────────────────────────────────────────

    /**
     * Get overall score across all epochs.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function getOverallScore(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            return new DataResponse($this->service->getOverallScore($this->userId));
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to get score'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
