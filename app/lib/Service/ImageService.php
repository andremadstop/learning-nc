<?php
declare(strict_types=1);
namespace OCA\Learning\Service;

use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException as FilesNotFoundException;

class ImageService {
    private IRootFolder $rootFolder;

    private const IMAGE_DIR = 'Learning/images';
    private const MAX_SIZE = 5 * 1024 * 1024; // 5MB
    private const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(IRootFolder $rootFolder) {
        $this->rootFolder = $rootFolder;
    }

    public function upload(string $userId, array $fileData): string {
        if ($fileData['size'] > self::MAX_SIZE) {
            throw new \InvalidArgumentException('File too large (max 5MB)');
        }

        $mimeType = $fileData['type'] ?? '';
        if (!in_array($mimeType, self::ALLOWED_TYPES)) {
            throw new \InvalidArgumentException('Invalid file type. Allowed: JPEG, PNG, GIF, WebP');
        }

        $userFolder = $this->rootFolder->getUserFolder($userId);

        if (!$userFolder->nodeExists(self::IMAGE_DIR)) {
            $userFolder->newFolder(self::IMAGE_DIR);
        }

        $imageDir = $userFolder->get(self::IMAGE_DIR);
        $ext = $this->getExtension($mimeType);
        $filename = uniqid('q_') . '.' . $ext;

        $content = file_get_contents($fileData['tmp_name']);
        $imageDir->newFile($filename, $content);

        return $userId . '/' . self::IMAGE_DIR . '/' . $filename;
    }

    public function getFile(string $imagePath): \OCP\Files\File {
        $parts = explode('/', $imagePath, 2);
        if (count($parts) !== 2) {
            throw new \InvalidArgumentException('Invalid image path');
        }

        $userId = $parts[0];
        $relativePath = $parts[1];

        $userFolder = $this->rootFolder->getUserFolder($userId);
        $node = $userFolder->get($relativePath);

        if (!($node instanceof \OCP\Files\File)) {
            throw new \InvalidArgumentException('Not a file');
        }

        return $node;
    }

    public function delete(string $imagePath): void {
        try {
            $file = $this->getFile($imagePath);
            $file->delete();
        } catch (FilesNotFoundException $e) {
            // Already gone, that's fine
        }
    }

    private function getExtension(string $mimeType): string {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
        return $map[$mimeType] ?? 'bin';
    }
}
