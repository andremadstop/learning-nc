<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\QuestionController;
use OCA\Learning\Controller\ShareController;
use OCA\Learning\Service\QuestionService;
use OCA\Learning\Service\ShareService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Audit #64, #66: a pool the caller cannot read (deleted, unshared, foreign) answered 500 with a
 * logged stack trace on two endpoints, while their sibling actions answer 404.
 */
#[AllowMockObjectsWithoutExpectations]
class PoolAccessNotFoundTest extends TestCase {

    public function testQuestionIndexWithoutPoolAccessIs404(): void {
        $service = $this->createMock(QuestionService::class);
        $service->method('findByPool')->willThrowException(new \Exception('Pool not found or no access'));
        $controller = new QuestionController('learning', $this->createMock(IRequest::class), $service, 'mallory');

        $response = $controller->index(99);

        self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        self::assertSame(['error' => 'Pool not found or no access'], $response->getData());
    }

    public function testPagedQuestionIndexWithoutPoolAccessIs404(): void {
        $service = $this->createMock(QuestionService::class);
        $service->method('findByPoolPaged')->willThrowException(new \Exception('Pool not found or no access'));
        $controller = new QuestionController('learning', $this->createMock(IRequest::class), $service, 'mallory');

        $response = $controller->index(99, 50, 0);

        self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }

    public function testQuestionIndexKeepsADatabaseFailureA500(): void {
        $service = $this->createMock(QuestionService::class);
        $service->method('findByPool')->willThrowException(new \OCP\DB\Exception(0, 'connection lost'));
        $controller = new QuestionController('learning', $this->createMock(IRequest::class), $service, 'alice');

        $this->expectException(\OCP\DB\Exception::class);
        $controller->index(42);
    }

    public function testShareUpdateOnAForeignPoolIs404(): void {
        $service = $this->createMock(ShareService::class);
        $service->method('updatePermission')->willThrowException(new DoesNotExistException('not the owner'));
        $controller = new ShareController('learning', $this->createMock(IRequest::class), $service, 'mallory');

        $response = $controller->update(99, 'bob', 'edit');

        self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        self::assertSame(['error' => 'Share not found or no access'], $response->getData());
    }
}
