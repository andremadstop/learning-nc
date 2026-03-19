<?php
declare(strict_types=1);

namespace OCA\Learning\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;

class PersonalSettings implements ISettings {
    private IConfig $config;
    private ?string $userId;

    public function __construct(IConfig $config, ?string $userId) {
        $this->config = $config;
        $this->userId = $userId;
    }

    public function getForm(): TemplateResponse {
        $userId = $this->userId ?? '';
        $params = [
            'daily_challenge' => $this->config->getUserValue($userId, 'learning', 'daily_challenge', 'yes'),
            'ui_language' => $this->config->getUserValue($userId, 'learning', 'ui_language', ''),
            'content_language' => $this->config->getUserValue($userId, 'learning', 'content_language', ''),
            'notifications_enabled' => $this->config->getUserValue($userId, 'learning', 'notifications_enabled', 'yes'),
        ];

        return new TemplateResponse('learning', 'settings/personal', $params, TemplateResponse::RENDER_AS_BLANK);
    }

    public function getSection(): string {
        return 'learning';
    }

    public function getPriority(): int {
        return 50;
    }
}
