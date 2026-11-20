<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCP\Files\NotFoundException;

/**
 * GitHub #78: the certificate logo library. Administrators upload the official variants of their
 * logo once (primary, secondary, square, wordmark), give each a name, and every course picks one
 * for its certificates under "Certificate design" instead of the Theming logo.
 *
 * Storage and list: CertImageLibrary (appdata_<instance>/learning/cert-logos/<sha256>, the list in
 * the app config). Since 5.17.0 (ZC9, pre-existing before) a logo is decoded and encoded again with
 * GD before it is stored, like a background (CertBackgroundLibrary): EXIF and GPS data, comments and
 * anything appended to the file stay behind, a JPEG's EXIF orientation is applied to the pixels,
 * transparency is kept, a PNG stays a PNG. Before, a logo was stored as uploaded, and the EXIF data
 * of a JPEG logo reached every certificate and the public verification page. Its id is the sha256
 * of the stored bytes. A copy that comes out over the limit is refused as too large. Logos stored
 * before stay as they are in the library; a course choosing one gets a re-encoded copy (courseCopy()).
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

    protected function prepare(string $bytes): array {
        return self::reencodedCopy($bytes, self::MAX_BYTES, self::MAX_DIMENSION);
    }

    /**
     * What a course copies into its certificate images when it chooses the logo
     * (CertLayoutService::selectLogo()): the stored bytes of a logo re-encoded on upload, a
     * re-encoded copy of one stored before 5.17.0 (ZC9). The library itself is not changed.
     *
     * @return array{mime: string, bytes: string}
     * @throws NotFoundException no such logo, or its file is gone or changed
     * @throws \InvalidArgumentException too_large, unsupported_type (the old logo cannot be re-encoded within the limits)
     */
    public function courseCopy(string $id): array {
        $logo = $this->content($id);
        if ($this->find($id)['reencoded'] ?? false) {
            return $logo;
        }
        return self::reencodedCopy($logo['bytes'], self::MAX_BYTES, self::MAX_DIMENSION);
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
