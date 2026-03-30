<?php
declare(strict_types=1);

namespace OCA\Learning\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

class LegalNoticeHelpSettings implements ISettings {
    public function __construct(
        private IL10N $l,
        private IURLGenerator $urlGenerator,
    ) {
    }

    public function getForm(): TemplateResponse {
        return new TemplateResponse('learning', 'settings/legal-link', [
            'title' => $this->l->t('Impressum'),
            'description' => $this->l->t('Die Betreiberangaben der DevCloud Lernplattform sind über eine eigene Seite erreichbar.'),
            'url' => $this->urlGenerator->linkToRoute('learning.page.impressum'),
            'linkLabel' => $this->l->t('Impressum öffnen'),
        ], TemplateResponse::RENDER_AS_BLANK);
    }

    public function getSection(): string {
        return 'tips-tricks';
    }

    public function getPriority(): int {
        return 31;
    }
}
