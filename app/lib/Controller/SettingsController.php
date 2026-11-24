<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\AuditCheckpointService;
use OCA\Learning\Service\DocumentService;
use OCA\Learning\Service\KeyService;
use OCA\Learning\Service\Llm\ApiKeyStore;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCA\Learning\Service\RoleService;
use OCA\Learning\Service\TranslationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;

#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]
class SettingsController extends Controller {
    private const AVAILABLE_TOOL_IDS = [
        'subnet',
        'dns',
        'firewall',
        'portscan',
        'routing',
        'nat',
        'wireshark',
        'authflow',
    ];
    private const AVAILABLE_AI_PROVIDERS = ['gemini', 'ollama', 'disabled'];

    private IConfig $config;
    private IDBConnection $db;
    private ?string $userId;
    private KeyService $keyService;
    private AuditCheckpointService $auditCheckpointService;
    private ConnectionStore $connectionStore;
    private ApiKeyStore $apiKeyStore;
    private RoleService $roleService;

    public function __construct(string $appName, IRequest $request, IConfig $config, IDBConnection $db, KeyService $keyService, AuditCheckpointService $auditCheckpointService, ConnectionStore $connectionStore, ApiKeyStore $apiKeyStore, RoleService $roleService, ?string $userId) {
        parent::__construct($appName, $request);
        $this->roleService = $roleService;
        $this->connectionStore = $connectionStore;
        $this->apiKeyStore = $apiKeyStore;
        $this->config = $config;
        $this->db = $db;
        $this->keyService = $keyService;
        $this->auditCheckpointService = $auditCheckpointService;
        $this->userId = $userId;
    }

    public function getAdmin(): DataResponse {
        // AUDIT-08 — liveness widget data. Defensive: on a fresh install the audit tables are not yet
        // migrated and getLivenessStatus() (a COUNT on learning_audit_events) would throw. Never 500 the
        // admin settings page — fall back to a neutral state.
        try {
            $liveness = $this->auditCheckpointService->getLivenessStatus();
        } catch (\Throwable $e) {
            $liveness = [
                'last_checkpoint_at'      => 0,
                'events_since_checkpoint' => 0,
                'anchor_enabled'          => false,
                'last_anchor_status'      => 'none',
                'is_overdue'              => false,
            ];
        }

        $connection = $this->connectionStore->load();

        return new DataResponse([
            'daily_challenge_enabled' => $this->config->getAppValue('learning', 'daily_challenge_enabled', 'yes'),
            'default_language' => $this->config->getAppValue('learning', 'default_language', 'de'),
            'max_import_size_mb' => (int)$this->config->getAppValue('learning', 'max_import_size_mb', '2'),
            'gamification_enabled' => $this->config->getAppValue('learning', 'gamification_enabled', 'yes'),
            // GitHub #60: the first group in force (the lists once saved, the 5.11 key before),
            // '' when only people are instructors — never the raw legacy key, which no longer
            // decides anything once the lists exist.
            'instructor_group' => $this->roleService->getInstructorGroup(),
            'allow_course_instructor_fallback' => $this->config->getAppValue('learning', 'allow_course_instructor_fallback', 'no'),
            'exam_attempt_limit_per_day' => (int)$this->config->getAppValue('learning', 'exam_attempt_limit_per_day', '5'),
            'exam_attempt_cooldown_minutes' => (int)$this->config->getAppValue('learning', 'exam_attempt_cooldown_minutes', '10'),
            // The key the Gemini connection actually uses: after the upgrade it is in the encrypted
            // slot, and the plaintext `gemini_api_key` is gone.
            'gemini_api_key_set' => $this->apiKeyStore->get(ConnectionStore::legacyGemini(false)) !== null,
            // The connection in force (snapshot, or derived from the 5.5 keys), never the raw legacy
            // keys: a legacy ai_provider '' (AI off) used to show as Gemini and on, and saving any
            // unrelated setting then switched on an external recipient.
            'ai_provider' => $connection->provider,
            'ai_ollama_url' => $connection->provider === 'ollama'
                ? $connection->baseUrl
                : $this->config->getAppValue('learning', 'ai_ollama_url', 'http://localhost:11434'),
            'ai_ollama_model' => $connection->provider === 'ollama'
                ? $connection->model
                : $this->config->getAppValue('learning', 'ai_ollama_model', 'llama3'),
            'ai_enabled' => $connection->enabled && $connection->provider !== 'disabled' ? 'yes' : 'no',
            // GitHub #35: course materials can only feed the AI assistant from PDFs with pdftotext
            'pdf_text_extraction_available' => DocumentService::detectPdfTextExtraction(),
            // Certificates (v5.0.0): false on a fresh install until the admin runs
            // `occ learning:cert:init-issuer`. Drives the admin-settings setup banner.
            'cert_issuer_ready' => $this->keyService->hasActiveKey(),
            // AUDIT-08 — audit-trail liveness (tamper-evidence health at a glance).
            'audit_last_checkpoint_at'      => (int)$liveness['last_checkpoint_at'],
            'audit_events_since_checkpoint' => (int)$liveness['events_since_checkpoint'],
            'audit_anchor_enabled'          => (bool)$liveness['anchor_enabled'],
            'audit_anchor_status'           => (string)$liveness['last_anchor_status'],
            'audit_is_overdue'              => (bool)$liveness['is_overdue'],
        ]);
    }

