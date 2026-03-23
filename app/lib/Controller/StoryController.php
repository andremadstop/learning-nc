<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Service\StoryEngineService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\IRequest;

/**
 * StoryController — REST API for the Story-RPG (v6.0 Abenteuer).
 *
 * Routes (all require authentication via @NoAdminRequired):
 *
 *   GET  /api/story/campaigns                         → listCampaigns
 *   POST /api/story/campaigns/{campaignId}/start      → startCampaign
 *   GET  /api/story/campaigns/{campaignId}/scene      → getScene
 *   GET  /api/story/campaigns/{campaignId}/scene/{sceneId}/questions/{choiceId}
 *                                                     → getSkillCheckQuestions
 *   POST /api/story/campaigns/{campaignId}/choice     → makeChoice
 *   POST /api/story/campaigns/{campaignId}/answer     → submitSkillAnswer
 *   POST /api/story/campaigns/{campaignId}/batch      → submitSkillBatch
 *   POST /api/story/campaigns/{campaignId}/freetext   → submitFreetext
 *   GET  /api/story/progress                          → listProgress
 */
class StoryController extends Controller {

    private StoryEngineService $service;
    private ?string $userId;

    public function __construct(
        string            $appName,
        IRequest          $request,
        StoryEngineService $service,
        ?string           $userId
    ) {
        parent::__construct($appName, $request);
        $this->service = $service;
        $this->userId  = $userId;
    }

    // ─── Campaign Discovery ──────────────────────────────────────────────────

    /**
     * List all available campaigns (lightweight metadata only).
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function listCampaigns(): DataResponse {
        try {
            return new DataResponse($this->service->listCampaigns());
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to list campaigns'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ─── Progress ────────────────────────────────────────────────────────────

    /**
     * List all campaign progress records for the current user.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function listProgress(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            // Delegate to service via a direct mapper call — lightweight, no campaign load
            $records = $this->service->listUserProgress($this->userId);
            return new DataResponse($records);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to load progress'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ─── Campaign Lifecycle ──────────────────────────────────────────────────

    /**
     * Start (or restart) a campaign for the current user.
     *
     * POST body:
     *   character_class  string  One of: architect|security|sysadmin|helpdesk
     *   coop_user_ids    string  Optional JSON array of additional user IDs
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function startCampaign(string $campaignId, string $characterClass = 'helpdesk', string $coopUserIds = '[]'): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            $coopIds = json_decode($coopUserIds, true) ?? [];
            if (!is_array($coopIds)) {
                $coopIds = [];
            }
            // Sanitize: only strings, deduplicate, remove self
            $coopIds = array_values(array_unique(array_filter(
                array_map('strval', $coopIds),
                fn(string $uid) => $uid !== '' && $uid !== $this->userId
            )));

            $result = $this->service->startCampaign($this->userId, $campaignId, $characterClass, $coopIds);
            return new DataResponse($result, Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to start campaign'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get the current scene for the user's active campaign session.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 60, period: 60)]
    public function getScene(string $campaignId): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            $result = $this->service->getScene($this->userId, $campaignId);
            return new DataResponse($result);
        } catch (\RuntimeException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to get scene'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ─── Skill Check Questions ────────────────────────────────────────────────

    /**
     * Fetch skill-check questions for a specific scene choice.
     * Called before the player commits to a choice (pre-load questions).
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 60, period: 60)]
    public function getSkillCheckQuestions(string $campaignId = '', string $sceneId = '', string $choiceId = ''): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            // Character class comes from user's active progress
            $characterClass = $this->service->getCharacterClass($this->userId, $campaignId);
            $questions = $this->service->getSkillCheckQuestions($campaignId, $sceneId, $choiceId, $characterClass);
            return new DataResponse(['questions' => $questions]);
        } catch (\RuntimeException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to fetch questions'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ─── Choice + Skill-Check Submission ─────────────────────────────────────

    /**
     * Make a story choice. If the choice has a skill check and the caller provides
     * question_id + answer_id, evaluates inline. Otherwise returns the scene with
     * pending skill-check questions for the caller to answer via /answer or /batch.
     *
     * POST body:
     *   choice_id    string  ID of the chosen option
     *   question_id  int     Optional — question answered (single-question inline check)
     *   answer_id    int     Optional — answer selected (paired with question_id)
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function makeChoice(
        string $campaignId,
        string $choiceId,
        int    $questionId = 0,
        int    $answerId   = 0
    ): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        if ($choiceId === '') {
            return new DataResponse(['error' => 'choice_id is required'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $result = $this->service->makeChoice(
                $this->userId,
                $campaignId,
                $choiceId,
                $questionId > 0 ? $questionId : null,
                $answerId   > 0 ? $answerId   : null
            );
            return new DataResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to process choice'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Submit a single skill-check answer and advance the story.
     *
     * POST body:
     *   choice_id    string
     *   question_id  int
     *   answer_id    int
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function submitSkillAnswer(
        string $campaignId,
        string $choiceId,
        int    $questionId,
        int    $answerId
    ): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        if ($choiceId === '' || $questionId <= 0 || $answerId <= 0) {
            return new DataResponse(['error' => 'choice_id, question_id and answer_id are required'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $result = $this->service->submitSkillAnswer($this->userId, $campaignId, $choiceId, $questionId, $answerId);
            return new DataResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to submit answer'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Submit a full skill-check batch (all questions for a choice at once).
     *
     * POST body:
     *   choice_id  string
     *   answers    JSON string: [{question_id: int, answer_id: int}, ...]
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function submitSkillBatch(
        string $campaignId,
        string $choiceId,
        string $answers = '[]'
    ): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        if ($choiceId === '') {
            return new DataResponse(['error' => 'choice_id is required'], Http::STATUS_BAD_REQUEST);
        }

        $answersDecoded = json_decode($answers, true);
        if (!is_array($answersDecoded)) {
            return new DataResponse(['error' => 'answers must be a valid JSON array'], Http::STATUS_BAD_REQUEST);
        }
        if (count($answersDecoded) === 0) {
            return new DataResponse(['error' => 'answers array must not be empty'], Http::STATUS_BAD_REQUEST);
        }
        if (count($answersDecoded) > 20) {
            return new DataResponse(['error' => 'Too many answers in batch (max 20)'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $result = $this->service->submitSkillBatch($this->userId, $campaignId, $choiceId, $answersDecoded);
            return new DataResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage() ?: 'Failed to submit batch'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Evaluate a free-text player action via Gemini narrator.
     * Gemini judges if the action is relevant to the learning objective and
     * generates a narrative response.
     *
     * POST body:
     *   text  string  Free-text action description (max 500 chars)
     *
     * @return DataResponse{valid: bool, narrative: string, next_scene: string|null, fallback: bool}
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 15, period: 60)]
    public function submitFreetext(string $campaignId, string $text = ''): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $text = trim($text);
        if ($text === '') {
            return new DataResponse(['error' => 'text is required'], Http::STATUS_BAD_REQUEST);
        }
        if (mb_strlen($text) > 500) {
            return new DataResponse(['error' => 'text must not exceed 500 characters'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $result = $this->service->submitFreetext($campaignId, $text, $this->userId);
            return new DataResponse($result);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            return new DataResponse(
                ['error' => $e->getMessage() ?: 'Failed to process free-text action'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }
}
