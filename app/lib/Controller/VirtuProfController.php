<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\AiChatMemoryService;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\GeminiService;
use OCA\Learning\Service\LernplanService;
use OCA\Learning\Service\NoteGeneratorService;
use OCA\Learning\Service\PoolService;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\RagContextService;
use OCA\Learning\Service\SupportTicketService;
use OCA\Learning\Service\TelosService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserManager;

class VirtuProfController extends Controller {
    private const ALLOWED_INTERFACE_LANGUAGES = ['', 'de', 'en', 'ru', 'ar'];
    private const ALLOWED_SKINS = [
        'nova',
        'prof_lern_classic',
        'theoretiker',
        'kosmologe',
        'popularisierer',
    ];

    // MIGR-01/02 (Phase 153): First-touch-coercion via IConfig::getUserKeys()
    // existence signal. DO NOT replace with IUser::getLastLogin() — login() updates
    // lastLogin BEFORE controllers run, causing existing users to falsely look "new"
    // on their first post-deploy login → silent flip to classic = Nova-Removal-Trauma.
    // See .planning/phases/153-migration-tests-deploy-app-store/153-RESEARCH.md Pitfall 5.
    private const NEW_USER_DEFAULT_SKIN = 'prof_lern_classic';
    private const LEGACY_USER_DEFAULT_SKIN = 'nova';

    private const ALLOWED_VOICE_LANGUAGES = [
        'de-DE',
        'en-US',
        'ru-RU',
        'ar-SA',
        'tr-TR',
        'fr-FR',
        'es-ES',
        'zh-CN',
        'ja-JP',
        'ko-KR',
        'pt-BR',
        'it-IT',
        'pl-PL',
        'nl-NL',
        'uk-UA',
    ];
    private const VOICE_LANGUAGE_MAP = [
        'de' => 'de-DE',
        'en' => 'en-US',
        'ru' => 'ru-RU',
        'ar' => 'ar-SA',
        'tr' => 'tr-TR',
        'fr' => 'fr-FR',
        'es' => 'es-ES',
        'zh' => 'zh-CN',
        'ja' => 'ja-JP',
        'ko' => 'ko-KR',
        'pt' => 'pt-BR',
        'it' => 'it-IT',
        'pl' => 'pl-PL',
        'nl' => 'nl-NL',
        'uk' => 'uk-UA',
    ];

    private IConfig $config;
    private ?string $userId;
    private GeminiService $geminiService;
    private RagContextService $ragContextService;
    private AiChatMemoryService $chatMemoryService;
    private NoteGeneratorService $noteGeneratorService;
    private LernplanService $lernplanService;
    private SupportTicketService $ticketService;
    private TelosService $telosService;
    private IUserManager $userManager;
    private CourseService $courseService;
    private PoolService $poolService;
    private QuestionService $questionService;

    public function __construct(
        string $appName,
        IRequest $request,
        IConfig $config,
        ?string $userId,
        GeminiService $geminiService,
        RagContextService $ragContextService,
        AiChatMemoryService $chatMemoryService,
        NoteGeneratorService $noteGeneratorService,
        LernplanService $lernplanService,
        SupportTicketService $ticketService,
        TelosService $telosService,
        IUserManager $userManager,
        CourseService $courseService,
        PoolService $poolService,
        QuestionService $questionService
    ) {
        parent::__construct($appName, $request);
        $this->config = $config;
        $this->userId = $userId;
        $this->geminiService = $geminiService;
        $this->ragContextService = $ragContextService;
        $this->chatMemoryService = $chatMemoryService;
        $this->noteGeneratorService = $noteGeneratorService;
        $this->lernplanService = $lernplanService;
        $this->ticketService = $ticketService;
        $this->telosService = $telosService;
        $this->userManager = $userManager;
        $this->courseService = $courseService;
        $this->poolService = $poolService;
        $this->questionService = $questionService;
    }

