<?php
declare(strict_types=1);

namespace OCA\Learning\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

/**
 * The personal page is a mount point for the Vue island (src/personal-settings.js), which
 * loads its data through GET /api/settings/personal. The template reads no parameters
 * (audit #232).
 */
class PersonalSettings implements ISettings {
    public function getForm(): TemplateResponse {
        return new TemplateResponse('learning', 'settings/personal', [], TemplateResponse::RENDER_AS_BLANK);
    }

    public function getSection(): string {
        return 'learning';
    }

    public function getPriority(): int {
        return 50;
    }
}
