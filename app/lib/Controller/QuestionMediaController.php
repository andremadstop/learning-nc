<?php
declare(strict_types=1);

namespace OCA\Learning\Controller;

use OCA\Learning\Service\ByteRange;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\NotFoundException;
use OCA\Learning\Service\QuestionMediaService;
use OCA\Learning\Service\QuestionMediaUnavailableException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;

/**
 * Media per question (GitHub #66). The rules for who gets what are QuestionMediaService's; this
 * controller only maps them to HTTP:
 * - GET /api/questions/{questionId}/media — the tracks (and for editors the cues) the player may
 *   load before answering. 404 for a question the user cannot see (its existence is not
 *   confirmed), 403 while a counting exam runs for them on the pool.
 * - GET /api/question-media/{trackId}[?expires=&grant=] — the mp3, with Range support so the
 *   player can seek (206 / 416). A dialog track needs the grant of the answer response.
 *
 * The audio route is #[NoCSRFRequired] because an <audio> element cannot send the CSRF token with
 * its (range) requests; it reads, never writes, and every byte is behind the login and the checks
 * above. The rate limit allows seeking through a handful of tracks per question.
 */
#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]
class QuestionMediaController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private QuestionMediaService $mediaService,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[UserRateLimit(limit: 300, period: 60)]
    public function index(int $questionId): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(['error' => 'Not logged in'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            return new DataResponse($this->mediaService->metadata($questionId, $this->userId));
        } catch (NotFoundException $e) {
            return new DataResponse(['error' => 'Question not found'], Http::STATUS_NOT_FOUND);
        } catch (ForbiddenException $e) {
            return new DataResponse(['error' => 'exam_running'], Http::STATUS_FORBIDDEN);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[UserRateLimit(limit: 600, period: 60)]
    public function track(int $trackId, ?string $expires = null, ?string $grant = null): Response {
        if ($this->userId === null) {
            return new Response(Http::STATUS_UNAUTHORIZED);
        }
        try {
            $opened = $this->mediaService->openTrack($trackId, $this->userId, $expires, $grant);
        } catch (NotFoundException $e) {
            return new Response(Http::STATUS_NOT_FOUND);
        } catch (ForbiddenException $e) {
            return new Response(Http::STATUS_FORBIDDEN);
        } catch (QuestionMediaUnavailableException $e) {
            $response = new Response(Http::STATUS_SERVICE_UNAVAILABLE);
            $response->addHeader('Retry-After', '5');
            return $response;
        }

        $bytes = $opened['bytes'];
        $size = strlen($bytes);
        $range = ByteRange::parse($this->request->getHeader('Range'), $size);
        if ($range === false) {
            $response = new Response(416);
            $response->addHeader('Content-Range', 'bytes */' . $size);
            $response->addHeader('Accept-Ranges', 'bytes');
            return $response;
        }
        [$start, $end, $partial] = $range;
        $body = $size === 0 ? '' : substr($bytes, $start, $end - $start + 1);

        $response = new DataDisplayResponse($body, Http::STATUS_OK, [
            'Content-Type' => 'audio/mpeg',
            'Content-Length' => (string)strlen($body),
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => 'inline',
        ]);
        if ($partial) {
            $response->setStatus(Http::STATUS_PARTIAL_CONTENT);
            $response->addHeader('Content-Range', 'bytes ' . $start . '-' . $end . '/' . $size);
        }
        return $response;
    }
}
