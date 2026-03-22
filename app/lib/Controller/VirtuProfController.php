<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\AiChatMemoryService;
use OCA\Learning\Service\GeminiService;
use OCA\Learning\Service\LernplanService;
use OCA\Learning\Service\NoteGeneratorService;
use OCA\Learning\Service\RagContextService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;

class VirtuProfController extends Controller {
    private IConfig $config;
    private ?string $userId;
    private GeminiService $geminiService;
    private RagContextService $ragContextService;
    private AiChatMemoryService $chatMemoryService;
    private NoteGeneratorService $noteGeneratorService;
    private LernplanService $lernplanService;

    public function __construct(
        string $appName,
        IRequest $request,
        IConfig $config,
        ?string $userId,
        GeminiService $geminiService,
        RagContextService $ragContextService,
        AiChatMemoryService $chatMemoryService,
        NoteGeneratorService $noteGeneratorService,
        LernplanService $lernplanService
    ) {
        parent::__construct($appName, $request);
        $this->config = $config;
        $this->userId = $userId;
        $this->geminiService = $geminiService;
        $this->ragContextService = $ragContextService;
        $this->chatMemoryService = $chatMemoryService;
        $this->noteGeneratorService = $noteGeneratorService;
        $this->lernplanService = $lernplanService;
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function getState(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $dismissed = json_decode(
            $this->config->getUserValue($this->userId, 'learning', 'virtuprof_dismissed', '[]'),
            true
        );

        return new DataResponse([
            'dismissed' => is_array($dismissed) ? array_values($dismissed) : [],
            'enabled' => $this->config->getUserValue($this->userId, 'learning', 'virtuprof_enabled', 'yes') === 'yes',
            'language' => $this->config->getUserValue($this->userId, 'learning', 'virtuprof_language', ''),
            // PRIV-02: expose global AI toggle so frontend can hide chat section when disabled
            'ai_enabled' => $this->config->getAppValue('learning', 'ai_enabled', 'no') === 'yes',
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

        $normalized = strtolower(trim($language));
        if (!in_array($normalized, ['', 'de', 'en', 'ru', 'ar'], true)) {
            $normalized = '';
        }

        $this->config->setUserValue($this->userId, 'learning', 'virtuprof_language', $normalized);

        return new DataResponse(['ok' => true, 'language' => $normalized]);
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
        ?int $lastWrongQuestionId = null
    ): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        // Admin guard: ai_enabled must be 'yes'
        if ($this->config->getAppValue('learning', 'ai_enabled', 'no') !== 'yes') {
            return new DataResponse(['error' => 'AI feature disabled'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        // FILE-INTENT: detect file-creation intents before the AI call
        $lowerMessage = mb_strtolower($message);
        if ($this->isFileIntent($lowerMessage)) {
            return $this->handleFileIntent($lowerMessage, $poolId, $courseId);
        }

        // Build RAG context when the frontend provides learning context params
        $ragContext = [];
        if ($poolId !== null || $courseId !== null || $lastWrongQuestionId !== null) {
            $ragContext = $this->ragContextService->buildContext(
                $this->userId,
                $poolId,
                $courseId,
                $lastWrongQuestionId,
                $message
            );
        }

        // MEM-01: Load persistent chat memory (last 10 entries, oldest-first)
        $memoryEntries = $this->chatMemoryService->loadMemory($this->userId);

        $result = $this->geminiService->chat($message, $this->userId, $ragContext, $memoryEntries);

        // SEC-01: invalid_input is a client error
        if (($result['reason'] ?? '') === 'invalid_input') {
            return new DataResponse(['error' => $result['error'] ?? 'Invalid input'], Http::STATUS_BAD_REQUEST);
        }

        // MEM-01/02: Persist the exchange when we got a real answer
        if (!($result['fallback'] ?? true) && $result['answer'] !== null) {
            $this->chatMemoryService->saveExchange($this->userId, $message, $result['answer']);
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
}
