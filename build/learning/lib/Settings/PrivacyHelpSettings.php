<?php
declare(strict_types=1);

namespace OCA\Learning\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

class PrivacyHelpSettings implements ISettings {
    public function __construct(
        private IL10N $l,
        private IURLGenerator $urlGenerator,
    ) {
    }

    public function getForm(): TemplateResponse {
        return new TemplateResponse('learning', 'settings/legal-link', [
            'title' => $this->l->t('Datenschutzerklärung'),
            'description' => $this->l->t('Die vollständigen Datenschutzinformationen der Learning-App öffnen sich auf einer eigenen Seite.'),
            'url' => $this->urlGenerator->linkToRoute('learning.page.privacy'),
            'linkLabel' => $this->l->t('Datenschutzerklärung öffnen'),
        ], TemplateResponse::RENDER_AS_BLANK);
    }

    public function getSection(): string {
        return 'additional';
    }

    public function getPriority(): int {
        return 30;
    }
}
