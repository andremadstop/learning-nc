<?php
declare(strict_types=1);
namespace OCA\Learning\Tests\Unit\Controller;

use OCA\Learning\Controller\InstructorAccessController;
use OCA\Learning\Controller\SettingsController;
use OCA\Learning\Db\OversightMapper;
use OCA\Learning\Service\AuditCheckpointService;
use OCA\Learning\Service\KeyService;
use OCA\Learning\Service\RoleService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

/**
 * GitHub #60: the admin endpoints for instructor groups and people.
 *
 * Admin-only by route (no @NoAdminRequired) and again in every method; the unit tests do not run
 * the middleware, so the method check is what is pinned here. Search uses the group and user
 * managers (an admin must see every group, whatever the sharing restrictions say).
 */
#[AllowMockObjectsWithoutExpectations]
class InstructorAccessControllerTest extends TestCase {

    /** @var array<string, string> */
    private array $appValues = [];

    private IConfig $config;

    protected function setUp(): void {
        $this->appValues = [];
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, $default = '') => $this->appValues[$key] ?? $default
        );
        $config->method('setAppValue')->willReturnCallback(
            function (string $app, string $key, string $value): void {
                $this->appValues[$key] = $value;
            }
        );
        $this->config = $config;
    }

    private function group(string $gid, string $name): IGroup {
        $group = $this->createMock(IGroup::class);
        $group->method('getGID')->willReturn($gid);
        $group->method('getDisplayName')->willReturn($name);
        return $group;
    }

    private function user(string $uid, string $name): IUser {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $user->method('getDisplayName')->willReturn($name);
        return $user;
    }

    private function controller(?string $actor, ?IGroupManager $groups = null, ?IUserManager $users = null): InstructorAccessController {
        if ($groups === null) {
            $groups = $this->createMock(IGroupManager::class);
            $groups->method('isAdmin')->willReturnCallback(fn ($uid) => $uid === 'root');
            $groups->method('get')->willReturnCallback(
                fn (string $gid) => $gid === 'training' ? $this->group('training', 'Training Department') : null
            );
        }
        if ($users === null) {
            $users = $this->createMock(IUserManager::class);
            $users->method('get')->willReturnCallback(
                fn (string $uid) => $uid === 'carla' ? $this->user('carla', 'Carla Klein') : null
            );
        }
        $roles = new RoleService($groups, $this->config, $this->createMock(IDBConnection::class), 'learning', $this->createMock(OversightMapper::class));
        return new InstructorAccessController('learning', $this->createMock(IRequest::class), $roles, $groups, $users, $actor);
    }

    public function testNonAdminsGetNeitherTheListNorTheSearchNorTheSave(): void {
        $this->appValues[RoleService::GROUPS_KEY] = '["training"]';
        foreach (['anna', null, ''] as $actor) {
            $controller = $this->controller($actor);
            $this->assertSame(403, $controller->get()->getStatus());
            $this->assertSame(403, $controller->search('a')->getStatus());
            $this->assertSame(403, $controller->save(['hr'], ['anna'])->getStatus());
        }
        $this->assertSame('["training"]', $this->appValues[RoleService::GROUPS_KEY], 'a refused save writes nothing');
        $this->assertArrayNotHasKey(RoleService::USERS_KEY, $this->appValues);
    }

    public function testGetShowsTheLegacyGroupBeforeTheFirstSave(): void {
        $this->appValues[RoleService::LEGACY_GROUP_KEY] = 'training';
        $data = $this->controller('root')->get()->getData();
        $this->assertSame([['id' => 'training', 'label' => 'Training Department']], $data['groups']);
        $this->assertSame([], $data['users']);
    }

    public function testUnresolvableIdsAreShownAsTheyAreSoTheAdminCanRemoveThem(): void {
        $this->appValues[RoleService::GROUPS_KEY] = '["training","ldap-gone"]';
        $this->appValues[RoleService::USERS_KEY] = '["carla","deleted-user"]';
        $data = $this->controller('root')->get()->getData();
        $this->assertSame([
            ['id' => 'training', 'label' => 'Training Department'],
            ['id' => 'ldap-gone', 'label' => 'ldap-gone'],
        ], $data['groups']);
        $this->assertSame([
            ['id' => 'carla', 'label' => 'Carla Klein'],
            ['id' => 'deleted-user', 'label' => 'deleted-user'],
        ], $data['users']);
    }

    public function testSaveStoresGroupsAndPeopleAndTheyGrantInstructorRights(): void {
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isAdmin')->willReturnCallback(fn ($uid) => $uid === 'root');
        $groups->method('get')->willReturn(null);
        $groups->method('isInGroup')->willReturnCallback(fn ($uid, $gid) => $uid === 'ben' && $gid === 'hr');

        $data = $this->controller('root', $groups)->save(['training', 'hr'], ['carla'])->getData();
        $this->assertSame(['training', 'hr'], array_column($data['groups'], 'id'));
        $this->assertSame(['carla'], array_column($data['users'], 'id'));

        $roles = new RoleService($groups, $this->config, $this->createMock(IDBConnection::class), 'learning', $this->createMock(OversightMapper::class));
        $this->assertTrue($roles->isInstructor('ben'), 'second group');
        $this->assertTrue($roles->isInstructor('carla'), 'listed person');
        $this->assertFalse($roles->isInstructor('anna'));
    }

    public function testSearchOffersGroupsAndAccountsFromTheManagers(): void {
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isAdmin')->willReturn(true);
        $groups->expects($this->once())->method('search')->with('tra', 20)
            ->willReturn([$this->group('training', 'Training Department'), $this->group('trainees', '')]);
        $users = $this->createMock(IUserManager::class);
        $users->expects($this->once())->method('searchDisplayName')->with('tra', 20)
            ->willReturn([$this->user('travis', 'Travis Lee')]);

        $data = $this->controller('root', $groups, $users)->search('  tra ')->getData();
        $this->assertSame([
            ['id' => 'training', 'label' => 'Training Department'],
            ['id' => 'trainees', 'label' => 'trainees'],
        ], $data['groups']);
        $this->assertSame([['id' => 'travis', 'label' => 'Travis Lee']], $data['users']);
    }

    public function testAnEmptySearchAsksNobody(): void {
        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isAdmin')->willReturn(true);
        $groups->expects($this->never())->method('search');
        $users = $this->createMock(IUserManager::class);
        $users->expects($this->never())->method('searchDisplayName');

        $this->assertSame(['users' => [], 'groups' => []], $this->controller('root', $groups, $users)->search('   ')->getData());
    }

    /**
     * The admin page no longer sends the single field. saveAdmin() used to default it to
     * 'learning-instructors' and would then have overwritten the 5.11 group on every save.
     */
    public function testSaveAdminWithoutTheOldFieldLeavesTheInstructorsAlone(): void {
        $this->appValues[RoleService::LEGACY_GROUP_KEY] = 'training';
        $store = $this->createMock(\OCA\Learning\Service\Llm\ConnectionStore::class);
        $settings = new SettingsController(
            'learning',
            $this->createMock(IRequest::class),
            $this->config,
            $this->createMock(IDBConnection::class),
            $this->createMock(KeyService::class),
            $this->createMock(AuditCheckpointService::class),
            $store,
            $this->createMock(\OCA\Learning\Service\Llm\ApiKeyStore::class),
            $this->roles(),
            'root'
        );
        $settings->saveAdmin('yes', 'de', 2, 'yes');
        $this->assertSame('training', $this->appValues[RoleService::LEGACY_GROUP_KEY]);
    }

    private function roles(): RoleService {
        return new RoleService($this->createMock(IGroupManager::class), $this->config, $this->createMock(IDBConnection::class), 'learning', $this->createMock(OversightMapper::class));
    }

    private function settings(): SettingsController {
        return new SettingsController(
            'learning',
            $this->createMock(IRequest::class),
            $this->config,
            $this->createMock(IDBConnection::class),
            $this->createMock(KeyService::class),
            $this->createMock(AuditCheckpointService::class),
            $this->createMock(\OCA\Learning\Service\Llm\ConnectionStore::class),
            $this->createMock(\OCA\Learning\Service\Llm\ApiKeyStore::class),
            $this->roles(),
            'root'
        );
    }

    /**
     * Review finding on #60: a client that still sends the 5.11 field got 200 while only the
     * legacy key changed, which RoleService no longer reads once the lists exist. The field now
     * replaces the groups (people stay) and keeps the 5.11 key equal to the first group.
     */
    public function testTheOldFieldStillTakesEffectAfterTheListsWereSaved(): void {
        $this->roles()->saveInstructors(['training', 'ops'], ['carla']);

        $this->settings()->saveAdmin('yes', 'de', 2, 'yes', 'teachers');

        $roles = $this->roles();
        $this->assertSame(['teachers'], $roles->getInstructorGroups());
        $this->assertSame(['carla'], $roles->getInstructorUsers());
        $this->assertSame('teachers', $this->appValues[RoleService::LEGACY_GROUP_KEY]);
    }
}
