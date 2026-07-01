<?php
declare(strict_types=1);

namespace OCA\Learning\Service;

use OCA\Learning\Db\CourseVideo;

/**
 * VideoSourceAdapter — the single contract every gated video source implements (Phase 162,
 * VIDEO-01/05). Three source types share this interface so the gate, the registry CRUD, and the
 * player front-end never branch on a hard-coded provider list:
 *
 *   - `nc-files`          — FIRST-CLASS, fully built this phase. Server-side Range-206 streaming
 *                           from the INSTRUCTOR's Nextcloud namespace (NcFilesVideoAdapter +
 *                           VideoStreamController). No third-party byte ever leaves the instance.
 *   - `vimeo`             — declared slot. Consent-gated CDN embed (DSGVO Art.13, dnt=1).
 *   - `youtube-nocookie`  — declared slot. youtube-nocookie.com + dnt=1 after explicit consent.
 *
 * The Vimeo/YouTube adapters ship as interface slots this phase (CONTEXT: "deferrable slots" — the
 * architecture must NOT need rework to add them). Only their playback DESCRIPTOR shape is fixed here;
 * their full player-progress integration is pulled through after NC-Files without touching callers.
 */
interface VideoSourceAdapter {
    /**
     * The canonical source_type string this adapter owns (e.g. 'nc-files').
     */
    public function getSourceType(): string;

    /**
     * Whether this adapter handles the given registry source_type.
     */
    public function supports(string $sourceType): bool;

    /**
     * Build the playback descriptor the client uses to render/stream the video. The shape is
     * source-specific but always self-describing via its `type` key:
     *
     *   nc-files         => ['type'=>'nc-files','stream_url'=>'/api/video-stream/{id}','subtitle_ref'=>?string]
     *   youtube-nocookie => ['type'=>'youtube-nocookie','embed_id'=>string,'requires_consent'=>true,'dnt'=>1]
     *   vimeo            => ['type'=>'vimeo','embed_id'=>string,'requires_consent'=>true,'dnt'=>1]
     *
     * The descriptor NEVER contains a raw NC share URL or a client-writable file path — nc-files
     * playback flows ONLY through the enrollment-gated /api/video-stream/{id} endpoint.
     *
     * @return array<string, mixed>
     */
    public function resolvePlayback(CourseVideo $video): array;
}
