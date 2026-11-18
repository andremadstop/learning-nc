<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\SettingsController;
use OCA\Learning\Service\AuditCheckpointService;
use OCA\Learning\Service\KeyService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #99: "Nicht mehr anzeigen" in the dialog that explains archiving a course is a user
 * setting on the server, not a localStorage flag (which would bring the dialog back in every
 * other browser). PUT /api/settings/personal/archive-confirm stores it, GET /api/settings/personal
 * reports it.
 */
#[AllowMockObjectsWithoutExpectations]
final class ArchiveConfirmSettingTest extends TestCase {
    /** @var IConfig&\PHPUnit\Framework\MockObject\MockObject */
    private $config;

    private function controller(?string $userId = 'lena'): SettingsController {
        $this->config ??= $this->createMock(IConfig::class);
        return new SettingsController(
            'learning',
            $this->createMock(IRequest::class),
            $this->config,
            $this->createMock(IDBConnection::class),
            $this->createMock(KeyService::class),
            $this->createMock(AuditCheckpointService::class),
            $this->createMock(\OCA\Learning\Service\Llm\ConnectionStore::class),
            $this->createMock(\OCA\Learning\Service\Llm\ApiKeyStore::class),
            $this->createMock(\OCA\Learning\Service\RoleService::class),
            $userId
        );
    }

    public function testSkipIsStoredForTheUser(): void {
        $this->config = $this->createMock(IConfig::class);
        $this->config->expects($this->once())->method('setUserValue')
            ->with('lena', 'learning', 'archive_confirm_skip', 'yes');

        $response = $this->controller()->saveArchiveConfirm(true);

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(['archive_confirm_skip' => 'yes'], $response->getData());
    }

    public function testTheDialogCanBeBroughtBack(): void {
        $this->config = $this->createMock(IConfig::class);
        $this->config->expects($this->once())->method('setUserValue')
            ->with('lena', 'learning', 'archive_confirm_skip', 'no');

        $this->assertSame(['archive_confirm_skip' => 'no'], $this->controller()->saveArchiveConfirm(false)->getData());
    }

    public function testWithoutAUserNothingIsStored(): void {
        $this->config = $this->createMock(IConfig::class);
        $this->config->expects($this->never())->method('setUserValue');

        $this->assertSame(401, $this->controller(null)->saveArchiveConfirm(true)->getStatus());
    }

    public function testPersonalSettingsReportTheFlagDefaultingToTheDialog(): void {
        $this->config = $this->createMock(IConfig::class);
        $this->config->method('getUserValue')->willReturnArgument(3);
        $this->assertSame('no', $this->controller()->getPersonal()->getData()['archive_confirm_skip']);

        $this->config = $this->createMock(IConfig::class);
        $this->config->method('getUserValue')->willReturnCallback(
            static fn (string $uid, string $app, string $key, $default = '') => $key === 'archive_confirm_skip' ? 'yes' : $default
        );
        $this->assertSame('yes', $this->controller()->getPersonal()->getData()['archive_confirm_skip']);
    }
}
