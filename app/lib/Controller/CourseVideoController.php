<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Db\CourseVideo;
use OCA\Learning\Db\CourseVideoMapper;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\NcFilesVideoAdapter;
use OCA\Learning\Service\VideoProgressService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * CourseVideoController — instructor registry CRUD for gated content (Phase 162, VIDEO-09).
 *
 * Registry rows drive the gate: each row is an nc-files video, a third-party embed
 * (vimeo / youtube-nocookie), or a document material. Duration is admin-entered here because
 * NC-MP4 has no reliable server-side probe and third-party durations are provider-owned.
 *
 * SECURITY:
 *   - Every mutation is owner-gated via CourseService::assertInstructorOfCourse BEFORE any DB write
 *     (an instructor of course A must not touch course B's registry).
 *   - An nc-files video is streamed from the course OWNER's Nextcloud home to every member, so only
 *     the owner may point a row at a file there, and only at a video (audit #15: a co-instructor
 *     could publish any file of the owner's home, e.g. a payslip PDF).
 *   - A gated VIDEO source (nc-files/vimeo/youtube-nocookie) MUST carry a positive duration_seconds,
 *     otherwise the 95%-coverage gate can never be satisfied and would silently stay open. We reject
 *     such saves with 400 (advisor must-have — no silently-open gate). Documents are exempt.
 *
 * The video_gate_enabled activation toggle lives in CourseController::setVideoGate (162-01), NOT here.
 * Routes are pre-registered by 162-01 (courseVideo#index/create/update/destroy).
 */
class CourseVideoController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private CourseVideoMapper $videoMapper,
        private CourseService $courseService,
        private VideoProgressService $videoProgressService,
        private IRootFolder $rootFolder,
        private LoggerInterface $logger,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * List the gated-content registry for a course (instructor-only).
     *
     * @NoAdminRequired
     */
    public function index(int $courseId): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            $this->courseService->assertInstructorOfCourse($courseId, $this->userId);
            return new DataResponse($this->videoMapper->findByCourse($courseId));
        } catch (DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (ForbiddenException $e) {
            return new DataResponse(['error' => 'Access denied'], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            $this->logger->error('CourseVideo index failed: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Failed to list videos'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Add a registry row (instructor-only).
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function create(
        int $courseId,
        string $sourceType,
        string $videoRef,
        ?string $title = null,
        ?int $durationSeconds = null,
        ?string $subtitleRef = null,
        int $sortOrder = 0
    ): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        $durationError = $this->rejectInvalidGateDuration($sourceType, $durationSeconds);
        if ($durationError !== null) {
            return $durationError;
        }
        try {
            $course = $this->courseService->assertInstructorOfCourse($courseId, $this->userId);
            if ($sourceType === NcFilesVideoAdapter::SOURCE_TYPE) {
                $fileError = $this->rejectOwnerFile($course->getInstructorId(), $videoRef);
                if ($fileError !== null) {
                    return $fileError;
                }
            }

            $video = new CourseVideo();
            $video->setCourseId($courseId);
            $video->setSourceType($sourceType);
            $video->setVideoRef($videoRef);
            $video->setTitle($title);
            $video->setDurationSeconds($durationSeconds);
            $video->setSubtitleRef($subtitleRef);
            $video->setSortOrder($sortOrder);
            $video->setCreatedAt(time());

            return new DataResponse($this->videoMapper->insert($video), Http::STATUS_CREATED);
        } catch (DoesNotExistException $e) {
            return new DataResponse(['error' => 'Course not found'], Http::STATUS_NOT_FOUND);
        } catch (ForbiddenException $e) {
            return new DataResponse(['error' => 'Access denied'], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            $this->logger->error('CourseVideo create failed: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Failed to create video'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * Update a registry row (instructor-only). Only provided fields are changed.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function update(
        int $id,
        ?string $sourceType = null,
        ?string $videoRef = null,
        ?string $title = null,
        ?int $durationSeconds = null,
        ?string $subtitleRef = null,
        ?int $sortOrder = null
    ): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            $video = $this->videoMapper->findById($id);
            // Owner gate: resolve the course from the registry row (never a client courseId).
            $course = $this->courseService->assertInstructorOfCourse($video->getCourseId(), $this->userId);

            $effectiveSourceType = $sourceType ?? $video->getSourceType();
            // duration_seconds semantics: null = "leave unchanged"; validate the RESULTING state.
            $effectiveDuration = $durationSeconds ?? $video->getDurationSeconds();
            $durationError = $this->rejectInvalidGateDuration($effectiveSourceType, $effectiveDuration);
            if ($durationError !== null) {
                return $durationError;
            }
            // Which file the row streams changes when the ref changes or a row becomes nc-files.
            $pointsAtNewFile = $videoRef !== null || $effectiveSourceType !== $video->getSourceType();
            if ($effectiveSourceType === NcFilesVideoAdapter::SOURCE_TYPE && $pointsAtNewFile) {
                $fileError = $this->rejectOwnerFile($course->getInstructorId(), $videoRef ?? $video->getVideoRef());
                if ($fileError !== null) {
                    return $fileError;
                }
            }

            if ($sourceType !== null) {
                $video->setSourceType($sourceType);
            }
            if ($videoRef !== null) {
                $video->setVideoRef($videoRef);
            }
            if ($title !== null) {
                $video->setTitle($title);
            }
            if ($durationSeconds !== null) {
                $video->setDurationSeconds($durationSeconds);
            }
            if ($subtitleRef !== null) {
                $video->setSubtitleRef($subtitleRef);
            }
            if ($sortOrder !== null) {
                $video->setSortOrder($sortOrder);
            }

            return new DataResponse($this->videoMapper->update($video));
        } catch (DoesNotExistException $e) {
            return new DataResponse(['error' => 'Video not found'], Http::STATUS_NOT_FOUND);
        } catch (ForbiddenException $e) {
            return new DataResponse(['error' => 'Access denied'], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            $this->logger->error('CourseVideo update failed: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Failed to update video'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * Delete a registry row (instructor-only).
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function destroy(int $id): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            $video = $this->videoMapper->findById($id);
            $this->courseService->assertInstructorOfCourse($video->getCourseId(), $this->userId);
            $this->videoMapper->delete($video);
            return new DataResponse([], Http::STATUS_NO_CONTENT);
        } catch (DoesNotExistException $e) {
            return new DataResponse(['error' => 'Video not found'], Http::STATUS_NOT_FOUND);
        } catch (ForbiddenException $e) {
            return new DataResponse(['error' => 'Access denied'], Http::STATUS_FORBIDDEN);
        } catch (\Throwable $e) {
            $this->logger->error('CourseVideo destroy failed: ' . $e->getMessage(), ['app' => 'learning']);
            return new DataResponse(['error' => 'Failed to delete video'], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * An nc-files row streams a file of the course owner's home (VideoStreamController). Only the
     * owner may choose it, and it must be a video there: a co-instructor gets 403, a missing file
     * or any other type 400.
     *
     * @return DataResponse|null the refusal, null when acceptable
     */
    private function rejectOwnerFile(string $ownerId, string $videoRef): ?DataResponse {
        if ($ownerId !== $this->userId) {
            return new DataResponse(
                ['error' => 'Only the course owner can add a video from their files'],
                Http::STATUS_FORBIDDEN
            );
        }
        try {
            $node = $this->rootFolder->getUserFolder($ownerId)->get($videoRef);
        } catch (\OCP\Files\NotFoundException $e) {
            $node = null;
        }
        if (!($node instanceof File) || !NcFilesVideoAdapter::isVideoMime($node->getMimeType())) {
            return new DataResponse(['error' => 'The file must be a video in your files'], Http::STATUS_BAD_REQUEST);
        }
        return null;
    }

    /**
     * Reject a gated VIDEO source saved with a null/zero/negative duration — such a row can never
     * reach the 95% coverage gate and would leave it silently open. Documents (and any non-timed
     * source) are exempt. Reuses the single source of truth VideoProgressService::isDurationValidForGate.
     *
     * @return DataResponse|null 400 response when invalid, null when acceptable
     */
    private function rejectInvalidGateDuration(string $sourceType, ?int $durationSeconds): ?DataResponse {
        if ($this->videoProgressService->isDurationValidForGate($sourceType, $durationSeconds)) {
            return null;
        }
        return new DataResponse(
            ['error' => 'A gated video source requires a positive duration_seconds'],
            Http::STATUS_BAD_REQUEST
        );
    }
}
