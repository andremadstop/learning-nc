<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Settings;

use OCA\Learning\Settings\AdminSettings;
use OCA\Learning\Settings\PersonalSettings;
use PHPUnit\Framework\TestCase;

/**
 * Audit #232: both settings templates are mount points for Vue islands that load their data
 * through the API. getForm() used to compute parameters nobody read — the admin page even
 * decrypted the Gemini key on every view. The forms now need no services at all.
 */
class AdminSettingsTest extends TestCase {
    public function testAdminFormPassesNoParameters(): void {
        $form = (new AdminSettings())->getForm();
        $this->assertSame([], $form->getParams());
        $this->assertSame('settings/admin', $form->getTemplateName());
    }

    public function testPersonalFormPassesNoParameters(): void {
        $form = (new PersonalSettings())->getForm();
        $this->assertSame([], $form->getParams());
        $this->assertSame('settings/personal', $form->getTemplateName());
    }
}
