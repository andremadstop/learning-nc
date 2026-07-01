<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\CourseVideo;

/**
 * NcFilesVideoAdapter — the FIRST-CLASS, fully-built video source (Phase 162, VIDEO-01). It declares
 * the nc-files source type and builds the playback DESCRIPTOR the client uses to stream through the
 * enrollment-gated /api/video-stream/{id} endpoint.
 *
 * The security-critical byte path (instructor-namespace resolution, registry-only path, enrollment
 * gate, Range-206/416 hardening) lives ENTIRELY in VideoStreamController so the whole IDOR/traversal
 * surface is one reviewable file. This adapter therefore holds NO file handle and reads NO request
 * parameter — it only maps a registry row to a self-describing client descriptor.
 */
class NcFilesVideoAdapter implements VideoSourceAdapter {
    public const SOURCE_TYPE = 'nc-files';

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
}
