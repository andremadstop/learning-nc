<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Db\CourseVideoMapper;
use OCA\Learning\Service\CourseService;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\NcFilesVideoAdapter;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attributes\UserRateLimit;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * VideoStreamController — enrollment-gated Range-206 partial-content streaming of NC-Files videos
 * (Phase 162, VIDEO-01). This is the #1 grumpy-Codex IDOR / path-traversal review surface, so the
 * ENTIRE security-critical byte path lives here, in strict order:
 *
 *   1. Resolve the registry row by content_id (404 if absent). Reject non-nc-files sources here.
 *   2. Assert the CURRENT authenticated student is enrolled in the video's course
 *      (CourseService::assertEnrolledInCourse) — BEFORE a single byte is opened. A student passing
 *      another course's content_id is rejected with 403 here (the core IDOR gate).
 *   3. Resolve the file in the INSTRUCTOR's namespace (getUserFolder($instructorId)) from the
 *      REGISTRY path ($video->getVideoRef()) ONLY — never a client-supplied path/filename.
 *   4. Parse the Range header defensively: a malformed/out-of-bounds range → HTTP 416 (never a crash
 *      or a leak); a valid range → HTTP 206 with Content-Range + Accept-Ranges + Content-Length.
 *   5. Stream via a custom ICallbackResponse (the in-repo idiom; there is no OCP\StreamResponse),
 *      fseek-ing to the range start and reading a bounded chunk loop.
 *
 * @NoCSRFRequired is required because a native <video> element cannot attach a CSRF token to its
 * range requests. Auth is via @NoAdminRequired (logged-in) + the per-course enrollment assertion.
 */
class VideoStreamController extends Controller {
    private const CHUNK_SIZE = 8192;

