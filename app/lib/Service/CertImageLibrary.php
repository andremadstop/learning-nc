<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;

/**
 * An image library administrators keep for the certificates: the logo library (GitHub #78,
 * CertLogoLibrary) and the background library (GitHub #93, CertBackgroundLibrary).
 *
 * Storage: the bytes in the app's own data folder (appdata_<instance>/learning/<folder>/<sha256>),
 * never in a user's files; the list (id, name, type, size) in the app config. An image's id is the
 * sha256 of the bytes stored, so the same file cannot be added twice under two names.
 *
 * What an image must be (types, limits) is up to the library: check() and prepare(). What is
 * stored is a copy decoded and encoded again with GD (reencodedCopy()): only the pixels survive,
 * no EXIF or GPS data, no comments, nothing appended to the file. Entries stored that way carry
 * reencoded: true; logos stored before 5.17.0 (ZC9) do not, and stay as they were (no migration).
 *
 * A certificate never points into a library: choosing an image for a course copies it into the
 * course's certificate images (learning_cert_assets), and a certificate keeps the copy it was
 * issued with. Deleting a library image therefore never changes an issued certificate.
 */
abstract class CertImageLibrary {
    public const NAME_MAX = 80;
    public const MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp'];
    /** JPEG and WebP quality of a re-encoded copy */
    public const QUALITY = 88;
    /** lower JPEG and WebP qualities tried in turn while a re-encoded copy is over the limit */
    public const FALLBACK_QUALITIES = [80, 70];

    public function __construct(
        private IAppData $appData,
        private IConfig $config,
        private ITimeFactory $timeFactory,
    ) {
    }

    /** The app-data folder of the library. */
    abstract protected function folderName(): string;

    /** The app config key holding the list. */
    abstract protected function configKey(): string;

    /** How many images the library holds at most. */
    abstract protected function maxEntries(): int;

    /**
     * The type of stored bytes, checked again whenever they are read.
     *
     * @throws \InvalidArgumentException too_large, unsupported_type
     */
    abstract protected function check(string $bytes): string;

    /**
     * What is stored for an upload: reencodedCopy() within the library's limits.
     *
     * @return array{mime: string, bytes: string}
     * @throws \InvalidArgumentException too_large, unsupported_type
     */
    abstract protected function prepare(string $bytes): array;

