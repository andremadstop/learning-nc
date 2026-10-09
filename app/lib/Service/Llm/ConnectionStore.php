<?php
declare(strict_types=1);

namespace OCA\Learning\Service\Llm;

use OCA\Learning\Service\AuditService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Reads and writes the connection snapshot `ai_connection` (spec 2.3).
 *
 * - load(): one read of `ai_connection`; only if it is missing, the snapshot is derived from the 5.5
 *   keys (ai_provider, ai_enabled, ai_ollama_url, ai_ollama_model), so neither a fresh install
 *   nor an upgrade needs a repair step to keep working. An empty ai_provider meant the removed
 *   TaskProcessing path and becomes `disabled`. Anything unreadable fails closed to `disabled`.
 * - isEnabledNow(): drops the per-process app config cache first, because the gateway uses it
 *   right before sending and a long-running job must see an admin switching AI off.
 * - save(): one write of the whole snapshot, plus a copy per provider+preset so switching back
 *   restores the old settings; values are never inherited by another backend.
 * - 5.7.0 scope: only the backends 5.5 already had (Gemini, Ollama) can be selected. The
 *   openai_compatible and anthropic adapters stay in the code for 5.8.0, but save() refuses them
 *   and load() reads a stored one as disabled, so none of them can be switched on.
 */
class ConnectionStore {
    public const APP = 'learning';
    public const KEY = 'ai_connection';
    public const SAVED_PREFIX = 'ai_connection_saved_';
    /** Providers an admin can switch on in this release (5.7.0); the rest arrive with 5.8.0. */
    public const SELECTABLE_PROVIDERS = ['gemini', 'ollama', 'disabled'];

    /** The 5.5 GeminiProvider endpoint and model; the migrated key is bound to exactly this. */
    public const GEMINI_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';
    public const GEMINI_MODEL = 'gemini-2.5-flash';
    public const LEGACY_OLLAMA_URL = 'http://localhost:11434';
    public const LEGACY_OLLAMA_MODEL = 'llama3';

    public function __construct(
        private IAppConfig $appConfig,
        private AuditService $auditService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The Gemini connection the 5.5 configuration described. Single source for the legacy
     * derivation and the key migration, so both compute the same connection fingerprint.
     */
    public static function legacyGemini(bool $enabled): LlmConnection {
        return LlmConnection::fromArray([
            'enabled' => $enabled,
            'provider' => 'gemini',
            'baseUrl' => self::GEMINI_BASE_URL,
            'model' => self::GEMINI_MODEL,
        ]);
    }

    public function load(): LlmConnection {
        // Only a missing snapshot falls back to the 5.5 keys. An existing but empty (or lazily
        // stored, hence unreadable here) value is broken and fails closed like invalid JSON.
        if (!$this->appConfig->hasKey(self::APP, self::KEY, null)) {
            return $this->deriveFromLegacy();
        }
        $raw = $this->appConfig->getValueString(self::APP, self::KEY, '');
        if ($raw === '') {
            $this->logger->warning('ai_connection is empty; AI is treated as disabled', ['app' => self::APP]);
            return self::disabled();
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->logger->warning('ai_connection is not valid JSON; AI is treated as disabled', ['app' => self::APP]);
            return self::disabled();
        }
        try {
            $c = LlmConnection::fromArray($data);
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('ai_connection is invalid; AI is treated as disabled: ' . $e->getMessage(), ['app' => self::APP]);
            return self::disabled();
        }
        if (!in_array($c->provider, self::SELECTABLE_PROVIDERS, true)) {
            $this->logger->warning('ai_connection names a provider that is not available in this release; AI is treated as disabled', ['app' => self::APP]);
            return self::disabled();
        }
        return $c;
    }

    public function isEnabledNow(): bool {
        $this->appConfig->clearCache();
        $c = $this->load();
        return $c->enabled && $c->provider !== 'disabled';
    }

    /**
     * @throws \InvalidArgumentException for a provider that is not selectable in this release
     */
    public function save(LlmConnection $c, string $adminUid): void {
        if (!in_array($c->provider, self::SELECTABLE_PROVIDERS, true)) {
            throw new \InvalidArgumentException('Provider ' . $c->provider . ' is not available in this release');
        }
        $rev = max($this->load()->rev, $c->rev) + 1;
        $stored = LlmConnection::fromArray(array_merge($c->toArray(), ['rev' => $rev]));
        $json = json_encode($stored->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->appConfig->setValueString(self::APP, self::KEY, $json);
        if ($stored->provider !== 'disabled') {
            $this->appConfig->setValueString(self::APP, self::SAVED_PREFIX . $stored->provider . '_' . $stored->preset, $json);
        }

        $recipient = (new LlmSession($stored, null, LlmPurpose::AdminTest))->recipient();
        $this->auditService->logEvent('ai_connection_changed', $adminUid, [
            'rev' => $stored->rev,
            'enabled' => $stored->enabled,
            'provider' => $stored->provider,
            'preset' => $stored->preset,
            'recipient' => $recipient->name,
            'connection_fp' => substr($stored->connectionFingerprint(), 0, 12),
            'recipient_fp' => substr($stored->recipientFingerprint(), 0, 12),
        ]);
    }

    private function deriveFromLegacy(): LlmConnection {
        $provider = strtolower(trim($this->appConfig->getValueString(self::APP, 'ai_provider', '')));
        $enabled = $this->appConfig->getValueString(self::APP, 'ai_enabled', 'no') === 'yes';

        if ($provider === 'gemini') {
            return self::legacyGemini($enabled);
        }
        if ($provider === 'ollama') {
            $url = trim($this->appConfig->getValueString(self::APP, 'ai_ollama_url', ''));
            $model = trim($this->appConfig->getValueString(self::APP, 'ai_ollama_model', ''));
            try {
                return LlmConnection::fromArray([
                    'enabled' => $enabled,
                    'provider' => 'ollama',
                    'baseUrl' => $url !== '' ? $url : self::LEGACY_OLLAMA_URL,
                    'model' => $model !== '' ? $model : self::LEGACY_OLLAMA_MODEL,
                    // 5.5 connected to the configured host without an internal-network gate.
                    'allowInternalNetwork' => true,
                    // A URL does not prove local processing; the admin confirms it explicitly.
                    'processingLocal' => false,
                ]);
            } catch (\InvalidArgumentException $e) {
                $this->logger->warning('Legacy Ollama configuration is invalid; AI is treated as disabled: ' . $e->getMessage(), ['app' => self::APP]);
                return self::disabled();
            }
        }
        return self::disabled();
    }

    private static function disabled(): LlmConnection {
        return LlmConnection::fromArray(['provider' => 'disabled']);
    }
}