    /**
     * AUDIT HIGH-03 (DSGVO): every user-triggered LLM path must confirm the per-user AI
     * consent BEFORE any data reaches Gemini — the admin ai_enabled toggle alone is NOT
     * sufficient (mirrors SummaryController's consent gate). Returns true when consent is
     * absent, in which case the caller returns a `consent_required` response.
     */
    private function aiConsentMissing(): bool {
        return $this->userId === null
            || empty($this->telosService->getAiConsentVersion($this->userId));
    }

    /**
     * AUDIT HIGH-02 (IDOR): the frontend supplies poolId/courseId/lastWrongQuestionId as raw
     * learning-context hints. RagContextService loads pool questions (with answers), course
     * material chunks and the last-wrong correct answer WITHOUT an access check, so a crafted
     * request could pull foreign course content and answer keys. We drop (null out) any context
     * id the user cannot access BEFORE it reaches RAG/file-intent — the chat still answers
     * generically, and dropping (vs. 403) avoids leaking which ids exist. userId-scoped context
     * (Leitner stats, weaknesses) stays safe by construction.
     *
     * @return array{0: ?int, 1: ?int, 2: ?int} filtered [poolId, courseId, lastWrongQuestionId]
     */
    private function filterAccessibleContext(?int $poolId, ?int $courseId, ?int $lastWrongQuestionId): array {
        $userId = (string)$this->userId;

        if ($courseId !== null) {
            try {
                $this->courseService->findById($courseId, $userId);
            } catch (\Throwable $e) {
                $courseId = null;
            }
        }
        if ($poolId !== null) {
            try {
                $this->poolService->findByIdWithShareAccess($poolId, $userId);
            } catch (\Throwable $e) {
                $poolId = null;
            }
        }
        if ($lastWrongQuestionId !== null) {
            try {
                $this->questionService->find($lastWrongQuestionId, $userId);
            } catch (\Throwable $e) {
                $lastWrongQuestionId = null;
            }
        }

        return [$poolId, $courseId, $lastWrongQuestionId];
    }

    /**
     * Get the user's first name (display name up to first space).
     */
    private function getUserFirstName(): string {
        if ($this->userId === null) {
            return '';
        }
        $user = $this->userManager->get($this->userId);
        if ($user === null) {
            return '';
        }
        $displayName = $user->getDisplayName();
        $parts = explode(' ', trim($displayName), 2);
        return $parts[0] ?? '';
    }

    /**
     * @return array{dismissed: array<int, string>, enabled: bool, language: string, visited_tools: array<int, string>, ai_enabled: bool, tts_enabled: bool, stt_enabled: bool, voice_lang: string, onboarding_reminder_count: int, onboarding_declined: bool, skin: string}
     */
    private function buildStatePayload(): array {
        $dismissed = json_decode(
            $this->config->getUserValue($this->userId, 'learning', 'virtuprof_dismissed', '[]'),
            true
        );
        $visitedTools = json_decode(
            $this->config->getUserValue($this->userId, 'learning', 'visited_tools', '[]'),
            true
        );

        return [
            'dismissed' => is_array($dismissed) ? array_values($dismissed) : [],
            'enabled' => $this->config->getUserValue($this->userId, 'learning', 'virtuprof_enabled', 'yes') === 'yes',
            'language' => $this->normalizeInterfaceLanguage(
                $this->config->getUserValue($this->userId, 'learning', 'virtuprof_language', '')
            ),
            'visited_tools' => is_array($visitedTools) ? array_values($visitedTools) : [],
            'ai_enabled' => $this->isAiFeatureAvailable(),
            'tts_enabled' => $this->getTtsEnabled(),
            'stt_enabled' => $this->getSttEnabled(),
            'voice_lang' => $this->getVoiceLanguage(),
            'onboarding_reminder_count' => $this->getOnboardingReminderCount(),
            'onboarding_declined' => $this->config->getUserValue($this->userId, 'learning', 'onboarding_declined', 'no') === 'yes',
            'skin' => $this->getSkin(),
        ];
    }

