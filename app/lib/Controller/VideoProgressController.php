<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Db\CourseVideoMapper;
use OCA\Learning\Db\VideoProgressMapper;
use OCA\Learning\Service\VideoProgressService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\IRequest;

/**
 * VideoProgressController — the student-facing progress endpoints for the Phase-162 video/material
 * gate (VIDEO-04 heartbeat, VIDEO-07 document "Gelesen"). These are the INPUTS that feed the
 * server-side completion engine; the enforcement itself lives in TrainingService::startSession().
 *
 * SECURITY MODEL:
 *  - The acting user is ALWAYS the session-bound $userId injected by the DI container — NEVER a userId
 *    from the request body. A client cannot record progress for someone else.
 *  - Completion is decided ONLY by server-merged watch intervals reaching 95% (VideoProgressService,
 *    162-01). heartbeat() merely forwards the reported [start,end] interval; the service discards
 *    <5s pings and performs the atomic (CAS) interval merge — this controller re-implements NONE of
 *    that. A client "I finished" (complete()) is a HINT: we finalize only if the SERVER's stored
 *    covered_pct already crosses the threshold.
 *  - Responses never leak raw intervals — only covered_pct (a number) and completed (a bool).
 */
class VideoProgressController extends Controller {
    private VideoProgressService $service;
    private VideoProgressMapper $progressMapper;
    private CourseVideoMapper $videoMapper;
    private ?string $userId;

    public function __construct(
        string $appName,
        IRequest $request,
        VideoProgressService $service,
        VideoProgressMapper $progressMapper,
        CourseVideoMapper $videoMapper,
        ?string $userId
    ) {
        parent::__construct($appName, $request);
        $this->service = $service;
        $this->progressMapper = $progressMapper;
        $this->videoMapper = $videoMapper;
        $this->userId = $userId;
    }

    /**
     * VIDEO-04: record a watch heartbeat. The client reports the [start,end] seconds it just watched;
     * the server (recordHeartbeat) discards <5s pings and atomically merges the interval — the client
     * cannot fake coverage. Returns the current server-computed covered_pct + completed flag ONLY
     * (never the raw interval set).
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 60, period: 60)]
    public function heartbeat(int $contentId, float $start, float $end): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            // Server is the source of truth: recordHeartbeat sanitizes the interval, discards <5s pings,
            // and performs the CAS-serialized merge (162-01). This controller only delegates.
            $this->service->recordHeartbeat($this->userId, $contentId, [$start, $end]);
        } catch (DoesNotExistException $e) {
            return new DataResponse(['error' => 'Unknown content'], Http::STATUS_NOT_FOUND);
        } catch (\Exception $e) {
            return new DataResponse(['error' => 'Failed to record progress'], Http::STATUS_BAD_REQUEST);
        }
        return new DataResponse($this->progressState($contentId));
    }

    /**
     * Defensive server-side finalization. The client "I finished" is a HINT, never authoritative: we
     * mark complete ONLY if the SERVER's stored covered_pct already crosses the 95% threshold
     * (VideoProgressService::decideComplete). For third-party embeds (vimeo / youtube-nocookie) the
     * server only has the intervals the player API reported via heartbeat — with no server-side
     * intervals there is nothing to finalize, so completion stays honest best-effort via those pings.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function complete(int $contentId): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            $video = $this->videoMapper->findById($contentId);
        } catch (DoesNotExistException $e) {
            return new DataResponse(['error' => 'Unknown content'], Http::STATUS_NOT_FOUND);
        }
        $row = $this->progressMapper->findByUserAndContent($this->userId, $contentId);
        if ($row === null || $row->getCompletedAt() === null) {
            $covered = $row?->getCoveredPct();
            // decideComplete(null) is false — an unwatched/underwatched video cannot be self-declared done.
            if ($this->service->decideComplete($covered !== null ? (float)$covered : null)) {
                $this->service->markComplete($this->userId, $contentId, (int)$video->getCourseId());
            }
        }
        return new DataResponse($this->progressState($contentId));
    }

    /**
     * VIDEO-07: a document "Gelesen" click records a server-side read that satisfies the same gate
     * query as a completed video (no intervals, no duration requirement). Idempotent (double-click safe
     * inside the service). The courseId is resolved from the registry row — never taken from the body.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function documentRead(int $contentId): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            $video = $this->videoMapper->findById($contentId);
        } catch (DoesNotExistException $e) {
            return new DataResponse(['error' => 'Unknown content'], Http::STATUS_NOT_FOUND);
        }
        // SECURITY (gate-bypass): the registry holds videos AND documents, and the gate query only
        // checks completed_at IS NOT NULL. Without this guard a student could POST document-read with a
        // VIDEO's contentId and unlock the quiz WITHOUT watching. Only genuine document material may be
        // marked "read"; video sources must be watched. Mirrors isDurationValidForGate's video/doc split.
        if (in_array($video->getSourceType(), ['nc-files', 'vimeo', 'youtube-nocookie'], true)) {
            return new DataResponse(['error' => 'Content is a video — it must be watched, not marked read'], Http::STATUS_BAD_REQUEST);
        }
        $this->service->markDocumentRead($this->userId, $contentId, (int)$video->getCourseId());
        return new DataResponse(['completed' => true, 'covered_pct' => 1.0]);
    }

    /**
     * Build the leak-free progress view: covered_pct (number) + completed (bool) only — the raw
     * intervals_json is DSGVO-transient server state and never crosses to the client. A completed row
     * reports 1.0 (its transient covered_pct was nulled at completion).
     *
     * @return array{completed: bool, covered_pct: float}
     */
    private function progressState(int $contentId): array {
        $row = $this->progressMapper->findByUserAndContent((string)$this->userId, $contentId);
        if ($row === null) {
            return ['completed' => false, 'covered_pct' => 0.0];
        }
        $completed = $row->getCompletedAt() !== null;
        $covered = $completed ? 1.0 : (float)($row->getCoveredPct() ?? 0.0);
        return ['completed' => $completed, 'covered_pct' => $covered];
    }
}
