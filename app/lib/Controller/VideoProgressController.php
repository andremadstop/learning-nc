<?php
declare(strict_types=1);
namespace OCA\Learning\Controller;

use OCA\Learning\Db\Course;
use OCA\Learning\Db\CourseDocumentMapper;
use OCA\Learning\Db\CourseVideo;
use OCA\Learning\Db\CourseVideoMapper;
use OCA\Learning\Db\VideoProgressMapper;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\DocumentService;
use OCA\Learning\Service\DocumentUnavailableException;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\RequiredDocumentService;
use OCA\Learning\Service\VideoProgressService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\IRequest;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;

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
#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]
class VideoProgressController extends Controller {
    /** Sources played by a provider's own player after consent (VideoConsentOverlay). */
    private const THIRD_PARTY_SOURCES = ['vimeo', 'youtube-nocookie'];

    private VideoProgressService $service;
    private VideoProgressMapper $progressMapper;
    private CourseVideoMapper $videoMapper;
    private CourseService $courseService;
    private ?string $userId;
    private CourseDocumentMapper $documentMapper;

    public function __construct(
        string $appName,
        IRequest $request,
        VideoProgressService $service,
        VideoProgressMapper $progressMapper,
        CourseVideoMapper $videoMapper,
        CourseService $courseService,
        ?string $userId,
        CourseDocumentMapper $documentMapper,
        private ?RequiredDocumentService $requiredDocuments = null,
    ) {
        parent::__construct($appName, $request);
        $this->service = $service;
        $this->progressMapper = $progressMapper;
        $this->videoMapper = $videoMapper;
        $this->courseService = $courseService;
        $this->userId = $userId;
        $this->documentMapper = $documentMapper;
    }

    /**
     * VIDEO-04: record a watch heartbeat. The client reports the [start,end] seconds it just watched;
     * the server (recordHeartbeat) discards <5s pings and atomically merges the interval — the client
     * cannot fake coverage. Returns the current server-computed covered_pct + completed flag ONLY
     * (never the raw interval set).
     */
    #[NoAdminRequired]
    public function heartbeat(int $contentId, float $start, float $end): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        $guard = $this->assertEnrolledForContent($contentId);
        if ($guard !== null) {
            return $guard;
        }
        try {
            // Server is the source of truth: recordHeartbeat sanitizes the interval, discards <5s pings,
            // and performs the CAS-serialized merge (162-01). This controller only delegates.
            $accepted = $this->service->recordHeartbeat($this->userId, $contentId, [$start, $end]);
        } catch (DoesNotExistException $e) {
            return new DataResponse(['error' => 'Unknown content'], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            return new DataResponse(['error' => 'Failed to record progress'], Http::STATUS_BAD_REQUEST);
        }
        // 'accepted' false: the ping was discarded (too soon after another tab's), the client keeps
        // the seconds and sends them again (audit #111).
        return new DataResponse($this->progressState($contentId) + ['accepted' => $accepted]);
    }

    /**
     * Defensive server-side finalization. The client "I finished" is a HINT, never authoritative: we
     * mark complete ONLY if the SERVER's stored covered_pct already crosses the 95% threshold
     * (VideoProgressService::decideComplete). For third-party embeds (vimeo / youtube-nocookie) the
     * intervals come from the provider's player API (VideoConsentOverlay sends them as heartbeats
     * after consent), under the same server caps as the native player.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 60)]
    public function complete(int $contentId): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        $guard = $this->assertEnrolledForContent($contentId);
        if ($guard !== null) {
            return $guard;
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
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 60)]
    public function documentRead(int $contentId): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        $guard = $this->assertEnrolledForContent($contentId);
        if ($guard !== null) {
            return $guard;
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
        if ($video->getSourceType() !== CourseVideoController::DOCUMENT_SOURCE_TYPE || $this->requiredDocuments === null) {
            return new DataResponse(['error' => 'Content is no document'], Http::STATUS_BAD_REQUEST);
        }
        // GitHub #75: appended per cycle and file version; a valid confirmation makes this a no-op.
        try {
            $state = $this->requiredDocuments->confirm($video, $this->userId);
        } catch (DocumentUnavailableException $e) {
            // GitHub #75 (review): nothing is recorded for a file nobody can open right now.
            return new DataResponse([
                'error' => 'The document cannot be opened right now',
                'code' => 'document_unavailable',
            ], Http::STATUS_CONFLICT);
        }
        return new DataResponse([
            'completed' => $state['confirmed'],
            'covered_pct' => $state['confirmed'] ? 1.0 : 0.0,
            'updated' => $state['updated'],
            'confirm_again' => $state['again'],
        ]);
    }

    /**
     * Build the leak-free progress view: covered_pct (number) + completed (bool) only — the raw
     * intervals_json is DSGVO-transient server state and never crosses to the client. A completed row
     * reports 1.0 (its transient covered_pct was nulled at completion).
     *
     * @return array{completed: bool, covered_pct: float}
     */
    /**
     * IDOR guard (Codex HIGH): resolve the registry row and assert the SESSION user is enrolled in its
     * course BEFORE any progress read/write — a student must not record/complete progress (and thereby
     * emit an immutable course.video.completed) for a course they are not in. Returns an error
     * DataResponse to short-circuit the action, or null to proceed.
     */
    private function assertEnrolledForContent(int $contentId): ?DataResponse {
        try {
            $video = $this->videoMapper->findById($contentId);
            $this->courseService->assertEnrolledInCourse((int)$video->getCourseId(), (string)$this->userId);
            // Fresh-eyes C5 (#75): an instructor of the course is no learner of it. In the student
            // view he could confirm a required document (and watch a required video to the end),
            // which wrote a confirmation row and an immutable course.video.completed event under his
            // name into the compliance chain. All three callers write progress, so all refuse.
            if ($this->courseService->canManageCourse((int)$video->getCourseId(), (string)$this->userId)) {
                return new DataResponse(['error' => 'Instructors of this course record no progress of their own'], Http::STATUS_FORBIDDEN);
            }
        } catch (DoesNotExistException $e) {
            return new DataResponse(['error' => 'Unknown content'], Http::STATUS_NOT_FOUND);
        } catch (ForbiddenException $e) {
            return new DataResponse(['error' => 'Not enrolled in this course'], Http::STATUS_FORBIDDEN);
        }
        return null;
    }

