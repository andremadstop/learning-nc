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
 * What an image must be (types, limits) and what is stored (the upload as it is, or a re-encoded
 * copy) is up to the library: check() and prepare().
 *
 * A certificate never points into a library: choosing an image for a course copies it into the
 * course's certificate images (learning_cert_assets), and a certificate keeps the copy it was
 * issued with. Deleting a library image therefore never changes an issued certificate.
 */
abstract class CertImageLibrary {
    public const NAME_MAX = 80;
    public const MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

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
     * What is stored for an upload.
     *
     * @return array{mime: string, bytes: string}
     * @throws \InvalidArgumentException too_large, unsupported_type
     */
    abstract protected function prepare(string $bytes): array;

    /**
     * The library, oldest first.
     *
     * @return list<array{id: string, name: string, mime: string, size: int, created_at: int}>
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
     * Add an image.
     *
     * @return array{id: string, name: string, mime: string, size: int, created_at: int}
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

    /** @param list<array{id: string, name: string, mime: string, size: int, created_at: int}> $list */
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
