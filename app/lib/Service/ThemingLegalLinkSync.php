<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCP\IConfig;
use OCP\IURLGenerator;

/**
 * Points the Nextcloud imprint and privacy links (theming) at Learning's pages - only once an admin
 * has entered the operator details, and only where no link is set yet. A link the admin set
 * elsewhere is never overwritten, and neither is Learning's own link (recognised by its path).
 *
 * Before the audit 2026-11 this ran unconditionally on every boot, so installations whose operator
 * was never entered linked the Nextcloud footer to pages naming the app author's company. Until
 * audit #206 it still ran on every request and rewrote its own link whenever the host differed:
 * cron and occ build absolute URLs from overwrite.cli.url, the web from the request, so the two
 * kept flipping the stored value. It now runs when an admin saves the operator details
 * (LegalOperatorController), in a web request with the instance's real address.
 */
class ThemingLegalLinkSync {
    private const LINKS = [
        'privacyUrl' => ['route' => 'learning.page.privacy'],
        'imprintUrl' => ['route' => 'learning.page.impressum'],
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
            if ($this->config->getAppValue('theming', $key, '') !== '') {
                continue;
            }
            $this->config->setAppValue('theming', $key, $this->urlGenerator->linkToRouteAbsolute($link['route']));
        }
    }
}