    /**
     * Generate the issuer signing key from the admin page — the web twin of
     * `occ learning:cert:init-issuer` for hosts without occ access (managed hosting, Codeberg #8).
     *
     * Init only: refuses when a key exists, so it can never replace a key that signed certificates.
     * Rotation stays occ-only. No password confirmation: the call is admin-only and CSRF-protected,
     * destroys nothing and returns no secret — the worst an abused admin session achieves is the key
     * the admin was about to create anyway.
     */
    public function initCertIssuer(): DataResponse {
        if ($this->keyService->hasActiveKey()) {
            return new DataResponse(['error' => 'already_initialized', 'cert_issuer_ready' => true], Http::STATUS_CONFLICT);
        }
        try {
            $key = $this->keyService->init();
        } catch (\RuntimeException $e) {
            // KeyService's own messages (active key exists, init in progress, ext-sodium missing,
            // encryption failed) — static strings, safe to show the admin.
            $status = str_contains($e->getMessage(), 'already') ? Http::STATUS_CONFLICT : Http::STATUS_INTERNAL_SERVER_ERROR;
            return new DataResponse(['error' => $e->getMessage(), 'cert_issuer_ready' => $this->keyService->hasActiveKey()], $status);
        } catch (\Throwable $e) {
            return new DataResponse(['error' => 'Issuer key initialisation failed', 'cert_issuer_ready' => false], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        // Audit checkpoints are signed with this key, so events that piled up without it are still
        // waiting. Checkpoint them now rather than up to a week later, or the overdue warning would
        // switch to "check your cron" on an install whose cron is fine. Best effort: the key exists,
        // and the weekly job retries anyway. A no-op when there are no pending events.
        try {
            $this->auditCheckpointService->createCheckpoint();
        } catch (\Throwable $e) {
        }

        return new DataResponse([
            'cert_issuer_ready' => true,
            'key_id' => $key->getKeyId(),
            'did' => $this->keyService->hostDid(),
        ]);
    }

    public function getAdminAudit(int $limit = 100, int $offset = 0): DataResponse {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('learning_audit_events')
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();
        return new DataResponse([
            'events' => array_map(static function ($r) {
                return [
                    'id' => (int)$r['id'],
                    'event_key' => $r['event_key'],
                    'user_id' => $r['user_id'],
                    'session_id' => $r['session_id'] !== null ? (int)$r['session_id'] : null,
                    'pool_id' => $r['pool_id'] !== null ? (int)$r['pool_id'] : null,
                    'created_at' => (int)$r['created_at'],
                    'context' => !empty($r['context_json']) ? (json_decode((string)$r['context_json'], true) ?: []) : [],
                ];
            }, $rows),
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    public function saveAdmin(
        string $daily_challenge_enabled,
        string $default_language,
        int $max_import_size_mb,
        string $gamification_enabled,
        ?string $instructor_group = null,
        string $allow_course_instructor_fallback = 'no',
        int $exam_attempt_limit_per_day = 5,
        int $exam_attempt_cooldown_minutes = 10,
        ?string $gemini_api_key = null,
        string $ai_provider = 'gemini',
        string $ai_ollama_url = 'http://localhost:11434',
        string $ai_ollama_model = 'llama3',
        string $ai_enabled = 'no'
    ): DataResponse {
        // Validate the AI connection before anything is written: an invalid one must not leave a
        // half-saved form behind.
        // 5.7.0 offers only the backends 5.5 had. Anything else is an error, not Gemini: a silent
        // fallback would switch to an external recipient and invalidate every user's consent.
        $provider = strtolower(trim($ai_provider));
        if (!in_array($provider, self::AVAILABLE_AI_PROVIDERS, true)) {
            return new DataResponse(['error' => 'AI provider is not available in this release'], Http::STATUS_BAD_REQUEST);
        }
        $ollamaUrl = trim($ai_ollama_url) !== '' ? trim($ai_ollama_url) : 'http://localhost:11434';
        $ollamaModel = trim($ai_ollama_model) !== '' ? trim($ai_ollama_model) : 'llama3';
        $aiEnabled = $ai_enabled === 'yes' && $provider !== 'disabled';
        try {
            $connection = ConnectionStore::fromLegacyValues($provider, $aiEnabled, $ollamaUrl, $ollamaModel);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => 'Invalid AI connection: ' . $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        // API key: only update when a new non-masked value is provided. It is stored encrypted in
        // the Gemini connection's slot (whichever provider is active, so a key entered for later
        // is kept), never as plaintext `gemini_api_key`: ApiKeyStore::get() prefers the encrypted
        // slot, so a plaintext write after the upgrade would be ignored and a later repair would
        // delete it. Written first: a key that cannot be stored saves nothing else either.
        if ($gemini_api_key !== null && $gemini_api_key !== '***') {
            try {
                $this->apiKeyStore->apply(ConnectionStore::legacyGemini(false), trim($gemini_api_key) === ''
                    ? ['action' => 'delete']
                    : ['action' => 'replace', 'value' => $gemini_api_key]);
            } catch (\RuntimeException $e) {
                return new DataResponse(['error' => 'The API key could not be stored'], Http::STATUS_INTERNAL_SERVER_ERROR);
            }
        }

        $this->config->setAppValue('learning', 'daily_challenge_enabled', $daily_challenge_enabled === 'yes' ? 'yes' : 'no');
        $this->config->setAppValue('learning', 'default_language', in_array($default_language, ['de', 'en', 'zh'], true) ? $default_language : 'de');
        $this->config->setAppValue('learning', 'max_import_size_mb', (string)max(1, min(10, $max_import_size_mb)));
        $this->config->setAppValue('learning', 'gamification_enabled', $gamification_enabled === 'yes' ? 'yes' : 'no');
        // Codeberg #6: the group that grants global instructor rights (create courses and
        // pools) has always existed as an app value, but nothing exposed it — the admin
        // page referred to "the configured instructor group" without ever naming it, and
        // the manual did not mention it at all. An empty submission means "keep the
        // default"; deleting the setting entirely would silently promote nobody.
        // GitHub #60: the admin page now saves groups and people through
        // InstructorAccessController. A client that still sends the single 5.11 field means
        // "this is the instructor group": it replaces the groups and keeps the individual people,
        // through RoleService, so the lists and the 5.11 key stay in step. Writing only the 5.11
        // key answered 200 and changed nothing once the lists existed. Not sending it leaves the
        // instructors untouched.
        if ($instructor_group !== null) {
            $instructorGroup = trim($instructor_group);
            if ($instructorGroup === '' || mb_strlen($instructorGroup) > RoleService::MAX_ID_LENGTH) {
                // no gid is longer than 64 characters; a cut name would name another group
                $instructorGroup = RoleService::DEFAULT_GROUP;
            }
            $this->roleService->saveInstructors([$instructorGroup], $this->roleService->getInstructorUsers());
        }
        $this->config->setAppValue('learning', 'allow_course_instructor_fallback', $allow_course_instructor_fallback === 'yes' ? 'yes' : 'no');
        $this->config->setAppValue('learning', 'exam_attempt_limit_per_day', (string)max(1, min(50, $exam_attempt_limit_per_day)));
        $this->config->setAppValue('learning', 'exam_attempt_cooldown_minutes', (string)max(0, min(1440, $exam_attempt_cooldown_minutes)));

        $this->config->setAppValue('learning', 'ai_provider', $provider);
        $this->config->setAppValue('learning', 'ai_ollama_url', $ollamaUrl);
        $this->config->setAppValue('learning', 'ai_ollama_model', $ollamaModel);
        $this->config->setAppValue('learning', 'ai_enabled', $aiEnabled ? 'yes' : 'no');
        // Once a connection snapshot exists it is what consent and transport use: the form's AI
        // change must reach it, or the admin would see one recipient and users would send to
        // another. A changed recipient asks every user for consent again.
        $this->connectionStore->adoptLegacySettings($connection, $this->userId ?? '');

        return new DataResponse(['status' => 'ok']);
    }

    #[NoAdminRequired]
    public function getPersonal(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], 401);
        }

        return new DataResponse([
            'daily_challenge' => $this->config->getUserValue($this->userId, 'learning', 'daily_challenge', 'yes'),
            'content_language' => $this->config->getUserValue($this->userId, 'learning', 'content_language', ''),
            'virtuprof_enabled' => $this->config->getUserValue($this->userId, 'learning', 'virtuprof_enabled', 'yes'),
            'notifications_enabled' => $this->config->getUserValue($this->userId, 'learning', 'notifications_enabled', 'yes'),
            'fsrs_detailed_stats' => $this->config->getUserValue($this->userId, 'learning', 'fsrs_detailed_stats', 'no'),
            'animations_enabled' => $this->config->getUserValue($this->userId, 'learning', 'animations_enabled', 'yes'),
            'onboarding_acknowledged' => $this->config->getUserValue($this->userId, 'learning', 'onboarding_acknowledged', 'no'),
            // GitHub #99: "Nicht mehr anzeigen" in the archive confirmation (saveArchiveConfirm())
            'archive_confirm_skip' => $this->config->getUserValue($this->userId, 'learning', 'archive_confirm_skip', 'no'),
            // GitHub #66: the question player's switches (saveQuestionMedia())
            'question_media_autoplay' => $this->config->getUserValue($this->userId, 'learning', 'question_media_autoplay', 'no'),
            'answer_sounds' => $this->config->getUserValue($this->userId, 'learning', 'answer_sounds', 'auto'),
            // GitHub #36: the admin's global gamification switch, read-only for everyone. The
            // frontend hides XP, level, streak and badges with it (App.vue holds the one copy).
            'gamification_enabled' => $this->config->getAppValue('learning', 'gamification_enabled', 'yes') === 'yes',
        ]);
    }

    /**
     * Records that the user is finished with the onboarding — completed, skipped or dismissed.
     *
     * Codeberg #5: whether the intro had been seen lived in window.localStorage alone, which is
     * scoped to one browser on one machine, so a second device brought the wizard back.
     *
     * Deliberately parameterless and idempotent: savePersonal() demands two mandatory
     * parameters, so a partial PUT for this single flag would be rejected inside NC's dispatcher
     * before the controller body runs. Deliberately not UserTelos::onboarding_completed, which
     * every profile save sets and which therefore means "a profile exists", not "intro seen".
     */
    #[NoAdminRequired]
    public function acknowledgeOnboarding(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], 401);
        }

        $this->config->setUserValue($this->userId, 'learning', 'onboarding_acknowledged', 'yes');

        return new DataResponse(['status' => 'ok']);
    }

