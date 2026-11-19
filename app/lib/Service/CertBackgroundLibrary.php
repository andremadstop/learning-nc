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
 * is also an archive or a script). The id is the sha256 of the re-encoded bytes. A re-encoded file
 * that comes out larger than the limit is refused as too large, the limit holds for what is stored.
 * Without GD support for the type the upload is refused, never stored unchecked.
 */
class CertBackgroundLibrary extends CertImageLibrary {
    public const MAX_BACKGROUNDS = 20;
    public const MAX_BYTES = 2097152;
    public const MAX_DIMENSION = 4000;
    /** JPEG and WebP quality of the re-encoded copy */
    public const QUALITY = 88;

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
        $mime = self::detectImage($bytes);
        $clean = self::reencode($bytes, $mime);
        if (strlen($clean) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('too_large');
        }
        // what is stored must pass the same check as on the way out
        if (self::detectImage($clean) !== $mime) {
            throw new \InvalidArgumentException('unsupported_type');
        }
        return ['mime' => $mime, 'bytes' => $clean];
    }

    /**
     * The image type by content: PNG, JPEG or WebP within the background limits.
     *
     * @throws \InvalidArgumentException too_large, unsupported_type
     */
    public static function detectImage(string $bytes): string {
        return self::inspect($bytes, self::MAX_BYTES, self::MAX_DIMENSION)['mime'];
    }

    /**
     * Decode and encode the image again in its own type: only the pixels survive.
     *
     * @throws \InvalidArgumentException unsupported_type (GD cannot read or write it, e.g. an animated WebP)
     */
    public static function reencode(string $bytes, string $mime): string {
        $writer = match ($mime) {
            'image/png' => 'imagepng',
            'image/jpeg' => 'imagejpeg',
            'image/webp' => 'imagewebp',
            default => null,
        };
        if ($writer === null || !function_exists('imagecreatefromstring') || !function_exists($writer)) {
            throw new \InvalidArgumentException('unsupported_type');
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new \InvalidArgumentException('unsupported_type');
        }
        if ($mime !== 'image/jpeg') {
            // keep transparency: without these a transparent background comes out black
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }
        // (no imagedestroy(): a no-op since PHP 8.0, deprecated in 8.5; the image is freed with $image)
        ob_start();
        try {
            $ok = match ($mime) {
                'image/png' => imagepng($image, null, 9),
                'image/jpeg' => imagejpeg($image, null, self::QUALITY),
                default => imagewebp($image, null, self::QUALITY),
            };
        } finally {
            $out = (string)ob_get_clean();
        }
        if (!$ok || $out === '') {
            throw new \InvalidArgumentException('unsupported_type');
        }
        return $out;
    }
}
