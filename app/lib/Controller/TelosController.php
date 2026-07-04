<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\TelosService;
use OCA\Learning\Service\CourseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * TelosController — Phase 75: Personal learning profile API.
 *
 * GET  /api/profile/telos           — get own telos
 * POST /api/profile/telos           — save telos (onboarding complete)
 * PUT  /api/profile/telos           — update individual fields
 * GET  /api/profile/telos/status    — check onboarding status
 * GET  /api/profile/telos/questions — get interview questions
 * POST /api/profile/telos/interview — process interview conversation
 * GET  /api/courses/{courseId}/telos — dozent: aggregated class profile
 */
class TelosController extends Controller {
    private ?string $userId;
    private TelosService $telosService;
    private CourseService $courseService;

    public function __construct(
        string $appName,
        IRequest $request,
        ?string $userId,
        TelosService $telosService,
        CourseService $courseService
    ) {
        parent::__construct($appName, $request);
        $this->userId = $userId;
        $this->telosService = $telosService;
        $this->courseService = $courseService;
    }

    /**
     * Get own telos profile.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function getTelos(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $telos = $this->telosService->getTelos($this->userId);
        if ($telos === null) {
            return new DataResponse(['onboarding_completed' => false, 'telos' => null]);
        }

        return new DataResponse($telos);
    }

    /**
     * Save full telos (after onboarding interview or form).
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function saveTelos(
        ?array $telos = null,
        ?string $bio = null,
        ?array $help_offer = null,
        ?array $help_wanted = null,
        ?string $visibility = null
    ): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        if ($telos === null || $telos === []) {
            return new DataResponse(['error' => 'Telos payload is required'], Http::STATUS_BAD_REQUEST);
        }

        $extra = [];
        if ($bio !== null) {
            $extra['bio'] = trim($bio);
        }
        $helpOffer = $this->normalizeStringList($help_offer);
        if ($helpOffer !== []) {
            $extra['help_offer'] = $helpOffer;
        }
        $helpWanted = $this->normalizeStringList($help_wanted);
        if ($helpWanted !== []) {
            $extra['help_wanted'] = $helpWanted;
        }
        if ($visibility !== null) {
            $extra['visibility'] = trim($visibility);
        }

        try {
            $result = $this->telosService->saveTelos($this->userId, $telos, $extra);
            return new DataResponse($result);
        } catch (\Throwable $e) {
            return new DataResponse(['error' => 'Could not save telos'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update individual telos fields.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function updateTelos(
        ?array $telos = null,
        ?string $bio = null,
        ?array $help_offer = null,
        ?array $help_wanted = null,
        ?string $visibility = null
    ): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $fields = [];
        if ($telos !== null) {
            $fields['telos'] = $telos;
        }
        if ($bio !== null) {
            $fields['bio'] = trim($bio);
        }
        if ($help_offer !== null) {
            $fields['help_offer'] = $this->normalizeStringList($help_offer);
        }
        if ($help_wanted !== null) {
            $fields['help_wanted'] = $this->normalizeStringList($help_wanted);
        }
        if ($visibility !== null) {
            $fields['visibility'] = trim($visibility);
        }

        if (empty($fields)) {
            return new DataResponse(['error' => 'No fields to update'], Http::STATUS_BAD_REQUEST);
        }

        $result = $this->telosService->updateFields($this->userId, $fields);
        if ($result === null) {
            return new DataResponse(['error' => 'No telos profile found — complete onboarding first'], Http::STATUS_NOT_FOUND);
        }

        return new DataResponse($result);
    }

    /**
     * Check onboarding status (lightweight, no full telos data).
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 60, period: 60)]
    public function getStatus(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        return new DataResponse([
            'onboarding_completed' => $this->telosService->hasCompletedOnboarding($this->userId),
        ]);
    }

    /**
     * Get current AI consent version for the user.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function getConsentStatus(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        return new DataResponse([
            'ai_consent_version' => $this->telosService->getAiConsentVersion($this->userId),
        ]);
    }

    /**
     * Save AI consent version (user accepted the consent dialog).
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function saveConsent(?string $version = null): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        if ($version === null || trim($version) === '') {
            return new DataResponse(['error' => 'Version is required'], Http::STATUS_BAD_REQUEST);
        }

        $version = trim($version);
        if (strlen($version) > 20) {
            return new DataResponse(['error' => 'Version too long'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $this->telosService->saveAiConsent($this->userId, $version);
            return new DataResponse(['status' => 'ok']);
        } catch (\Throwable $e) {
            return new DataResponse(['error' => 'Could not save consent'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get interview questions for the onboarding flow.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function getQuestions(): DataResponse {
        return new DataResponse([
            'questions' => $this->telosService->getInterviewQuestions(),
        ]);
    }

    /**
     * Process interview conversation text via Gemini.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 5, period: 60)]
    public function processInterview(?string $conversation = null): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        if ($conversation === null) {
            return new DataResponse(['error' => 'Conversation is required'], Http::STATUS_BAD_REQUEST);
        }

        // AUDIT v5.2.1 (HIGH-03 consistency, pre-live review R2): the Telos interview sends free-text
        // conversation to Gemini — it is a user-triggered LLM path and must be consent-gated like the
        // others (this endpoint previously reached Gemini with no per-user consent check at all).
        if (!$this->telosService->hasAiConsent($this->userId)) {
            return new DataResponse(['error' => 'AI consent required', 'consent_required' => true], Http::STATUS_FORBIDDEN);
        }

        $conversation = trim($conversation);

        if (strlen($conversation) < 20) {
            return new DataResponse(['error' => 'Conversation too short'], Http::STATUS_BAD_REQUEST);
        }
        if (strlen($conversation) > 10000) {
            return new DataResponse(['error' => 'Conversation too long (max 10000 chars)'], Http::STATUS_BAD_REQUEST);
        }

        $result = $this->telosService->processInterview($this->userId, $conversation);

        if (!$result['saved']) {
            return new DataResponse([
                'error' => $result['error'] ?? 'Interview processing failed',
                'telos' => $result['telos'],
            ], Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        return new DataResponse($result);
    }

    /**
     * Dozent: Get aggregated class telos profile for a course.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 15, period: 60)]
    public function getCourseTelosAggregate(int $courseId): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $course = $this->courseService->findById($courseId, $this->userId);
            if (($course['is_instructor'] ?? false) !== true) {
                return new DataResponse(['error' => 'Only the course instructor can view class profiles'], Http::STATUS_FORBIDDEN);
            }

            $members = $this->courseService->getMembers($courseId, $this->userId);
            $userIds = array_values(array_column(array_filter(
                $members,
                static fn(array $member): bool => ($member['role'] ?? '') === 'student'
            ), 'user_id'));

            $aggregate = $this->telosService->aggregateForCourse($userIds);

            return new DataResponse($aggregate);
        } catch (\Throwable $e) {
            return new DataResponse(['error' => 'Could not aggregate telos data'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get study buddy matches for a course based on help_offer/help_wanted overlap.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 15, period: 60)]
    public function getCourseBuddies(int $courseId): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            // Verify enrollment: findById throws DoesNotExistException if no access
            $this->courseService->findById($courseId, $this->userId);

            $buddies = $this->telosService->getCourseBuddies($this->userId, $courseId);
            return new DataResponse($buddies);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return new DataResponse(['error' => 'Could not load buddies'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @param array<int, mixed>|null $values
     * @return string[]
     */
    private function normalizeStringList(?array $values): array {
        if ($values === null) {
            return [];
        }

        $normalized = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }
}
