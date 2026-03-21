<?php
declare(strict_types=1);

namespace OCA\Learning\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {
    private IConfig $config;

    public function __construct(IConfig $config) {
        $this->config = $config;
    }

    public function getForm(): TemplateResponse {
        $params = [
            'daily_challenge_enabled' => $this->config->getAppValue('learning', 'daily_challenge_enabled', 'yes'),
            'default_language' => $this->config->getAppValue('learning', 'default_language', 'de'),
            'max_import_size_mb' => (int)$this->config->getAppValue('learning', 'max_import_size_mb', '2'),
            'gamification_enabled' => $this->config->getAppValue('learning', 'gamification_enabled', 'yes'),
            'gemini_api_key_set' => $this->config->getAppValue('learning', 'gemini_api_key', '') !== '',
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