    private function getTtsEnabled(): bool {
        return $this->config->getUserValue($this->userId, 'learning', 'virtuprof_tts_enabled', 'no') === 'yes';
    }

    private function getSttEnabled(): bool {
        return $this->config->getUserValue($this->userId, 'learning', 'virtuprof_stt_enabled', 'no') === 'yes';
    }

    private function getVoiceLanguage(): string {
        $stored = $this->normalizeVoiceLanguage(
            $this->config->getUserValue($this->userId, 'learning', 'virtuprof_voice_lang', '')
        );
        if ($stored !== '') {
            return $stored;
        }

        $contentLanguage = strtolower(trim($this->config->getUserValue($this->userId, 'learning', 'content_language', '')));
        if (isset(self::VOICE_LANGUAGE_MAP[$contentLanguage])) {
            return self::VOICE_LANGUAGE_MAP[$contentLanguage];
        }

        $interfaceLanguage = $this->normalizeInterfaceLanguage(
            $this->config->getUserValue($this->userId, 'learning', 'virtuprof_language', '')
        );
        return self::VOICE_LANGUAGE_MAP[$interfaceLanguage] ?? '';
    }

    private function getOnboardingReminderCount(): int {
        $raw = (int)$this->config->getUserValue($this->userId, 'learning', 'onboarding_reminder_count', '0');
        return max(0, min(3, $raw));
    }

    /**
     * Resolve the user's VirtuProf skin with first-touch-coercion (MIGR-01/02).
     *
     * Fast path: if a `virtuprof_skin` row exists, return it (after allowlist
     * sanitization). Existing user choices are preserved verbatim.
     *
     * Slow path (first touch only): if no row exists, peek at the user's
     * `learning.*` user_config keyset. Non-empty → user has interacted with
     * the learning app before (consent, language, telos, etc.) → resolve to
     * 'nova' (Zero-Change-Default for legacy users). Empty → brand-new account
     * → resolve to 'prof_lern_classic' (new-user default per v4.4.0 milestone).
     *
     * The resolved value is written back so subsequent reads hit the fast path
     * (O(1) on the row, not O(N) on the keyset).
     */
    private function getSkin(): string {
        // Sentinel '' means "no row exists" — distinct from any allowlisted skin id.
        $existing = $this->config->getUserValue(
            $this->userId, 'learning', 'virtuprof_skin', ''
        );
        if ($existing !== '') {
            // Fast path: existing row → return as-is (after allowlist sanitization).
            // Existing users with prior explicit choices keep that choice.
            return $this->normalizeSkin($existing);
        }
        // No row exists — apply first-touch-coercion via existence-signal.
        // Any non-empty key-set under 'learning' app means the user has interacted
        // with the learning app before (consent, language, telos, exam_date, etc.).
        // Empty key-set means a fresh account that has never touched the app.
        $existingKeys = $this->config->getUserKeys($this->userId, 'learning');
        $resolved = empty($existingKeys)
            ? self::NEW_USER_DEFAULT_SKIN     // brand-new user
            : self::LEGACY_USER_DEFAULT_SKIN; // existing user, just never picked a skin

        // Write-once so we never re-evaluate (and so subsequent reads are O(1) on the row).
        $this->config->setUserValue(
            $this->userId, 'learning', 'virtuprof_skin', $resolved
        );

        return $this->normalizeSkin($resolved);
    }

    private function normalizeInterfaceLanguage(string $language): string {
        $normalized = strtolower(trim($language));
        return in_array($normalized, self::ALLOWED_INTERFACE_LANGUAGES, true) ? $normalized : '';
    }

    private function normalizeVoiceLanguage(string $language): string {
        $normalized = trim($language);
        return in_array($normalized, self::ALLOWED_VOICE_LANGUAGES, true) ? $normalized : '';
    }

    private function normalizeSkin(string $skin): string {
        return in_array($skin, self::ALLOWED_SKINS, true) ? $skin : 'nova';
    }