    /**
     * The library, oldest first.
     *
     * @return list<array{id: string, name: string, mime: string, size: int, created_at: int, reencoded: bool}>
     */
    public function list(): array {
        $raw = $this->config->getAppValue('learning', $this->configKey(), '');
        $data = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $entry) {
            if (!is_array($entry) || !self::isId($entry['id'] ?? null)
                || !in_array($entry['mime'] ?? null, self::MIME_TYPES, true)) {
                continue;
            }
            $out[] = [
                'id' => (string)$entry['id'],
                'name' => is_string($entry['name'] ?? null) ? $entry['name'] : '',
                'mime' => (string)$entry['mime'],
                'size' => (int)($entry['size'] ?? 0),
                'created_at' => (int)($entry['created_at'] ?? 0),
                'reencoded' => ($entry['reencoded'] ?? false) === true,
            ];
        }
        return $out;
    }

    /** @return array{id: string, name: string, mime: string, size: int, created_at: int, reencoded: bool}|null */
    public function find(string $id): ?array {
        foreach ($this->list() as $entry) {
            if ($entry['id'] === $id) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * Add an image.
     *
     * @return array{id: string, name: string, mime: string, size: int, created_at: int, reencoded: bool}
     * @throws \InvalidArgumentException invalid_name, too_large, unsupported_type, duplicate, limit_reached
     */
    public function add(string $name, string $bytes): array {
        $name = self::cleanName($name);
        $prepared = $this->prepare($bytes);
        $bytes = $prepared['bytes'];
        $id = hash('sha256', $bytes);
        $list = $this->list();
        foreach ($list as $entry) {
            if ($entry['id'] === $id) {
                throw new \InvalidArgumentException('duplicate');
            }
        }
        if (count($list) >= $this->maxEntries()) {
            throw new \InvalidArgumentException('limit_reached');
        }
        $folder = $this->folder(true);
        if ($folder->fileExists($id)) {
            $folder->getFile($id)->putContent($bytes);
        } else {
            $folder->newFile($id, $bytes);
        }
        $entry = [
            'id' => $id,
            'name' => $name,
            'mime' => $prepared['mime'],
            'size' => strlen($bytes),
            'created_at' => $this->timeFactory->getTime(),
            'reencoded' => true,
        ];
        $list[] = $entry;
        $this->save($list);
        return $entry;
    }

    /**
     * @throws NotFoundException no such image
     * @throws \InvalidArgumentException invalid_name
     */
    public function rename(string $id, string $name): void {
        $name = self::cleanName($name);
        $list = $this->list();
        foreach ($list as $i => $entry) {
            if ($entry['id'] === $id) {
                $list[$i]['name'] = $name;
                $this->save($list);
                return;
            }
        }
        throw new NotFoundException('no such image');
    }

    /**
     * Remove an image from the library. The caller resets the courses that use it; issued
     * certificates keep their own copy.
     *
     * @throws NotFoundException no such image
     */
    public function delete(string $id): void {
        $list = $this->list();
        $kept = array_values(array_filter($list, static fn(array $entry): bool => $entry['id'] !== $id));
        if (count($kept) === count($list)) {
            throw new NotFoundException('no such image');
        }
        $this->save($kept);
        try {
            $folder = $this->folder(false);
            if ($folder->fileExists($id)) {
                $folder->getFile($id)->delete();
            }
        } catch (NotFoundException $e) {
            // no folder, nothing to delete
        }
    }

    /**
     * The bytes of an image, checked again on the way out.
     *
     * @return array{mime: string, bytes: string}
     * @throws NotFoundException no such image, or its file is gone
     */
    public function content(string $id): array {
        $entry = $this->find($id);
        if ($entry === null) {
            throw new NotFoundException('no such image');
        }
        $bytes = $this->folder(false)->getFile($id)->getContent();
        if (hash('sha256', $bytes) !== $id) {
            throw new NotFoundException('image file changed');
        }
        try {
            $mime = $this->check($bytes);
        } catch (\InvalidArgumentException $e) {
            throw new NotFoundException('image file is not an accepted image');
        }
        return ['mime' => $mime, 'bytes' => $bytes];
    }

    /**
     * The image type by content: PNG, JPEG or WebP within the given limits, anything else is
     * refused. Signature bytes and the image header must agree; the file name and the type the
     * browser claims are never asked. No SVG: an SVG can carry scripts.
     *
     * @return array{mime: string, width: int, height: int}
     * @throws \InvalidArgumentException too_large, unsupported_type
     */
    protected static function inspect(string $bytes, int $maxBytes, int $maxDimension): array {
        if (strlen($bytes) > $maxBytes) {
            throw new \InvalidArgumentException('too_large');
        }
        $byMagic = match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => IMAGETYPE_PNG,
            str_starts_with($bytes, "\xFF\xD8\xFF") => IMAGETYPE_JPEG,
            strlen($bytes) >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP' => IMAGETYPE_WEBP,
            default => null,
        };
        if ($byMagic === null) {
            throw new \InvalidArgumentException('unsupported_type');
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[2] !== $byMagic) {
            throw new \InvalidArgumentException('unsupported_type');
        }
        $width = (int)$info[0];
        $height = (int)$info[1];
        if ($width < 1 || $height < 1) {
            throw new \InvalidArgumentException('unsupported_type');
        }
        if ($width > $maxDimension || $height > $maxDimension) {
            throw new \InvalidArgumentException('too_large');
        }
        return [
            'mime' => match ($byMagic) {
                IMAGETYPE_PNG => 'image/png',
                IMAGETYPE_JPEG => 'image/jpeg',
                default => 'image/webp',
            },
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * What a library stores for an upload: a copy decoded and encoded again in its own type
     * (reencode()), within the same limits as the upload. A copy that comes out larger than
     * $maxBytes is refused as too large: the limit holds for what is stored.
     *
     * @return array{mime: string, bytes: string}
     * @throws \InvalidArgumentException too_large, unsupported_type
     */
    protected static function reencodedCopy(string $bytes, int $maxBytes, int $maxDimension): array {
        $mime = self::inspect($bytes, $maxBytes, $maxDimension)['mime'];
        $clean = self::reencode($bytes, $mime, $maxBytes);
        if (strlen($clean) > $maxBytes) {
            throw new \InvalidArgumentException('too_large');
        }
        // what is stored must pass the same check as on the way out
        if (self::inspect($clean, $maxBytes, $maxDimension)['mime'] !== $mime) {
            throw new \InvalidArgumentException('unsupported_type');
        }
        return ['mime' => $mime, 'bytes' => $clean];
    }

    /**
     * Decode and encode the image again in its own type: only the pixels survive.
     *
     * With $maxBytes, a JPEG or WebP copy over that size is written again at the lower qualities of
     * FALLBACK_QUALITIES (release review 5.17.0): a JPEG saved at quality 60–75 grows when written
     * at QUALITY, and a file within the limit as uploaded would otherwise be refused as too large.
     * The smallest copy is returned; the caller checks the limit. A PNG is lossless and stays as is.
     *
     * @throws \InvalidArgumentException unsupported_type (GD cannot read or write it, e.g. an animated WebP)
     */
    public static function reencode(string $bytes, string $mime, ?int $maxBytes = null): string {
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
        $out = self::encode($image, $mime, self::QUALITY);
        if ($maxBytes !== null && $mime !== 'image/png') {
            foreach (self::FALLBACK_QUALITIES as $quality) {
                if (strlen($out) <= $maxBytes) {
                    break;
                }
                $out = self::encode($image, $mime, $quality);
            }
        }
        return $out;
    }

    /**
     * @throws \InvalidArgumentException unsupported_type (GD could not write it)
     */
    private static function encode(\GdImage $image, string $mime, int $quality): string {
        ob_start();
        try {
            $ok = match ($mime) {
                'image/png' => imagepng($image, null, 9),
                'image/jpeg' => imagejpeg($image, null, $quality),
                default => imagewebp($image, null, $quality),
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
    public static function isId(mixed $value): bool {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/', $value) === 1;
    }

    /**
     * @throws \InvalidArgumentException invalid_name
     */
    private static function cleanName(string $name): string {
        $name = trim(CertLayout::stripInvisible((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name), false));
        if ($name === '' || preg_match('//u', $name) !== 1 || mb_strlen($name) > self::NAME_MAX) {
            throw new \InvalidArgumentException('invalid_name');
        }
        return $name;
    }

    /** @param list<array{id: string, name: string, mime: string, size: int, created_at: int, reencoded: bool}> $list */
    private function save(array $list): void {
        if ($list === []) {
            $this->config->deleteAppValue('learning', $this->configKey());
            return;
        }
        $this->config->setAppValue('learning', $this->configKey(), (string)json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @throws NotFoundException when $create is false and there is no folder yet */
    private function folder(bool $create): ISimpleFolder {
        try {
            return $this->appData->getFolder($this->folderName());
        } catch (NotFoundException $e) {
            if (!$create) {
                throw $e;
            }
            return $this->appData->newFolder($this->folderName());
        }
    }
}
