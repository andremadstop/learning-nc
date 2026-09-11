<?php
declare(strict_types=1);
namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\SettingsController;
use OCA\Learning\Service\AuditCheckpointService;
use OCA\Learning\Service\KeyService;
use OCA\Learning\Service\RoleService;
use OCA\Learning\Db\OversightMapper;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Codeberg #6, part 1: a manager could not create courses or pools.
 *
 * The mechanism was never broken — RoleService has always read an app value named
 * `instructor_group` (default `learning-instructors`). It was simply invisible: nothing
 * in the admin page named it or let anyone change it, and the manual described the
 * instructor role as per-course only, which is wrong for creating a course at all.
 *
 * These tests cover the setting end to end, because a key the frontend sends and the
 * controller never reads answers 200 and silently does nothing — the same failure shape
 * as the Arabic content language in SettingsControllerLanguageTest.
 */
class AdminInstructorGroupTest extends TestCase {

    /** @var array<string, string> */
    private array $appValues = [];

    private function makeController(): SettingsController {
        $config = $this->createMock(IConfig::class);
        $config->method('setAppValue')->willReturnCallback(
            function (string $app, string $key, string $value): void {
                $this->appValues[$key] = $value;
            }
        );
        $config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, $default = '') => $this->appValues[$key] ?? $default
        );

        return new SettingsController(
            'learning',
            $this->createMock(IRequest::class),
            $config,
            $this->createMock(IDBConnection::class),
            $this->createMock(KeyService::class),
            $this->createMock(AuditCheckpointService::class),
            'admin'
        );
    }

    private function saveWithGroup(string $group): string {
        $this->appValues = [];
        $this->makeController()->saveAdmin('yes', 'de', 2, 'yes', $group);

        return $this->appValues['instructor_group'] ?? '__never_written__';
    }

    public function testTheGroupNameSurvivesTheRoundTrip(): void {
        $this->assertSame('teachers', $this->saveWithGroup('teachers'));
    }

    public function testGetAdminReportsTheStoredGroup(): void {
        $this->appValues = [];
        $controller = $this->makeController();
        $controller->saveAdmin('yes', 'de', 2, 'yes', 'teachers');

        $this->assertSame('teachers', $controller->getAdmin()->getData()['instructor_group']);
    }

    public function testGetAdminFallsBackToTheDocumentedDefault(): void {
        $this->appValues = [];

        $this->assertSame(
            'learning-instructors',
            $this->makeController()->getAdmin()->getData()['instructor_group']
        );
    }

    public function testAnEmptySubmissionKeepsTheDefaultInsteadOfPromotingNobody(): void {
        // An empty group name would make isInstructor() test membership in group '' —
        // which nobody is in, locking every non-admin out of course creation silently.
        $this->assertSame('learning-instructors', $this->saveWithGroup('   '));
    }

    public function testTheGroupNameIsLengthCapped(): void {
        $this->assertSame(64, mb_strlen($this->saveWithGroup(str_repeat('x', 200))));
    }

    /**
     * The setting is only worth anything if RoleService actually consults it. Guards
     * against the two halves drifting apart.
     */
    public function testRoleServiceReadsTheConfiguredGroup(): void {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturn('teachers');

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn(false);
        $groupManager->expects($this->once())
            ->method('isInGroup')
            ->with('maria', 'teachers')
            ->willReturn(true);

        $roleService = new RoleService(
            $groupManager,
            $config,
            $this->createMock(IDBConnection::class),
            'learning',
            $this->createMock(OversightMapper::class)
        );

        $this->assertSame('teachers', $roleService->getInstructorGroup());
        $this->assertTrue($roleService->isInstructor('maria'));
    }
}
