<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

/**
 * GitHub #93: the certificate background library. Administrators upload backgrounds (ornaments, a
 * frame, the brand's colours), give each a name, and every course picks one under "Certificate
 * design", like the logo library (GitHub #78, CertLogoLibrary).
 *
 * Storage and list: CertImageLibrary (appdata_<instance>/learning/cert-backgrounds/<sha256>, the
 * list in the app config).
 *
 * Only PNG, JPEG and WebP, recognised by their content; never SVG. Limits: 2 MB and 4000 pixels per
 * edge (a landscape A4 sheet at about 340 dpi), any aspect ratio. The pixel count is checked from
 * the image header before anything is decoded.
 *
 * Every upload is decoded and encoded again with GD before it is stored. A background is a full
 * photo or scan more often than a logo is, and the copy drops whatever rode along with the pixels:
 * EXIF and GPS data, comments, colour profiles, and anything appended to the file (a "polyglot" that
 * is also an archive or a script). The id is the sha256 of the re-encoded bytes. A JPEG or WebP copy
 * larger than the limit is written again at a lower quality (release review 5.17.0); one still larger
 * is refused as too large, the limit holds for what is stored.
 * A JPEG's EXIF orientation is applied to the pixels first (C10 review): the copy has no EXIF, and
 * a phone photo taken upright would otherwise lie on its side.
 * Without GD support for the type the upload is refused, never stored unchecked.
 * The re-encoding itself (CertImageLibrary::reencode()) is shared with the logo library (ZC9).
 */
class CertBackgroundLibrary extends CertImageLibrary {
    public const MAX_BACKGROUNDS = 20;
    public const MAX_BYTES = 2097152;
    public const MAX_DIMENSION = 4000;

    protected function folderName(): string {
        return 'cert-backgrounds';
    }

    protected function configKey(): string {
        return 'cert_background_library';
    }

    protected function maxEntries(): int {
        return self::MAX_BACKGROUNDS;
    }

    protected function check(string $bytes): string {
        return self::detectImage($bytes);
    }

    protected function prepare(string $bytes): array {
        return self::reencodedCopy($bytes, self::MAX_BYTES, self::MAX_DIMENSION);
    }

    /**
     * The image type by content: PNG, JPEG or WebP within the background limits.
     *
     * @throws \InvalidArgumentException too_large, unsupported_type
     */
    public static function detectImage(string $bytes): string {
        return self::inspect($bytes, self::MAX_BYTES, self::MAX_DIMENSION)['mime'];
    }
}
