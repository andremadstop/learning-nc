<?php
declare(strict_types=1);

namespace OCA\Learning\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

/**
 * The admin page is a mount point for the Vue island (src/admin-settings.js), which loads its
 * data through the API. The template reads no parameters; computing them decrypted the Gemini
 * key on every page view for a value that was thrown away (audit #232).
 */
class AdminSettings implements ISettings {
    public function getForm(): TemplateResponse {
        return new TemplateResponse('learning', 'settings/admin', [], TemplateResponse::RENDER_AS_BLANK);
    }

    public function getSection(): string {
        return 'learning';
    }

    public function getPriority(): int {
        return 50;
    }
}