    /**
     * GitHub #99: "Nicht mehr anzeigen" in the dialog that explains archiving a course. Stored per
     * user on the server, so it holds in every browser and on every device; skip=false brings the
     * dialog back. Its own route for the reason given at acknowledgeOnboarding(): savePersonal()
     * has two mandatory parameters and would refuse a PUT carrying only this flag.
     *
     * @param bool $skip true archives without the dialog from now on
     * @return DataResponse<Http::STATUS_OK, array{archive_confirm_skip: 'yes'|'no'}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>
     */
    #[NoAdminRequired]
    public function saveArchiveConfirm(bool $skip): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $value = $skip ? 'yes' : 'no';
        $this->config->setUserValue($this->userId, 'learning', 'archive_confirm_skip', $value);

        return new DataResponse(['archive_confirm_skip' => $value]);
    }

    /**
     * GitHub #66: the two switches of the question player, stored per user on the server like
     * saveArchiveConfirm() (own route for the same reason). Either field may be left out; only
     * the ones sent are stored.
     *
     * - autoplay: the intro starts by itself when a question is shown, and after answering the
     *   spoken explanation of the chosen option and the dialog follow. Off by default (WCAG
     *   1.4.2, required training in an office).
     * - sounds: the short "right"/"wrong" tone after answering. 'auto' (the default) follows
     *   autoplay; 'yes'/'no' is the learner's explicit choice.
     *
     * @param bool|null $autoplay start the intro and the explanations by themselves
     * @param string|null $sounds 'auto', 'yes' or 'no'
     * @return DataResponse<Http::STATUS_OK, array{question_media_autoplay: string, answer_sounds: string}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>
     */
    #[NoAdminRequired]
    public function saveQuestionMedia(?bool $autoplay = null, ?string $sounds = null): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        if ($sounds !== null && !in_array($sounds, ['auto', 'yes', 'no'], true)) {
            return new DataResponse(['error' => 'sounds must be auto, yes or no'], Http::STATUS_BAD_REQUEST);
        }

        if ($autoplay !== null) {
            $this->config->setUserValue($this->userId, 'learning', 'question_media_autoplay', $autoplay ? 'yes' : 'no');
        }
        if ($sounds !== null) {
            $this->config->setUserValue($this->userId, 'learning', 'answer_sounds', $sounds);
        }

        return new DataResponse([
            'question_media_autoplay' => $this->config->getUserValue($this->userId, 'learning', 'question_media_autoplay', 'no'),
            'answer_sounds' => $this->config->getUserValue($this->userId, 'learning', 'answer_sounds', 'auto'),
        ]);
    }

    #[NoAdminRequired]
    public function savePersonal(
        string $daily_challenge,
        string $notifications_enabled,
        string $content_language = '',
        string $virtuprof_enabled = 'yes',
        string $fsrs_detailed_stats = 'no',
        string $animations_enabled = 'yes',
        // Accepted and ignored for clients that still send it: the 'UI Language' setting was
        // stored but never read — the interface follows the Nextcloud language — so the field
        // is gone (audit #12, #61, #218). Values already stored stay untouched and unused.
        string $ui_language = ''
    ): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], 401);
        }

        $this->config->setUserValue($this->userId, 'learning', 'daily_challenge', $daily_challenge === 'yes' ? 'yes' : 'no');
        // Codeberg #6: 'ar' was missing here while the dropdown offered Arabic — the PUT
        // answered 200 and stored '' instead. The set is TranslationService::ALLOWED_LANGS, the
        // languages a translation can be stored in (fr and uk since 5.18.0), plus '' (original).
        $this->config->setUserValue($this->userId, 'learning', 'content_language', in_array($content_language, TranslationService::ALLOWED_LANGS, true) ? $content_language : '');
        $this->config->setUserValue($this->userId, 'learning', 'virtuprof_enabled', $virtuprof_enabled === 'no' ? 'no' : 'yes');
        $this->config->setUserValue($this->userId, 'learning', 'notifications_enabled', $notifications_enabled === 'yes' ? 'yes' : 'no');
        $this->config->setUserValue($this->userId, 'learning', 'fsrs_detailed_stats', $fsrs_detailed_stats === 'yes' ? 'yes' : 'no');
        $this->config->setUserValue($this->userId, 'learning', 'animations_enabled', $animations_enabled === 'no' ? 'no' : 'yes');

        return new DataResponse(['status' => 'ok']);
    }

    #[NoAdminRequired]
    public function getTools(): DataResponse {
        // GitHub #35: the course view also needs the admin's AI switch, to leave out what only
        // serves the AI assistant (knowledge import, text extraction of materials). Same
        // predicate as getAdmin(). A read failure hides those parts rather than the page.
        try {
            $connection = $this->connectionStore->load();
            $aiEnabled = $connection->enabled && $connection->provider !== 'disabled';
        } catch (\Throwable $e) {
            $aiEnabled = false;
        }
        return new DataResponse([
            'enabled_tools' => $this->getConfiguredTools(),
            'ai_enabled' => $aiEnabled,
        ]);
    }

    public function saveTools(array $enabledTools = []): DataResponse {
        $normalized = $this->normalizeTools($enabledTools);
        $this->config->setAppValue('learning', 'enabled_tools', json_encode($normalized));

        return new DataResponse([
            'status' => 'ok',
            'enabled_tools' => $normalized,
        ]);
    }

    private function getConfiguredTools(): array {
        $raw = $this->config->getAppValue('learning', 'enabled_tools', '');
        if ($raw === '') {
            return self::AVAILABLE_TOOL_IDS;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return self::AVAILABLE_TOOL_IDS;
        }

        return $this->normalizeTools($decoded);
    }

    private function normalizeTools(array $enabledTools): array {
        $allowedLookup = array_flip(self::AVAILABLE_TOOL_IDS);
        $normalized = [];

        foreach ($enabledTools as $toolId) {
            $toolId = (string)$toolId;
            if ($toolId !== '' && isset($allowedLookup[$toolId])) {
                $normalized[$toolId] = $toolId;
            }
        }

        return array_values($normalized);
    }
}
