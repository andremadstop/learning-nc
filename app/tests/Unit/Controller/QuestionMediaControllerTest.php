<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\QuestionMediaController;
use OCA\Learning\Db\QuestionTrack;
use OCA\Learning\Service\ForbiddenException;
use OCA\Learning\Service\NotFoundException;
use OCA\Learning\Service\QuestionMediaService;
use OCA\Learning\Service\QuestionMediaUnavailableException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #66: the HTTP side of the question media — 404 for what the user cannot see, 403 for a
 * dialog without grant / a running exam, 206 with Content-Range for seeking, 416 for a range
 * outside the file. The access rules themselves: tests/Integration/QuestionMediaTest.
 */
#[AllowMockObjectsWithoutExpectations]
final class QuestionMediaControllerTest extends TestCase {
    private const BYTES = '0123456789abcdefghij';

    private function controller(string $range, ?\Throwable $failure = null, ?string $user = 'student', ?array &$seen = null): QuestionMediaController {
        $service = $this->createMock(QuestionMediaService::class);
        $service->method('openTrack')->willReturnCallback(function (int $trackId, string $userId, ?string $expires, ?string $grant) use ($failure, &$seen) {
            $seen = [$trackId, $userId, $expires, $grant];
            if ($failure !== null) {
                throw $failure;
            }
            return ['track' => new QuestionTrack(), 'bytes' => self::BYTES];
        });
        $service->method('metadata')->willReturnCallback(function () use ($failure) {
            if ($failure !== null) {
                throw $failure;
            }
            return ['question_id' => 1, 'tracks' => []];
        });
        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->willReturnCallback(fn (string $name) => $name === 'Range' ? $range : '');
        return new QuestionMediaController('learning', $request, $service, $user);
    }

    public function testWholeFile(): void {
        $response = $this->controller('')->track(7, '123', 'abc');
        $this->assertInstanceOf(DataDisplayResponse::class, $response);
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(self::BYTES, $response->render());
        $headers = $response->getHeaders();
        $this->assertSame('audio/mpeg', $headers['Content-Type']);
        $this->assertSame('20', $headers['Content-Length']);
        $this->assertSame('bytes', $headers['Accept-Ranges']);
        $this->assertArrayNotHasKey('Content-Range', $headers);
    }

    public function testTheGrantAndTheLoggedInUserReachTheService(): void {
        $seen = null;
        $this->controller('', null, 'student', $seen)->track(7, '123', 'abc');
        $this->assertSame([7, 'student', '123', 'abc'], $seen);
    }

    public function testRangeForSeeking(): void {
        $response = $this->controller('bytes=5-9')->track(7);
        $this->assertSame(Http::STATUS_PARTIAL_CONTENT, $response->getStatus());
        $this->assertSame('56789', $response->render());
        $this->assertSame('bytes 5-9/20', $response->getHeaders()['Content-Range']);
        $this->assertSame('5', $response->getHeaders()['Content-Length']);
    }

    public function testUnsatisfiableRange(): void {
        $response = $this->controller('bytes=20-')->track(7);
        $this->assertSame(416, $response->getStatus());
        $this->assertSame('bytes */20', $response->getHeaders()['Content-Range']);
    }

    public function testNotVisibleIs404AndNotAllowedIs403(): void {
        $this->assertSame(Http::STATUS_NOT_FOUND, $this->controller('', new NotFoundException('x'))->track(7)->getStatus());
        $this->assertSame(Http::STATUS_FORBIDDEN, $this->controller('', new ForbiddenException('x'))->track(7)->getStatus());
        $this->assertSame(Http::STATUS_NOT_FOUND, $this->controller('', new NotFoundException('x'))->index(1)->getStatus());
        $this->assertSame(Http::STATUS_FORBIDDEN, $this->controller('', new ForbiddenException('x'))->index(1)->getStatus());
        $this->assertSame(Http::STATUS_OK, $this->controller('')->index(1)->getStatus());
    }

    public function testAnUnreadableFileIs503WithRetryAfter(): void {
        $response = $this->controller('', new QuestionMediaUnavailableException('x'))->track(7);
        $this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
        $this->assertSame('5', $response->getHeaders()['Retry-After'] ?? null);
    }

    public function testNotLoggedIn(): void {
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller('', null, null)->track(7)->getStatus());
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller('', null, null)->index(1)->getStatus());
    }
}
