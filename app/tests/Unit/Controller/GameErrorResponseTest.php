<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\GameErrorResponse;
use OCA\Learning\Controller\LeagueController;
use OCA\Learning\Service\LeagueService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Audit #141: league/duel/gameshow/coop answered every exception with a 400 and its raw message,
 * so a database error showed SQL text in the browser and never reached the log.
 */
#[AllowMockObjectsWithoutExpectations]
final class GameErrorResponseTest extends TestCase {

    public function testAUserErrorKeepsItsMessage(): void {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');

        $resp = GameErrorResponse::fromThrowable(new \RuntimeException('Session is not active'), $logger, 'Failed', 'answer');

        $this->assertSame(400, $resp->getStatus());
        $this->assertSame(['error' => 'Session is not active'], $resp->getData());
    }

    public function testAnEmptyMessageUsesTheFallback(): void {
        $resp = GameErrorResponse::fromThrowable(new \RuntimeException(''), $this->createMock(LoggerInterface::class), 'Failed to join duel', 'join');
        $this->assertSame(['error' => 'Failed to join duel'], $resp->getData());
    }

    public function testADatabaseErrorIsLoggedAndHidden(): void {
        $e = new \OCP\DB\Exception(0, 'SQLSTATE[42703]: column "foo" does not exist');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with($this->anything(), $this->callback(fn (array $c): bool => $c['exception'] === $e && $c['action'] === 'create'));

        $resp = GameErrorResponse::fromThrowable($e, $logger, 'Failed', 'create');

        $this->assertSame(500, $resp->getStatus());
        $this->assertSame(['error' => 'Internal error'], $resp->getData());
    }

    public function testAPhpErrorIsLoggedAndHidden(): void {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $resp = GameErrorResponse::fromThrowable(new \TypeError('Argument #1 must be of type int'), $logger, 'Failed', 'state');

        $this->assertSame(500, $resp->getStatus());
        $this->assertSame(['error' => 'Internal error'], $resp->getData());
    }

    public function testAMissingRowIsANotFoundWithoutTheQuery(): void {
        $resp = GameErrorResponse::fromThrowable(
            new DoesNotExistException('Did expect one result but found none when executing: query "SELECT * FROM oc_learning_duel_sessions"'),
            $this->createMock(LoggerInterface::class), 'Failed', 'state'
        );

        $this->assertSame(404, $resp->getStatus());
        $this->assertSame(['error' => 'Not found'], $resp->getData());
    }

    public function testLeagueControllerRoutesADatabaseErrorThroughTheLog(): void {
        $service = $this->createMock(LeagueService::class);
        $service->method('getOverview')->willThrowException(new \OCP\DB\Exception(0, 'SQLSTATE[23505]: duplicate key'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $controller = new LeagueController('learning', $this->createMock(IRequest::class), $service, 'alice', $logger);
        $resp = $controller->active(7);

        $this->assertSame(500, $resp->getStatus());
        $this->assertStringNotContainsString('SQLSTATE', json_encode($resp->getData()));
    }
}