    /**
     * The course_documents id of a document row when document#download would serve it to a
     * member of $course (same course, in the current material folder), else null.
     */
    private function servableDocumentId(Course $course, CourseVideo $video): ?int {
        $documentId = CourseVideoController::documentIdFromRef($video->getVideoRef());
        if ($documentId === null) {
            return null;
        }
        try {
            $doc = $this->documentMapper->findById($documentId);
        } catch (DoesNotExistException $e) {
            return null;
        }
        if ((int)$doc->getCourseId() !== (int)$course->getId()
            || !DocumentService::isInMaterialFolder($doc, $course->getMaterialFolder())) {
            return null;
        }
        return $documentId;
    }

    private function progressState(int $contentId): array {
        $row = $this->progressMapper->findByUserAndContent((string)$this->userId, $contentId);
        if ($row === null) {
            return ['completed' => false, 'covered_pct' => 0.0];
        }
        $completed = $row->getCompletedAt() !== null;
        $covered = $completed ? 1.0 : (float)($row->getCoveredPct() ?? 0.0);
        return ['completed' => $completed, 'covered_pct' => $covered];
    }

    /**
     * VIDEO-07 student-read: the enrolled student's own gate status for a course — the course's video/
     * document registry annotated with THIS user's covered_pct + completed. Feeds the "Pflichtinhalte"
     * tab + gate-status UI (162-04). Instructor-only CRUD stays in CourseVideoController; this is the
     * read-only student mirror.
     *
     * SECURITY / IDOR:
     *  - The user is the session-bound $userId, never the request. Enrollment in $courseId is asserted
     *    via CourseService::assertEnrolledInCourse BEFORE any registry row is read — a non-member gets
     *    403, so this endpoint cannot enumerate another course's content.
     *  - Only the student's OWN progress is annotated (progressState is keyed on $this->userId).
     *  - DSGVO: the raw video_ref (instructor file path) and intervals_json are NOT returned — only
     *    id/source_type/title/duration/sort_order + covered_pct (number) + completed (bool), plus
     *    embed_id (the provider's public video id) for vimeo / youtube-nocookie only.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 120, period: 60)]
    public function courseStatus(int $courseId): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            $course = $this->courseService->assertEnrolledInCourse($courseId, $this->userId);
        } catch (ForbiddenException $e) {
            return new DataResponse(['error' => 'Not enrolled in this course'], Http::STATUS_FORBIDDEN);
        } catch (DoesNotExistException $e) {
            return new DataResponse(['error' => 'Unknown course'], Http::STATUS_NOT_FOUND);
        }
        $videos = [];
        $registry = $this->videoMapper->findByCourse($courseId);
        // GitHub #75: documents by the confirmation rule (cycle, file version, asked again).
        $documentStates = $this->requiredDocuments !== null
            ? $this->requiredDocuments->statesForUser($course, $registry, $this->userId)
            : [];
        foreach ($registry as $video) {
            $state = $this->progressState((int)$video->getId());
            $documentState = $documentStates[(int)$video->getId()] ?? null;
            if ($documentState !== null) {
                $state = ['completed' => $documentState['confirmed'], 'covered_pct' => $documentState['confirmed'] ? 1.0 : 0.0];
            }
            $row = [
                'id' => (int)$video->getId(),
                'source_type' => $video->getSourceType(),
                'title' => $video->getTitle(),
                'duration_seconds' => $video->getDurationSeconds() !== null ? (int)$video->getDurationSeconds() : null,
                'sort_order' => (int)$video->getSortOrder(),
                'covered_pct' => $state['covered_pct'],
                'completed' => $state['completed'],
            ];
            // A Vimeo / YouTube video is played by the provider's player, which needs the provider's
            // public video id. That id is no file path and only ever sent for these two sources; an
            // nc-files path or a document path stays on the server (audit 2026-11, H6).
            if (in_array($video->getSourceType(), self::THIRD_PARTY_SOURCES, true)) {
                $row['embed_id'] = (string)$video->getVideoRef();
            }
            // GitHub #35 (B4): a required document is opened through document#download. Only its
            // material-folder document id is sent, and only while that download would serve it;
            // a path never leaves the server.
            if ($video->getSourceType() === CourseVideoController::DOCUMENT_SOURCE_TYPE) {
                $row['document_id'] = $this->servableDocumentId($course, $video);
                // "Updated since you confirmed it" / "Please confirm again" (new cycle or asked).
                $row['updated'] = $documentState['updated'] ?? false;
                $row['confirm_again'] = $documentState['again'] ?? false;
            }
            $videos[] = $row;
        }
        return new DataResponse(['videos' => $videos]);
    }
}
