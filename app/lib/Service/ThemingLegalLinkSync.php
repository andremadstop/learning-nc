<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCP\IConfig;
use OCP\IURLGenerator;

/**
 * Points the Nextcloud imprint and privacy links (theming) at Learning's pages - only once an admin
 * has entered the operator details, and only where no link is set yet (or the link is Learning's own
 * from an earlier address). A link the admin set elsewhere is never overwritten.
 *
 * Before the audit 2026-11 this ran unconditionally on every boot, so installations whose operator
 * was never entered linked the Nextcloud footer to pages naming the app author's company.
 */
class ThemingLegalLinkSync {
    private const LINKS = [
        'privacyUrl' => ['route' => 'learning.page.privacy', 'own' => '/apps/learning/privacy'],
        'imprintUrl' => ['route' => 'learning.page.impressum', 'own' => '/apps/learning/impressum'],
    ];

    public function __construct(
        private IConfig $config,
        private IURLGenerator $urlGenerator,
        private OperatorInfo $operatorInfo,
    ) {
    }

    public function sync(): void {
        if (!$this->operatorInfo->isConfigured()) {
            return;
        }
        foreach (self::LINKS as $key => $link) {
            $desired = $this->urlGenerator->linkToRouteAbsolute($link['route']);
            $current = $this->config->getAppValue('theming', $key, '');
            if (($current === '' || str_contains($current, $link['own'])) && $current !== $desired) {
                $this->config->setAppValue('theming', $key, $desired);
            }
        }
    }
}
