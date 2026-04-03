<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;

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

    public function __construct(string $appName, IRequest $request, IConfig $config, IDBConnection $db, ?string $userId) {
        parent::__construct($appName, $request);
        $this->config = $config;
        $this->db = $db;
        $this->userId = $userId;
    }

    /**
     * @AdminRequired
     */
    public function getAdmin(): DataResponse {
        return new DataResponse([
            'daily_challenge_enabled' => $this->config->getAppValue('learning', 'daily_challenge_enabled', 'yes'),
            'default_language' => $this->config->getAppValue('learning', 'default_language', 'de'),
            'max_import_size_mb' => (int)$this->config->getAppValue('learning', 'max_import_size_mb', '2'),
            'gamification_enabled' => $this->config->getAppValue('learning', 'gamification_enabled', 'yes'),
            'allow_course_instructor_fallback' => $this->config->getAppValue('learning', 'allow_course_instructor_fallback', 'no'),
            'exam_attempt_limit_per_day' => (int)$this->config->getAppValue('learning', 'exam_attempt_limit_per_day', '5'),
            'exam_attempt_cooldown_minutes' => (int)$this->config->getAppValue('learning', 'exam_attempt_cooldown_minutes', '10'),
            'gemini_api_key_set' => $this->config->getAppValue('learning', 'gemini_api_key', '') !== '',
            'ai_provider' => $this->normalizeAiProvider($this->config->getAppValue('learning', 'ai_provider', 'gemini')),
            'ai_ollama_url' => $this->config->getAppValue('learning', 'ai_ollama_url', 'http://localhost:11434'),
            'ai_ollama_model' => $this->config->getAppValue('learning', 'ai_ollama_model', 'llama3'),
            'ai_enabled' => $this->config->getAppValue('learning', 'ai_enabled', 'no'),
        ]);
    }

    /**
     * @AdminRequired
     */
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

    /**
     * @AdminRequired
     */
    public function saveAdmin(
        string $daily_challenge_enabled,
        string $default_language,
        int $max_import_size_mb,
        string $gamification_enabled,
        string $allow_course_instructor_fallback = 'no',
        int $exam_attempt_limit_per_day = 5,
        int $exam_attempt_cooldown_minutes = 10,
        ?string $gemini_api_key = null,
        string $ai_provider = 'gemini',
        string $ai_ollama_url = 'http://localhost:11434',
        string $ai_ollama_model = 'llama3',
        string $ai_enabled = 'no'
    ): DataResponse {
        $this->config->setAppValue('learning', 'daily_challenge_enabled', $daily_challenge_enabled === 'yes' ? 'yes' : 'no');
        $this->config->setAppValue('learning', 'default_language', in_array($default_language, ['de', 'en'], true) ? $default_language : 'de');
        $this->config->setAppValue('learning', 'max_import_size_mb', (string)max(1, min(10, $max_import_size_mb)));
        $this->config->setAppValue('learning', 'gamification_enabled', $gamification_enabled === 'yes' ? 'yes' : 'no');
        $this->config->setAppValue('learning', 'allow_course_instructor_fallback', $allow_course_instructor_fallback === 'yes' ? 'yes' : 'no');
        $this->config->setAppValue('learning', 'exam_attempt_limit_per_day', (string)max(1, min(50, $exam_attempt_limit_per_day)));
        $this->config->setAppValue('learning', 'exam_attempt_cooldown_minutes', (string)max(0, min(1440, $exam_attempt_cooldown_minutes)));

        // API key: only update when a new non-masked value is provided
        if ($gemini_api_key !== null && $gemini_api_key !== '***') {
            if ($gemini_api_key === '') {
                $this->config->deleteAppValue('learning', 'gemini_api_key');
            } else {
                $this->config->setAppValue('learning', 'gemini_api_key', trim($gemini_api_key));
            }
        }
        $provider = $this->normalizeAiProvider($ai_provider);
        $this->config->setAppValue('learning', 'ai_provider', $provider);
        $this->config->setAppValue('learning', 'ai_ollama_url', trim($ai_ollama_url) !== '' ? trim($ai_ollama_url) : 'http://localhost:11434');
        $this->config->setAppValue('learning', 'ai_ollama_model', trim($ai_ollama_model) !== '' ? trim($ai_ollama_model) : 'llama3');
        $this->config->setAppValue('learning', 'ai_enabled', ($ai_enabled === 'yes' && $provider !== 'disabled') ? 'yes' : 'no');

        return new DataResponse(['status' => 'ok']);
    }

    /**
     * @NoAdminRequired
     */
    public function getPersonal(): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], 401);
        }

        return new DataResponse([
            'daily_challenge' => $this->config->getUserValue($this->userId, 'learning', 'daily_challenge', 'yes'),
            'ui_language' => $this->config->getUserValue($this->userId, 'learning', 'ui_language', ''),
            'content_language' => $this->config->getUserValue($this->userId, 'learning', 'content_language', ''),
            'virtuprof_enabled' => $this->config->getUserValue($this->userId, 'learning', 'virtuprof_enabled', 'yes'),
            'notifications_enabled' => $this->config->getUserValue($this->userId, 'learning', 'notifications_enabled', 'yes'),
            'fsrs_detailed_stats' => $this->config->getUserValue($this->userId, 'learning', 'fsrs_detailed_stats', 'no'),
        ]);
    }

    /**
     * @NoAdminRequired
     */
    public function savePersonal(
        string $daily_challenge,
        string $ui_language,
        string $notifications_enabled,
        string $content_language = '',
        string $virtuprof_enabled = 'yes',
        string $fsrs_detailed_stats = 'no'
    ): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], 401);
        }

        $this->config->setUserValue($this->userId, 'learning', 'daily_challenge', $daily_challenge === 'yes' ? 'yes' : 'no');
        $this->config->setUserValue($this->userId, 'learning', 'ui_language', in_array($ui_language, ['de', 'en', ''], true) ? $ui_language : '');
        $this->config->setUserValue($this->userId, 'learning', 'content_language', in_array($content_language, ['de', 'en', 'ru', ''], true) ? $content_language : '');
        $this->config->setUserValue($this->userId, 'learning', 'virtuprof_enabled', $virtuprof_enabled === 'no' ? 'no' : 'yes');
        $this->config->setUserValue($this->userId, 'learning', 'notifications_enabled', $notifications_enabled === 'yes' ? 'yes' : 'no');
        $this->config->setUserValue($this->userId, 'learning', 'fsrs_detailed_stats', $fsrs_detailed_stats === 'yes' ? 'yes' : 'no');

        return new DataResponse(['status' => 'ok']);
    }

    /**
     * @NoAdminRequired
     */
    public function getTools(): DataResponse {
        return new DataResponse([
            'enabled_tools' => $this->getConfiguredTools(),
        ]);
    }

    /**
     * @AdminRequired
     */
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

    private function normalizeAiProvider(string $provider): string {
        $provider = strtolower(trim($provider));
        return in_array($provider, self::AVAILABLE_AI_PROVIDERS, true) ? $provider : 'gemini';
    }
}
