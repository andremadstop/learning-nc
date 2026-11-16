<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;

/**
 * GitHub #78: the certificate logo library. Administrators upload the official variants of their
 * logo once (primary, secondary, square, wordmark), give each a name, and every course picks one
 * for its certificates under "Certificate design" instead of the Theming logo.
 *
 * Storage: the bytes in the app's own data folder (appdata_<instance>/learning/cert-logos/<sha256>),
 * never in a user's files; the list (id, name, type, size) in the app config. A logo's id is the
 * sha256 of its bytes, so the same file cannot be added twice under two names.
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
class CertLogoLibrary {
    public const MAX_LOGOS = 20;
    public const NAME_MAX = 80;
    public const MAX_BYTES = CertLayoutService::MAX_BYTES;
    public const MAX_DIMENSION = CertLayoutService::MAX_DIMENSION;
    public const MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    private const FOLDER = 'cert-logos';
    private const CONFIG_KEY = 'cert_logo_library';

    public function __construct(
        private IAppData $appData,
        private IConfig $config,
        private ITimeFactory $timeFactory,
    ) {
    }

    /**
     * The library, oldest first.
     *
     * @return list<array{id: string, name: string, mime: string, size: int, created_at: int}>
     */
    public function list(): array {
        $raw = $this->config->getAppValue('learning', self::CONFIG_KEY, '');
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
            ];
        }
        return $out;
    }

    /** @return array{id: string, name: string, mime: string, size: int, created_at: int}|null */
    public function find(string $id): ?array {
        foreach ($this->list() as $entry) {
            if ($entry['id'] === $id) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * Add a logo.
     *
     * @return array{id: string, name: string, mime: string, size: int, created_at: int}
     * @throws \InvalidArgumentException invalid_name, too_large, unsupported_type, duplicate, limit_reached
     */
    public function add(string $name, string $bytes): array {
        $name = self::cleanName($name);
        $mime = self::detectImage($bytes);
        $id = hash('sha256', $bytes);
        $list = $this->list();
        foreach ($list as $entry) {
            if ($entry['id'] === $id) {
                throw new \InvalidArgumentException('duplicate');
            }
        }
        if (count($list) >= self::MAX_LOGOS) {
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
            'mime' => $mime,
            'size' => strlen($bytes),
            'created_at' => $this->timeFactory->getTime(),
        ];
        $list[] = $entry;
        $this->save($list);
        return $entry;
    }

    /**
     * @throws NotFoundException no such logo
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
        throw new NotFoundException('no such logo');
    }

    /**
     * Remove a logo from the library. The caller resets the courses that use it
     * (CertLayoutService::resetLogo); issued certificates keep their own copy.
     *
     * @throws NotFoundException no such logo
     */
    public function delete(string $id): void {
        $list = $this->list();
        $kept = array_values(array_filter($list, static fn(array $entry): bool => $entry['id'] !== $id));
        if (count($kept) === count($list)) {
            throw new NotFoundException('no such logo');
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
     * The bytes of a logo, checked again on the way out.
     *
     * @return array{mime: string, bytes: string}
     * @throws NotFoundException no such logo, or its file is gone
     */
    public function content(string $id): array {
        $entry = $this->find($id);
        if ($entry === null) {
            throw new NotFoundException('no such logo');
        }
        $bytes = $this->folder(false)->getFile($id)->getContent();
        if (hash('sha256', $bytes) !== $id) {
            throw new NotFoundException('logo file changed');
        }
        try {
            $mime = self::detectImage($bytes);
        } catch (\InvalidArgumentException $e) {
            throw new NotFoundException('logo file is not an accepted image');
        }
        return ['mime' => $mime, 'bytes' => $bytes];
    }

    /**
     * The image type by content: PNG, JPEG or WebP within the limits, anything else is refused.
     *
     * @throws \InvalidArgumentException too_large, unsupported_type
     */
    public static function detectImage(string $bytes): string {
        if (strlen($bytes) > self::MAX_BYTES) {
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
        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            throw new \InvalidArgumentException('too_large');
        }
        return match ($byMagic) {
            IMAGETYPE_PNG => 'image/png',
            IMAGETYPE_JPEG => 'image/jpeg',
            default => 'image/webp',
        };
    }

    public static function isId(mixed $value): bool {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/', $value) === 1;
    }

    /**
     * @throws \InvalidArgumentException invalid_name
     */
    private static function cleanName(string $name): string {
        $name = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));
        if ($name === '' || preg_match('//u', $name) !== 1 || mb_strlen($name) > self::NAME_MAX) {
            throw new \InvalidArgumentException('invalid_name');
        }
        return $name;
    }

    /** @param list<array{id: string, name: string, mime: string, size: int, created_at: int}> $list */
    private function save(array $list): void {
        if ($list === []) {
            $this->config->deleteAppValue('learning', self::CONFIG_KEY);
            return;
        }
        $this->config->setAppValue('learning', self::CONFIG_KEY, (string)json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @throws NotFoundException when $create is false and there is no folder yet */
    private function folder(bool $create): ISimpleFolder {
        try {
            return $this->appData->getFolder(self::FOLDER);
        } catch (NotFoundException $e) {
            if (!$create) {
                throw $e;
            }
            return $this->appData->newFolder(self::FOLDER);
        }
    }
}