    private function isAiFeatureAvailable(): bool {
        if ($this->config->getAppValue('learning', 'ai_enabled', 'no') !== 'yes') {
            return false;
        }

        return $this->geminiService->isAvailable();
    }

    /**
     * @param array<int, mixed> $history
     * @return array<int, array{question: string, answer: string}>
     */
    private function sanitizeInterviewHistory(array $history): array {
        $sanitized = [];
        foreach (array_slice($history, 0, 10) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $question = mb_substr(trim(strip_tags((string)($entry['question'] ?? ''))), 0, 240);
            $answer = mb_substr(trim(strip_tags((string)($entry['answer'] ?? ''))), 0, 500);
            if ($question === '' || $answer === '') {
                continue;
            }

            $sanitized[] = [
                'question' => $question,
                'answer' => $answer,
            ];
        }

        return $sanitized;
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function getState(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        return new DataResponse($this->buildStatePayload());
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 120, period: 60)]
    public function markVisited(string $guideKey): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $guideKey = strtolower(trim($guideKey));
        if ($guideKey === '' || !preg_match('/^[a-z0-9:_-]{1,64}$/', $guideKey)) {
            return new DataResponse(['error' => 'Invalid guide key'], Http::STATUS_BAD_REQUEST);
        }

        $visitedTools = json_decode(
            $this->config->getUserValue($this->userId, 'learning', 'visited_tools', '[]'),
            true
        );
        if (!is_array($visitedTools)) {
            $visitedTools = [];
        }

        if (!in_array($guideKey, $visitedTools, true)) {
            $visitedTools[] = $guideKey;
            $this->config->setUserValue($this->userId, 'learning', 'visited_tools', json_encode(array_values($visitedTools)));
        }

