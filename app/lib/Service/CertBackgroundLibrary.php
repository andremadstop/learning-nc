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
 * A JPEG's EXIF orientation is applied to the pixels first (C10 review): the copy has no EXIF, and
 * a phone photo taken upright would otherwise lie on its side.
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
        if ($mime === 'image/jpeg') {
            // the copy carries no EXIF, so it must already stand the way the photo is shown
            $image = self::orient($image, self::jpegOrientation($bytes));
        } else {
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

    /**
     * The EXIF orientation (1–8) of a JPEG: the tag 0x0112 in IFD0 of the first Exif APP1 segment.
     * Browsers turn a photo by this tag, and phones store an upright photo as a landscape one with
     * orientation 6. Read from untrusted bytes: every offset is checked against the segment, and
     * anything unexpected (no tag, a broken segment, a value outside 1–8) gives 1, the image as
     * stored. Never refuses an upload.
     */
    public static function jpegOrientation(string $bytes): int {
        $length = strlen($bytes);
        if ($length < 4 || !str_starts_with($bytes, "\xFF\xD8")) {
            return 1;
        }
        $at = 2;
        while ($at + 4 <= $length) {
            if ($bytes[$at] !== "\xFF") {
                return 1;
            }
            $marker = ord($bytes[$at + 1]);
            if ($marker === 0xFF) {
                $at++; // fill byte
                continue;
            }
            if ($marker === 0xDA || $marker === 0xD9) {
                return 1; // image data or the end: no metadata after this
            }
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD8)) {
                $at += 2; // markers without a length
                continue;
            }
            $segment = self::u16($bytes, $at + 2, false);
            if ($segment < 2 || $at + 2 + $segment > $length) {
                return 1;
            }
            if ($marker === 0xE1 && $segment >= 8 && substr($bytes, $at + 4, 6) === "Exif\0\0") {
                return self::exifOrientation(substr($bytes, $at + 10, $segment - 8));
            }
            $at += 2 + $segment;
        }
        return 1;
    }

    /** The orientation tag of a TIFF block (the Exif payload after "Exif\0\0"), 1 when unreadable. */
    private static function exifOrientation(string $tiff): int {
        $length = strlen($tiff);
        if ($length < 8) {
            return 1;
        }
        $order = substr($tiff, 0, 2);
        if ($order !== 'II' && $order !== 'MM') {
            return 1;
        }
        $le = $order === 'II';
        if (self::u16($tiff, 2, $le) !== 42) {
            return 1;
        }
        $ifd = self::u32($tiff, 4, $le);
        if ($ifd < 8 || $ifd + 2 > $length) {
            return 1;
        }
        $count = self::u16($tiff, $ifd, $le);
        for ($i = 0; $i < $count; $i++) {
            $entry = $ifd + 2 + 12 * $i;
            if ($entry + 12 > $length) {
                return 1;
            }
            if (self::u16($tiff, $entry, $le) !== 0x0112) {
                continue;
            }
            // SHORT, one value, stored in the first two bytes of the value field
            if (self::u16($tiff, $entry + 2, $le) !== 3 || self::u32($tiff, $entry + 4, $le) !== 1) {
                return 1;
            }
            $value = self::u16($tiff, $entry + 8, $le);
            return $value >= 1 && $value <= 8 ? $value : 1;
        }
        return 1;
    }

    /**
     * Turn and mirror the pixels the way the orientation tag says. Without GD's imagerotate or
     * imageflip the image stays as stored.
     */
    private static function orient(\GdImage $image, int $orientation): \GdImage {
        if ($orientation <= 1 || $orientation > 8 || !function_exists('imagerotate') || !function_exists('imageflip')) {
            return $image;
        }
        // imagerotate turns counter-clockwise: 270 = a quarter turn clockwise
        $angle = match ($orientation) {
            3, 4 => 180,
            5, 6 => 270,
            7, 8 => 90,
            default => 0,
        };
        if ($angle !== 0) {
            $rotated = @imagerotate($image, $angle, 0);
            if ($rotated === false) {
                return $image;
            }
            $image = $rotated;
        }
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            @imageflip($image, IMG_FLIP_HORIZONTAL);
        }
        return $image;
    }

    /** Unsigned 16 bits at $at, -1 when the bytes are not there. */
    private static function u16(string $bytes, int $at, bool $littleEndian): int {
        if ($at < 0 || $at + 2 > strlen($bytes)) {
            return -1;
        }
        $value = unpack($littleEndian ? 'v' : 'n', $bytes, $at);
        return is_array($value) ? (int)$value[1] : -1;
    }

    /** Unsigned 32 bits at $at, -1 when the bytes are not there. */
    private static function u32(string $bytes, int $at, bool $littleEndian): int {
        if ($at < 0 || $at + 4 > strlen($bytes)) {
            return -1;
        }
        $value = unpack($littleEndian ? 'V' : 'N', $bytes, $at);
        return is_array($value) ? (int)$value[1] : -1;
    }
}
