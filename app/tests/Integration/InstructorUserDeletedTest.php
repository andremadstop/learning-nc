<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration;

use OCA\Learning\Listener\InstructorUserDeletedListener;
use OCA\Learning\Service\RoleService;
use OCP\IConfig;
use OCP\IUser;
use OCP\Server;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;

/**
 * Review of GitHub #60: a person named as instructor (app value instructor_users) loses the right
 * with the account. Nextcloud removes the group memberships of a deleted account, not this list,
 * so a new account reusing the uid would have been an instructor at once. The listener is
 * resolved from the real container (its dependencies); the event registration itself is the
 * one line in Application::register().
 */
final class InstructorUserDeletedTest extends TestCase {
    private const KEYS = [RoleService::GROUPS_KEY, RoleService::USERS_KEY, RoleService::LEGACY_GROUP_KEY];

    /** @var array<string, string|null> */
    private array $saved = [];

    protected function setUp(): void {
        $config = Server::get(IConfig::class);
        foreach (self::KEYS as $key) {
            $value = $config->getAppValue('learning', $key, "\0absent");
            $this->saved[$key] = $value === "\0absent" ? null : $value;
        }
    }

    protected function tearDown(): void {
        $config = Server::get(IConfig::class);
        foreach ($this->saved as $key => $value) {
            if ($value === null) {
                $config->deleteAppValue('learning', $key);
            } else {
                $config->setAppValue('learning', $key, $value);
            }
        }
    }

    public function testADeletedPersonLeavesTheInstructorListAndNoGroupGainsRights(): void {
        $roles = Server::get(RoleService::class);
        $roles->saveInstructors([], ['it-gone-mueller']);
        $this->assertTrue($roles->isInstructor('it-gone-mueller'), 'setup');

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('it-gone-mueller');
        Server::get(InstructorUserDeletedListener::class)->handle(new UserDeletedEvent($user));

        $this->assertSame([], $roles->getInstructorUsers());
        $this->assertSame([], $roles->getInstructorGroups(), 'not the default group');
        $this->assertFalse($roles->isInstructor('it-gone-mueller'));
    }
}