        return new DataResponse([
            'ok' => true,
            'visited_tools' => array_values($visitedTools),
        ]);
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 60, period: 60)]
    public function dismiss(string $triggerId): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $triggerId = trim($triggerId);
        if ($triggerId === '') {
            return new DataResponse(['error' => 'Trigger ID is required'], Http::STATUS_BAD_REQUEST);
        }

        $dismissed = json_decode(
            $this->config->getUserValue($this->userId, 'learning', 'virtuprof_dismissed', '[]'),
            true
        );

        if (!is_array($dismissed)) {
            $dismissed = [];
        }

        if (!in_array($triggerId, $dismissed, true)) {
            $dismissed[] = $triggerId;
            $this->config->setUserValue($this->userId, 'learning', 'virtuprof_dismissed', json_encode(array_values($dismissed)));
        }

        return new DataResponse(['ok' => true, 'dismissed' => array_values($dismissed)]);
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function setEnabled(bool $enabled): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $this->config->setUserValue($this->userId, 'learning', 'virtuprof_enabled', $enabled ? 'yes' : 'no');

        return new DataResponse(['ok' => true, 'enabled' => $enabled]);
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function setLanguage(string $language): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $normalized = $this->normalizeInterfaceLanguage($language);

        $this->config->setUserValue($this->userId, 'learning', 'virtuprof_language', $normalized);

        return new DataResponse(['ok' => true, 'language' => $normalized]);
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function savePreferences(
        ?bool $ttsEnabled = null,
        ?bool $sttEnabled = null,
        ?string $voiceLang = null,
        ?int $onboardingReminderCount = null,
        ?bool $onboardingDeclined = null,
        ?string $skin = null
    ): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        if ($onboardingDeclined !== null) {
            $this->config->setUserValue($this->userId, 'learning', 'onboarding_declined', $onboardingDeclined ? 'yes' : 'no');
        }
        if ($ttsEnabled !== null) {
            $this->config->setUserValue($this->userId, 'learning', 'virtuprof_tts_enabled', $ttsEnabled ? 'yes' : 'no');
        }
        if ($sttEnabled !== null) {
            $this->config->setUserValue($this->userId, 'learning', 'virtuprof_stt_enabled', $sttEnabled ? 'yes' : 'no');
        }
        if ($voiceLang !== null) {
            $this->config->setUserValue(
                $this->userId,
                'learning',
                'virtuprof_voice_lang',
                $this->normalizeVoiceLanguage($voiceLang)
            );
        }
        if ($onboardingReminderCount !== null) {
            $this->config->setUserValue(
                $this->userId,
                'learning',
                'onboarding_reminder_count',
                (string)max(0, min(3, $onboardingReminderCount))
            );
        }
        if ($skin !== null) {
            $this->config->setUserValue(
                $this->userId,
                'learning',
                'virtuprof_skin',
                $this->normalizeSkin($skin)
            );
        }

        return new DataResponse(['ok' => true] + $this->buildStatePayload());
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 15, period: 60)]
    public function interviewTurn(array $history = [], int $nextQuestionNumber = 2): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        if (!$this->isAiFeatureAvailable()) {
            return new DataResponse(['error' => 'AI feature disabled'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        // HIGH-03: interview answers are free text sent to Gemini — require per-user consent.
        if ($this->aiConsentMissing()) {
            return new DataResponse(['error' => 'AI consent required', 'consent_required' => true], Http::STATUS_FORBIDDEN);
        }

        $sanitizedHistory = $this->sanitizeInterviewHistory($history);
        if ($sanitizedHistory === []) {
            return new DataResponse(['error' => 'Interview history is required'], Http::STATUS_BAD_REQUEST);
        }

        $nextQuestionNumber = max(2, min(10, $nextQuestionNumber));
        $language = $this->normalizeInterfaceLanguage(
            $this->config->getUserValue($this->userId, 'learning', 'content_language', '')
        );
        if ($language === '') {
            $language = $this->normalizeInterfaceLanguage(
                $this->config->getUserValue($this->userId, 'learning', 'virtuprof_language', 'de')
            ) ?: 'de';
        }

        $result = $this->geminiService->generateInterviewTurn(
            $sanitizedHistory,
            $nextQuestionNumber,
            $this->userId,
            $this->getUserFirstName(),
            $language
        );

        if (($result['reason'] ?? '') === 'rate_limit') {
            return new DataResponse(['error' => 'Rate limit exceeded'], Http::STATUS_TOO_MANY_REQUESTS);
        }
        if (($result['fallback'] ?? true) || $result['answer'] === null) {
            return new DataResponse(['error' => 'Interview turn unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        return new DataResponse([
            'answer' => $result['answer'],
            'next_question_number' => $nextQuestionNumber,
        ]);
    }

    /**
     * Return the last 20 chat memory entries for the current user (for frontend display on mount).
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function getChatHistory(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $entries = $this->chatMemoryService->loadRecentEntries($this->userId, 20);

        $messages = array_map(static fn($e) => [
            'role' => $e->getRole(),
            'text' => $e->getMessage(),
        ], $entries);

        return new DataResponse(['messages' => $messages]);
    }

    /**
     * Delete all chat memory for the current user (MEM-04 — privacy right).
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 5, period: 60)]
    public function clearChatHistory(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $this->chatMemoryService->clearMemory($this->userId);

        return new DataResponse(['ok' => true]);
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 15, period: 60)]
    public function chat(
        string $message,
        ?int $poolId = null,
        ?int $courseId = null,
        ?int $lastWrongQuestionId = null,
        ?array $questionContext = null,
        ?int $hintLevel = null
    ): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        // Admin guard: ai_enabled must be 'yes'
        if (!$this->isAiFeatureAvailable()) {
            return new DataResponse(['error' => 'AI feature disabled'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        // HIGH-03: per-user AI consent gate — covers this call AND the file/ticket intents below.
        if ($this->aiConsentMissing()) {
            return new DataResponse(['error' => 'AI consent required', 'consent_required' => true], Http::STATUS_FORBIDDEN);
        }

        // HIGH-02: drop learning-context ids the user cannot access before RAG/file-intent see them.
        [$poolId, $courseId, $lastWrongQuestionId] = $this->filterAccessibleContext($poolId, $courseId, $lastWrongQuestionId);

        // FILE-INTENT: detect file-creation intents before the AI call
        $lowerMessage = mb_strtolower($message);
        if ($this->isFileIntent($lowerMessage)) {
            return $this->handleFileIntent($lowerMessage, $poolId, $courseId);
        }

        // TICKET-INTENT: detect bug reports, feedback, support requests
        if ($this->isTicketIntent($lowerMessage)) {
            $ticketQuestionId = $questionContext['questionId'] ?? null;
            return $this->handleTicketIntent($message, $poolId, $courseId, $ticketQuestionId);
        }

        // Sanitize questionContext if provided
        if ($questionContext !== null && is_array($questionContext)) {
            $questionContext['questionText'] = mb_substr(
                strip_tags((string)($questionContext['questionText'] ?? '')), 0, 500
            );
            if (!empty($questionContext['answers']) && is_array($questionContext['answers'])) {
                $questionContext['answers'] = array_map(
                    static fn($a) => mb_substr(strip_tags((string)$a), 0, 200),
                    array_slice($questionContext['answers'], 0, 8)
                );
            } else {
                $questionContext['answers'] = [];
            }
            if (isset($questionContext['explanation'])) {
                $questionContext['explanation'] = mb_substr(
                    strip_tags((string)$questionContext['explanation']), 0, 500
                );
            }
            if (isset($questionContext['questionId'])) {
                $questionContext['questionId'] = (int)$questionContext['questionId'];
            }
            if (isset($questionContext['correctAnswerIndex']) && $questionContext['correctAnswerIndex'] !== null) {
                $questionContext['correctAnswerIndex'] = (int)$questionContext['correctAnswerIndex'];
            }
            // PBQ/Simulator fields
            if (isset($questionContext['questionType'])) {
                $questionContext['questionType'] = in_array($questionContext['questionType'], ['single', 'multi', 'open', 'pbq'], true)
                    ? $questionContext['questionType'] : null;
            }
            if (isset($questionContext['pbqSubtype'])) {
                $allowed = ['cli', 'dropdown', 'placement', 'cable', 'routing_config', 'switch_config', 'diagnostic', 'multi_panel'];
                $questionContext['pbqSubtype'] = in_array($questionContext['pbqSubtype'], $allowed, true)
                    ? $questionContext['pbqSubtype'] : null;
            }
            if (isset($questionContext['pbqConfig']) && is_array($questionContext['pbqConfig'])) {
                // Pass through but limit serialized size to 4KB for prompt budget
                $serialized = json_encode($questionContext['pbqConfig']);
                if ($serialized !== false && mb_strlen($serialized) > 4096) {
                    // Keep only essential keys for context
                    $keep = ['domain', 'terminals', 'positions', 'device_options', 'questions', 'hint', 'pins', 'scenario_type'];
                    $questionContext['pbqConfig'] = array_intersect_key($questionContext['pbqConfig'], array_flip($keep));
                }
            } else {
                $questionContext['pbqConfig'] = null;
            }
        } else {
            $questionContext = null;
        }

        // HINT: Validate and clamp hintLevel to 1-3 range
        if ($hintLevel !== null && $hintLevel > 0) {
            $hintLevel = max(1, min(3, $hintLevel));
        } else {
            $hintLevel = null;
        }

        // Build RAG context when the frontend provides learning context params
        $ragContext = [];
        if ($poolId !== null || $courseId !== null || $lastWrongQuestionId !== null) {
            // MED-10: during an active exam on the pool, withhold answer-bearing RAG context so
            // VirtuProf cannot be turned into an exam oracle (mirrors AIController::explain).
            $suppressAnswers = $poolId !== null
                && $this->questionService->isExamActiveOnPool($poolId, $this->userId);
            $ragContext = $this->ragContextService->buildContext(
                $this->userId,
                $poolId,
                $courseId,
                $lastWrongQuestionId,
                $message,
                $suppressAnswers
            );
        }

        // MEM-01: Load persistent chat memory (last 10 entries, oldest-first)
        $memoryEntries = $this->chatMemoryService->loadMemory($this->userId);

        $firstName = $this->getUserFirstName();
        $telos = $this->telosService->getTelos($this->userId);
        $result = $this->geminiService->chat(
            $message,
            $this->userId,
            $ragContext,
            $memoryEntries,
            $questionContext,
            $hintLevel,
            $firstName,
            is_array($telos['telos'] ?? null) ? $telos['telos'] : null,
            $this->isDetailedRequest($lowerMessage)
        );

        // SEC-01: invalid_input is a client error
        if (($result['reason'] ?? '') === 'invalid_input') {
            return new DataResponse(['error' => $result['error'] ?? 'Invalid input'], Http::STATUS_BAD_REQUEST);
        }

        // MEM-01/02: Persist the exchange when we got a real answer
        if (!($result['fallback'] ?? true) && $result['answer'] !== null) {
            $this->chatMemoryService->saveExchange($this->userId, $message, $result['answer']);
        }

        // RAG-TRANS: Extract deduplicated sources from RAG chunks
        $ragSources = [];
        if (!empty($ragContext['chunks'])) {
            $seen = [];
            foreach ($ragContext['chunks'] as $chunk) {
                $file = $chunk['source_file'] ?? '';
                $chapter = $chunk['chapter'] ?? null;
                $key = $file . '|' . ($chapter ?? '');
                if ($file !== '' && !isset($seen[$key])) {
                    $seen[$key] = true;
                    $source = ['source_file' => $file];
                    if ($chapter !== null && $chapter !== '') {
                        $source['chapter'] = $chapter;
                    }
                    $ragSources[] = $source;
                }
            }
        }
        if (!empty($ragSources)) {
            $result['rag_sources'] = $ragSources;
        }

        // All other outcomes (success, fallback, rate_limit, api_error, output_blocked) → HTTP 200
        // Frontend reads 'fallback' flag to trigger FAQ matcher when answer is null
        return new DataResponse($result);
    }

    // -------------------------------------------------------------------------
    // FILE-INTENT helpers
    // -------------------------------------------------------------------------

    /**
     * Detect whether the message is a file-creation intent.
     * Matches German and English keywords for summary, plan, and progress intents.
     */
    private function isFileIntent(string $lowerMessage): bool {
        $patterns = [
            'zusammenfassung erstellen',
            'zusammenfassung generieren',
            'lernplan erstellen',
            'lernplan generieren',
            'wochenplan erstellen',
            'fortschritt erstellen',
            'fortschritt anzeigen',
            'fortschritt aktualisieren',
            'create summary',
            'generate summary',
            'create learning plan',
            'learning plan',
            'lernplan',
            'fortschritt',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($lowerMessage, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function isDetailedRequest(string $lowerMessage): bool {
        $patterns = [
            'erklär genauer',
            'erklaer genauer',
            'mehr details',
            'geh ins detail',
            'ausführlicher',
            'ausfuehrlicher',
            'detaillierter',
            'explain in more detail',
            'more details',
            'go deeper',
            'be more detailed',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($lowerMessage, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handle a file-creation intent: call the appropriate service and return
     * a response with {answer, action: 'file_created', path: '/Learning/...'}.
     */
    private function handleFileIntent(string $lowerMessage, ?int $poolId, ?int $courseId): DataResponse {
        try {
            // Determine which intent matched
            if (
                str_contains($lowerMessage, 'zusammenfassung') ||
                str_contains($lowerMessage, 'summary')
            ) {
                // Summary requires a pool context
                if ($poolId === null) {
                    return new DataResponse([
                        'answer' => 'Bitte öffne zuerst einen Pool, damit ich eine Zusammenfassung erstellen kann.',
                        'fallback' => false,
                    ]);
                }
                $result = $this->noteGeneratorService->generateSummary($this->userId, $poolId, $courseId);
                return new DataResponse([
                    'answer' => 'Ich habe eine Zusammenfassung für dieses Thema erstellt und in deiner Nextcloud gespeichert.',
                    'action' => 'file_created',
                    'path'   => $result['path'],
                    'fallback' => false,
                ]);
            }

            if (
                str_contains($lowerMessage, 'lernplan') ||
                str_contains($lowerMessage, 'wochenplan') ||
                str_contains($lowerMessage, 'learning plan')
            ) {
                $result = $this->lernplanService->generateWeeklyPlan($this->userId, $courseId);
                return new DataResponse([
                    'answer' => 'Ich habe deinen wöchentlichen Lernplan erstellt und in deiner Nextcloud gespeichert.',
                    'action' => 'file_created',
                    'path'   => $result['path'],
                    'fallback' => false,
                ]);
            }

            if (str_contains($lowerMessage, 'fortschritt')) {
                $result = $this->lernplanService->generateFortschritt($this->userId, $courseId);
                return new DataResponse([
                    'answer' => 'Ich habe dein Fortschritts-Dashboard aktualisiert und in deiner Nextcloud gespeichert.',
                    'action' => 'file_created',
                    'path'   => $result['path'],
                    'fallback' => false,
                ]);
            }

            // Fallback — should not reach here if isFileIntent() is accurate
            return new DataResponse([
                'answer' => 'Ich konnte keinen passenden Datei-Typ für deine Anfrage bestimmen.',
                'fallback' => false,
            ]);
        } catch (\Throwable $e) {
            return new DataResponse([
                'answer' => 'Die Datei konnte leider nicht erstellt werden. Bitte versuche es später erneut.',
                'fallback' => false,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // TICKET-INTENT helpers
    // -------------------------------------------------------------------------

    private function isTicketIntent(string $lowerMessage): bool {
        $patterns = [
            'bug melden', 'fehler melden', 'problem melden',
            'bug report', 'report a bug', 'report bug',
            'ticket erstellen', 'ticket anlegen', 'support ticket',
            'feedback geben', 'feedback senden',
            'etwas funktioniert nicht', 'geht nicht', 'ist kaputt',
            'something is broken', 'not working', "doesn't work",
            'ich möchte einen fehler', 'ich will einen bug',
            'kann ich bei dir einen bug', 'kann ich einen fehler',
            'fehler gefunden', 'bug gefunden',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($lowerMessage, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function handleTicketIntent(string $message, ?int $poolId, ?int $courseId, ?int $questionId = null): DataResponse {
        // Extract the actual bug description — remove the intent keywords
        $description = $message;

        $context = [];
        if ($courseId !== null) {
            $context['courseId'] = $courseId;
        }
        if ($poolId !== null) {
            $context['poolId'] = $poolId;
        }
        if ($questionId !== null) {
            $context['questionId'] = $questionId;
            $context['mode'] = 'question_error_report';
        }

        // Route question error reports to course instructor instead of admin
        $category = ($questionId !== null) ? 'course_content' : 'technical';

        try {
            $ticket = $this->ticketService->create(
                $this->userId,
                null, // auto-generated subject
                $description,
                $context,
                $category
            );

            $ticketId = $ticket->getId();
            $answer = "Danke für deine Meldung! Ich habe ein Support-Ticket erstellt (#{$ticketId}). "
                . "Dein Dozent oder Admin wird sich darum kümmern. "
                . "Du kannst den Status unter \"More options\" → \"Meine Tickets\" einsehen.";

            return new DataResponse([
                'answer' => $answer,
                'fallback' => false,
                'ticket_id' => $ticketId,
            ]);
        } catch (\Exception $e) {
            return new DataResponse([
                'answer' => 'Deine Meldung konnte leider nicht gespeichert werden. '
                    . 'Bitte versuche es erneut oder wende dich direkt an deinen Dozenten.',
                'fallback' => false,
            ]);
        }
    }
}