    public function __construct(
        string $appName,
        IRequest $request,
        private CourseVideoMapper $videoMapper,
        private CourseService $courseService,
        private NcFilesVideoAdapter $ncFilesAdapter,
        private IRootFolder $rootFolder,
        private LoggerInterface $logger,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[UserRateLimit(limit: 120, period: 60)]
    public function show(int $contentId): Response {
        if ($this->userId === null) {
            return new Response(Http::STATUS_UNAUTHORIZED);
        }

        // 1. Registry lookup — the ONLY source of the file path.
        try {
            $video = $this->videoMapper->findById($contentId);
        } catch (DoesNotExistException $e) {
            return new Response(Http::STATUS_NOT_FOUND);
        }

        // This endpoint serves nc-files bytes only; embeds (vimeo/youtube) never stream through here.
        if (!$this->ncFilesAdapter->supports($video->getSourceType())) {
            return new Response(Http::STATUS_NOT_FOUND);
        }

        // 2. Enrollment gate BEFORE opening any byte (IDOR: foreign content_id → 403).
        try {
            $course = $this->courseService->assertEnrolledInCourse($video->getCourseId(), $this->userId);
        } catch (ForbiddenException $e) {
            return new Response(Http::STATUS_FORBIDDEN);
        } catch (DoesNotExistException $e) {
            return new Response(Http::STATUS_NOT_FOUND);
        }

        // 3. Resolve the file in the INSTRUCTOR namespace from the REGISTRY path only.
        $instructorId = $course->getInstructorId();
        try {
            $userFolder = $this->rootFolder->getUserFolder($instructorId);
            $node = $userFolder->get($video->getVideoRef());
        } catch (NotFoundException $e) {
            return new Response(Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            $this->logger->error('Video stream file resolve failed: ' . $e->getMessage(), ['app' => 'learning']);
            return new Response(Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        if (!($node instanceof File)) {
            return new Response(Http::STATUS_NOT_FOUND);
        }

        $size = (int)$node->getSize();
        $mime = $node->getMimeType() ?: 'application/octet-stream';

        // 4. Parse Range defensively → 416 marker, or [start, end].
        $range = $this->parseRange($this->request->getHeader('Range'), $size);
        if ($range === false) {
            $resp = new Response(416); // Range Not Satisfiable (no OCP constant in this NC version)
            $resp->addHeader('Content-Range', 'bytes */' . $size);
            $resp->addHeader('Accept-Ranges', 'bytes');
            return $resp;
        }
        [$start, $end, $partial] = $range;
        $length = $end - $start + 1;

        // 5. Open ONLY on the success path and stream the bounded window.
        try {
            $stream = $node->fopen('r');
        } catch (\Throwable $e) {
            $this->logger->error('Video stream fopen failed: ' . $e->getMessage(), ['app' => 'learning']);
            return new Response(Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        if (!is_resource($stream)) {
            return new Response(Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        $status = $partial ? Http::STATUS_PARTIAL_CONTENT : Http::STATUS_OK;
        $headers = [
            'Content-Type' => $mime,
            'Content-Length' => (string)$length,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'no-store',
        ];
        if ($partial) {
            $headers['Content-Range'] = 'bytes ' . $start . '-' . $end . '/' . $size;
        }

        return $this->createStreamResponse($stream, $start, $length, $status, $headers);
    }

    /**
     * Defensive Range parser. Returns [start, end, isPartial] for a valid request (no Range → full
     * file as a non-partial 200), or false when the range is malformed/unsatisfiable (→ 416).
     *
     * Rejects: non-`bytes=` units, multi-range, empty `-`, start>end, start<0, start>=size, and any
     * suffix/prefix that resolves to nothing. Clamps an open-ended `start-` to the last byte.
     *
     * @return array{0: int, 1: int, 2: bool}|false
     */
    private function parseRange(string $rangeHeader, int $size): array|false {
        $rangeHeader = trim($rangeHeader);
        if ($rangeHeader === '') {
            // No Range → whole file (a 0-byte file is still a valid non-partial 200).
            return [0, max(0, $size - 1), false];
        }

        // Single `bytes=start-end` only; anything else (multi-range, other units) → 416.
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', $rangeHeader, $m)) {
            return false;
        }
        $startStr = $m[1];
        $endStr = $m[2];
        if ($startStr === '' && $endStr === '') {
            return false;
        }

        if ($startStr === '') {
            // Suffix range: the last N bytes.
            $suffix = (int)$endStr;
            if ($suffix <= 0) {
                return false;
            }
            $start = max(0, $size - $suffix);
            $end = $size - 1;
        } else {
            $start = (int)$startStr;
            $end = ($endStr === '') ? $size - 1 : (int)$endStr;
        }

        // Clamp an over-long end to EOF, then hard bounds-check.
        if ($end > $size - 1) {
            $end = $size - 1;
        }
        if ($start < 0 || $start > $end || $start >= $size) {
            return false;
        }

        return [$start, $end, true];
    }

    /**
     * Build the streaming Response using the in-repo ICallbackResponse idiom (mirrors
     * SseController): fseek to the window start and echo a bounded fread loop, then fclose.
     *
     * @param resource            $stream
     * @param array<string,string> $headers
     */
    private function createStreamResponse($stream, int $start, int $length, int $status, array $headers): Response {
        $response = new class($stream, $start, $length, $headers, self::CHUNK_SIZE) extends Response implements ICallbackResponse {
            /**
             * @param resource             $stream
             * @param array<string,string> $headers
             */
            public function __construct(
                private $stream,
                private int $start,
                private int $length,
                array $headers,
                private int $chunkSize,
            ) {
                parent::__construct(Http::STATUS_OK, $headers);
            }

            public function callback(IOutput $output): void {
                @set_time_limit(0);
                ignore_user_abort(true);

                if ($this->start > 0) {
                    fseek($this->stream, $this->start);
                }

                $remaining = $this->length;
                while ($remaining > 0 && !feof($this->stream) && !connection_aborted()) {
                    $read = (int)min($this->chunkSize, $remaining);
                    $chunk = fread($this->stream, $read);
                    if ($chunk === false) {
                        break;
                    }
                    $len = strlen($chunk);
                    if ($len === 0) {
                        break;
                    }
                    $output->setOutput($chunk);
                    $remaining -= $len;
                    if (function_exists('ob_flush')) {
                        @ob_flush();
                    }
                    flush();
                }

                fclose($this->stream);
            }
        };
        // setStatus() accepts a plain int, sidestepping the parent ctor's literal-union type
        $response->setStatus($status);
        return $response;
    }
}
