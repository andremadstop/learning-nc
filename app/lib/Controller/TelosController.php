<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\Llm\LlmConsentException;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\Llm\ConsentRecipientChangedException;
use OCA\Learning\Service\Llm\ConsentService;
use OCA\Learning\Service\Llm\LlmConnection;
use OCA\Learning\Service\Llm\LlmPurpose;
use OCA\Learning\Service\Llm\LlmSession;
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
    private ConsentService $consentService;
    private ConnectionStore $connectionStore;

    public function __construct(
        string $appName,
        IRequest $request,
        ?string $userId,
        TelosService $telosService,
        CourseService $courseService,
        ConsentService $consentService,
        ConnectionStore $connectionStore
    ) {
        parent::__construct($appName, $request);
        $this->consentService = $consentService;
        $this->connectionStore = $connectionStore;
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
        array|string|null $telos = null,
        ?string $bio = null,
        array|string|null $help_offer = null,
        array|string|null $help_wanted = null,
        ?string $visibility = null
    ): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        if (!is_array($telos) || $telos === []) {
            return new DataResponse(['error' => 'Telos payload is required'], Http::STATUS_BAD_REQUEST);
        }

        $extra = [];
        if ($bio !== null) {
            $extra['bio'] = trim($bio);
        }
        // An omitted field (null) means "leave as is"; a supplied but empty one means "clear".
        // These two used to be collapsed — the normalized list was only forwarded when it was
        // NON-empty, so emptying the help textarea and saving silently kept the old value.
        // That is a full save, and the help topics are published to classmates through
        // ClassbookService, so a user could not withdraw a topic once shared. Same distinction
        // updateTelos() already made.
        if ($help_offer !== null) {
            $extra['help_offer'] = $this->normalizeStringList($help_offer);
        }
        if ($help_wanted !== null) {
            $extra['help_wanted'] = $this->normalizeStringList($help_wanted);
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
        array|string|null $telos = null,
        ?string $bio = null,
        array|string|null $help_offer = null,
        array|string|null $help_wanted = null,
        ?string $visibility = null
    ): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        // Reject a malformed telos BEFORE assembling anything else. Merely skipping it would
        // let a mixed payload half-save — {"telos": "oops", "bio": "changed"} would store the
        // bio and answer 200, which is worse than the TypeError it replaced.
        if ($telos !== null && !is_array($telos)) {
            return new DataResponse(['error' => 'Telos payload must be an object'], Http::STATUS_BAD_REQUEST);
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
     * AI consent state for the current connection (spec 3.3): whether it is valid, why not, and
     * the recipient the user would consent to. The client sends that recipient's fingerprint
     * back with the consent; the server never fills it in itself.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function getConsentStatus(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $connection = $this->connectionStore->load();
        $state = $this->consentService->state($this->userId, $connection);
        return new DataResponse([
            'valid' => $state['valid'],
            'reason' => $state['reason'],
            'source' => $state['source'],
            'ai_consent_version' => $this->telosService->getAiConsentVersion($this->userId),
            'current_version' => $this->consentService->currentVersion(),
            'ai_enabled' => self::isActive($connection),
            'recipient' => self::recipientData($connection),
            // "changed from {old} to {new}": only for recipient_changed, null when the old name is unknown
            'previous_recipient_name' => $state['reason'] === 'recipient_changed'
                ? $this->consentService->previousRecipientName($this->userId, $connection)
                : null,
        ]);
    }

    /**
     * Store the AI consent the user gave for the recipient they were shown.
     * 409 when the admin changed the recipient meanwhile (with the new one), 400 for an outdated
     * version, an unknown source or AI switched off.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function saveConsent(?string $version = null, ?string $recipient_fingerprint = null, string $source = 'dialog'): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        $version = trim((string)$version);
        $fingerprint = trim((string)$recipient_fingerprint);
        if ($version === '' || strlen($version) > 20) {
            return new DataResponse(['error' => 'Version is required'], Http::STATUS_BAD_REQUEST);
        }
        if ($fingerprint === '' || strlen($fingerprint) > 64) {
            return new DataResponse(['error' => 'Recipient fingerprint is required'], Http::STATUS_BAD_REQUEST);
        }

        $connection = $this->connectionStore->load();
        try {
            $this->consentService->grant($this->userId, $connection, $fingerprint, $version, $source);
        } catch (ConsentRecipientChangedException $e) {
            return new DataResponse([
                'error' => 'recipient_changed',
                'reason' => 'recipient_changed',
                'recipient' => self::recipientData($connection),
            ], Http::STATUS_CONFLICT);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return new DataResponse(['error' => 'Could not save consent'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        return new DataResponse(['status' => 'ok', 'valid' => true]);
    }

    /**
     * Revoke the AI consent. Takes effect for calls already under way: the gateway re-reads the
     * consent epoch right before sending.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function revokeConsent(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            $this->consentService->revoke($this->userId);
        } catch (\Throwable $e) {
            return new DataResponse(['error' => 'Could not revoke consent'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        return new DataResponse(['status' => 'ok', 'valid' => false]);
    }

    private static function isActive(LlmConnection $c): bool {
        return $c->enabled && $c->provider !== 'disabled';
    }

    /**
     * The recipient as the consent dialog explains it (spec 3.4).
     *
     * @return array{name: string, region: string, processing_local: bool, intermediary: bool, dpa_url: ?string, warning_key: ?string, fingerprint: string}|null
     */
    private static function recipientData(LlmConnection $c): ?array {
        if (!self::isActive($c)) {
            return null;
        }
        $recipient = (new LlmSession($c, null, LlmPurpose::AdminTest))->recipient();
        return [
            'name' => $recipient->name,
            'region' => $recipient->region,
            'processing_local' => $recipient->isLocal,
            'intermediary' => $recipient->intermediary,
            'dpa_url' => $recipient->dpaUrl,
            'warning_key' => $recipient->warningKey,
            'fingerprint' => $c->recipientFingerprint(),
        ];
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

        try {
            $result = $this->telosService->processInterview($this->userId, $conversation);
        } catch (LlmConsentException $e) {
            // consent revoked or recipient changed after the check above; nothing was sent
            return new DataResponse(['error' => 'AI consent required', 'consent_required' => true], Http::STATUS_FORBIDDEN);
        }

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
     * @param array<int, mixed>|string|null $values
     * @return string[]
     */
    private function normalizeStringList(array|string|null $values): array {
        if ($values === null) {
            return [];
        }

        // Codeberg #4: the NC dispatcher passes request parameters through untyped, so this field
        // arrives as an array from the profile form but as the raw textarea string from clients that
        // post it unsplit (the onboarding wizard sent ''). A strict ?array hint turned that into a
        // TypeError *before* the method body ran — an uncatchable 500 that killed the whole save.
        // Split on the same separators as the frontend's splitListValue() so both sides agree.
        if (is_string($values)) {
            $values = preg_split('/[\n,;]+/', $values) ?: [];
        }

        $normalized = [];
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $value = trim((string)$value);
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }
}
