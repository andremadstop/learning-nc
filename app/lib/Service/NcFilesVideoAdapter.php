<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\CourseVideo;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;

/**
 * NcFilesVideoAdapter — the FIRST-CLASS, fully-built video source (Phase 162, VIDEO-01). It resolves
 * a registry video to an openable file handle in the INSTRUCTOR's Nextcloud namespace and hands the
 * VideoStreamController the descriptor for enrollment-gated Range-206 streaming.
 *
 * SECURITY MODEL (primary grumpy-Codex IDOR/path-traversal target):
 *   - The file path is ALWAYS $video->getVideoRef() from the registry row — NEVER a client-supplied
 *     path or filename. There is no code path here that reads a request parameter.
 *   - The file is opened in the INSTRUCTOR's user folder (getUserFolder($instructorId)), NOT the
 *     requesting student's — a student has no share/read grant on the instructor's private files.
 *   - Enrollment is asserted by the CALLER (VideoStreamController via
 *     CourseService::assertEnrolledInCourse) BEFORE this adapter is ever asked to resolve a file.
 */
class NcFilesVideoAdapter implements VideoSourceAdapter {
    public const SOURCE_TYPE = 'nc-files';

    public function __construct(
        private IRootFolder $rootFolder,
    ) {
    }

    public function getSourceType(): string {
        return self::SOURCE_TYPE;
    }

    public function supports(string $sourceType): bool {
        return $sourceType === self::SOURCE_TYPE;
    }

    /**
     * Playback descriptor for the native <video> element: the client streams through the
     * enrollment-gated /api/video-stream/{id} endpoint — never a direct NC share/file URL.
     *
     * @return array<string, mixed>
     */
    public function resolvePlayback(CourseVideo $video): array {
        return [
            'type' => self::SOURCE_TYPE,
            'stream_url' => '/apps/learning/api/video-stream/' . $video->getId(),
            'subtitle_ref' => $video->getSubtitleRef(),
            'title' => $video->getTitle(),
        ];
    }

    /**
     * Resolve the openable file handle for a registry video, ALWAYS from the instructor namespace and
     * ALWAYS from the registry path. The $instructorId comes from the course the caller has already
     * authorized (Course::getInstructorId()), never from the request.
     *
     * @throws NotFoundException if the registry-referenced file no longer exists in the instructor folder
     * @throws \RuntimeException if the referenced node is not a plain file (e.g. a folder)
     */
    public function resolveFile(CourseVideo $video, string $instructorId): File {
        $userFolder = $this->rootFolder->getUserFolder($instructorId);
        // Path source is the registry row ONLY — $video->getVideoRef() — never a client-supplied path.
        $node = $userFolder->get($video->getVideoRef());
        if (!($node instanceof File)) {
            throw new \RuntimeException('Registry video_ref does not point to a file');
        }
        return $node;
    }
}
