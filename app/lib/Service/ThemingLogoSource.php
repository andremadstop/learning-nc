<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCP\App\IAppManager;
use OCP\Files\AppData\IAppDataFactory;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * GitHub #78: the bytes of the instance's current Theming logo, so a certificate can keep the logo
 * it was issued with when an admin replaces the Theming logo later.
 *
 * Read through public interfaces only (the theming app's data folder and its "logoMime" setting),
 * never through OCA\Theming classes. The layout global/images/logo (+ logo.png, a raster copy the
 * theming app makes of an SVG logo when Imagick is there) is the same in Nextcloud 33, 34 and 35.
 * Theming deletes logo.png whenever the logo is replaced, so a logo.png present belongs to the
 * current logo.
 *
 * Only PNG, JPEG and WebP within the limits of the logo library (CertLogoLibrary::detectImage).
 * An SVG logo without a raster copy, a GIF, a file over the limits, no custom logo or Theming
 * switched off give null: the certificate then shows the live Theming logo as before. Never
 * throws: a logo must never stop a certificate from being issued.
 */
class ThemingLogoSource {
    public function __construct(
        private IAppDataFactory $appDataFactory,
        private IConfig $config,
        private IAppManager $appManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{mime: string, bytes: string}|null
     */
    public function current(): ?array {
        try {
            if (!$this->appManager->isEnabledForAnyone('theming')) {
                return null;
            }
            if ($this->config->getAppValue('theming', 'logoMime', '') === '') {
                return null; // no custom logo: the Nextcloud logo, which does not change
            }
            $images = $this->appDataFactory->get('theming')->getFolder('global')->getFolder('images');
            foreach (['logo', 'logo.png'] as $name) {
                if (!$images->fileExists($name)) {
                    continue;
                }
                $bytes = $images->getFile($name)->getContent();
                try {
                    return ['mime' => CertLogoLibrary::detectImage($bytes), 'bytes' => $bytes];
                } catch (\InvalidArgumentException $e) {
                    continue; // SVG, GIF or over the limits: try the raster copy, else none
                }
            }
        } catch (\Throwable $e) {
            $this->logger->info('Certificate: the Theming logo could not be read, the certificate shows the live logo', ['exception' => $e]);
        }
        return null;
    }
}
