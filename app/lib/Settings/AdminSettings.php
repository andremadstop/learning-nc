<?php
declare(strict_types=1);

namespace OCA\Learning\Settings;

use OCA\Learning\Service\Llm\ApiKeyStore;
use OCA\Learning\Service\Llm\ConnectionStore;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {
    private IConfig $config;
    private ApiKeyStore $apiKeyStore;

    public function __construct(IConfig $config, ApiKeyStore $apiKeyStore) {
        $this->config = $config;
        $this->apiKeyStore = $apiKeyStore;
    }

    public function getForm(): TemplateResponse {
        $params = [
            'daily_challenge_enabled' => $this->config->getAppValue('learning', 'daily_challenge_enabled', 'yes'),
            'default_language' => $this->config->getAppValue('learning', 'default_language', 'de'),
            'max_import_size_mb' => (int)$this->config->getAppValue('learning', 'max_import_size_mb', '2'),
            'gamification_enabled' => $this->config->getAppValue('learning', 'gamification_enabled', 'yes'),
            // the key the Gemini connection uses (encrypted slot after the upgrade), not the plaintext
            'gemini_api_key_set' => $this->apiKeyStore->get(ConnectionStore::legacyGemini(false)) !== null,
            'ai_provider' => $this->config->getAppValue('learning', 'ai_provider', 'gemini'),
            'ai_ollama_url' => $this->config->getAppValue('learning', 'ai_ollama_url', 'http://localhost:11434'),
            'ai_ollama_model' => $this->config->getAppValue('learning', 'ai_ollama_model', 'llama3'),
            'ai_enabled' => $this->config->getAppValue('learning', 'ai_enabled', 'no'),
        ];

        return new TemplateResponse('learning', 'settings/admin', $params, TemplateResponse::RENDER_AS_BLANK);
    }

    public function getSection(): string {
        return 'learning';
    }

    public function getPriority(): int {
        return 50;
    }
}
