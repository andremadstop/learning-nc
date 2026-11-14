<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\GameshowController;
use OCA\Learning\Service\GameshowService;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Audit #227: the board-game modes were removed from the service, but the controller still listed
 * 'lernwuerfel' and 'wissensturm' as valid and named them in its error text.
 */
#[AllowMockObjectsWithoutExpectations]
final class GameshowControllerCreateTest extends TestCase {

    /** @return array<string, array{string}> */
    public static function removedModes(): array {
        return [
            'lernwuerfel' => ['lernwuerfel'],
            'wissensturm' => ['wissensturm'],
            'unknown' => ['bingo'],
        ];
    }

    #[DataProvider('removedModes')]
    public function testARemovedModeIsRefusedBeforeTheService(string $mode): void {
        $service = $this->createMock(GameshowService::class);
        $service->expects($this->never())->method('createSession');

        $resp = $this->controller($service)->create(7, $mode);

        $this->assertSame(400, $resp->getStatus());
        $this->assertSame(['error' => 'Invalid mode: must be sprint or elimination'], $resp->getData());
    }

    public function testSprintIsStillCreated(): void {
        $service = $this->createMock(GameshowService::class);
        $service->expects($this->once())->method('createSession')
            ->with(7, 'alice', 'sprint', 5, null)
            ->willReturn(['code' => 'abc123']);

        $resp = $this->controller($service)->create(7, 'sprint');

        $this->assertSame(201, $resp->getStatus());
        $this->assertSame(['code' => 'abc123'], $resp->getData());
    }

    private function controller(GameshowService $service): GameshowController {
        return new GameshowController(
            'learning',
            $this->createMock(IRequest::class),
            $service,
            'alice',
            $this->createMock(LoggerInterface::class)
        );
    }
}
