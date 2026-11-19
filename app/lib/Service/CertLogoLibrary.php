<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

/**
 * GitHub #78: the certificate logo library. Administrators upload the official variants of their
 * logo once (primary, secondary, square, wordmark), give each a name, and every course picks one
 * for its certificates under "Certificate design" instead of the Theming logo.
 *
 * Storage and list: CertImageLibrary (appdata_<instance>/learning/cert-logos/<sha256>, the list in
 * the app config). A logo is stored as uploaded; its id is the sha256 of its bytes.
 *
 * Only PNG, JPEG and WebP, recognised by their content (signature bytes and the image header), never
 * by the file name or the type the browser claims. No SVG: an SVG can carry scripts, and the
 * certificate with its logo is also shown on the public verification page. Same limits as the
 * stamp and signature (CertLayoutService): 1 MB and 3000 × 3000 pixels.
 *
 * A certificate never points into this library: choosing a logo for a course copies it into the
 * course's certificate images (learning_cert_assets), and a certificate keeps the copy it was
 * issued with. Deleting a library logo therefore never changes an issued certificate.
 */
class CertLogoLibrary extends CertImageLibrary {
    public const MAX_LOGOS = 20;
    public const MAX_BYTES = CertLayoutService::MAX_BYTES;
    public const MAX_DIMENSION = CertLayoutService::MAX_DIMENSION;

    protected function folderName(): string {
        return 'cert-logos';
    }

    protected function configKey(): string {
        return 'cert_logo_library';
    }

    protected function maxEntries(): int {
        return self::MAX_LOGOS;
    }

    protected function check(string $bytes): string {
        return self::detectImage($bytes);
    }

    /** A logo is stored as it was uploaded. */
    protected function prepare(string $bytes): array {
        return ['mime' => self::detectImage($bytes), 'bytes' => $bytes];
    }

    /**
     * The image type by content: PNG, JPEG or WebP within the logo limits, anything else is refused.
     * Also used for the copy of the Theming logo (ThemingLogoSource).
     *
     * @throws \InvalidArgumentException too_large, unsupported_type
     */
    public static function detectImage(string $bytes): string {
        return self::inspect($bytes, self::MAX_BYTES, self::MAX_DIMENSION)['mime'];
    }
}
