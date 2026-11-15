<?php
declare(strict_types=1);
namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\OversightMapper;
use OCA\Learning\Service\RoleService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

/**
 * GitHub #60: several instructor groups and individual people instead of one group.
 *
 * isInstructor() is the one gate behind course and pool creation and every global instructor
 * right, so each way in and each way of NOT getting in is pinned here: admin, any listed group,
 * a listed person, the untouched 5.11 single group, a damaged stored value, and the optional
 * course fallback, which must keep its place after the lists.
 */
#[AllowMockObjectsWithoutExpectations]
class RoleServiceInstructorListsTest extends TestCase {

    /** @var array<string, string> */
    private array $appValues = [];

    /** @var array<string, list<string>> user => groups */
    private array $memberships = [];

    /** @var list<string> */
    private array $admins = [];

    private bool $dbQueried = false;

    private function service(): RoleService {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, $default = '') => $this->appValues[$key] ?? $default
        );
        $config->method('setAppValue')->willReturnCallback(
            function (string $app, string $key, string $value): void {
                $this->appValues[$key] = $value;
            }
        );

        $groups = $this->createMock(IGroupManager::class);
        $groups->method('isAdmin')->willReturnCallback(fn ($uid) => in_array($uid, $this->admins, true));
        $groups->method('isInGroup')->willReturnCallback(
            fn ($uid, $gid) => in_array($gid, $this->memberships[$uid] ?? [], true)
        );

        // The course fallback queries the database; a query here means the fallback was reached.
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnCallback(function () {
            $this->dbQueried = true;
            throw new \RuntimeException('no database in this test');
        });

        return new RoleService($groups, $config, $db, 'learning', $this->createMock(OversightMapper::class));
    }

    protected function setUp(): void {
        $this->appValues = [];
        $this->memberships = [
            'anna' => ['training'],
            'ben' => ['hr'],
            'carla' => ['sales'],
            'dora' => ['learning-instructors'],
        ];
        $this->admins = ['root'];
        $this->dbQueried = false;
    }

    public function testAnAdminIsAlwaysAnInstructor(): void {
        $this->appValues[RoleService::GROUPS_KEY] = '[]';
        $this->appValues[RoleService::USERS_KEY] = '[]';
        $this->assertTrue($this->service()->isInstructor('root'));
    }

    public function testMembersOfEveryListedGroupAreInstructors(): void {
        $this->appValues[RoleService::GROUPS_KEY] = '["training","hr"]';
        $service = $this->service();
        $this->assertTrue($service->isInstructor('anna'));
        $this->assertTrue($service->isInstructor('ben'), 'the second group must count, not only the first');
        $this->assertFalse($service->isInstructor('carla'));
    }

    public function testAListedPersonIsAnInstructorWithoutAnyGroup(): void {
        $this->appValues[RoleService::GROUPS_KEY] = '["training"]';
        $this->appValues[RoleService::USERS_KEY] = '["carla"]';
        $service = $this->service();
        $this->assertTrue($service->isInstructor('carla'));
        $this->assertFalse($service->isInstructor('ben'));
    }

    public function testThePersonListComparesTheExactUid(): void {
        $this->appValues[RoleService::GROUPS_KEY] = '[]';
        $this->appValues[RoleService::USERS_KEY] = '["carla"]';
        $service = $this->service();
        $this->assertFalse($service->isInstructor('Carla'));
        $this->assertFalse($service->isInstructor('carla '));
        $this->assertFalse($service->isInstructor(''));
    }

    public function testWithoutTheNewListsTheSingle511GroupStillApplies(): void {
        $this->appValues[RoleService::LEGACY_GROUP_KEY] = 'training';
        $service = $this->service();
        $this->assertSame(['training'], $service->getInstructorGroups());
        $this->assertSame([], $service->getInstructorUsers());
        $this->assertSame('training', $service->getInstructorGroup());
        $this->assertTrue($service->isInstructor('anna'));
        $this->assertFalse($service->isInstructor('dora'));
    }

    public function testWithNothingStoredTheDocumentedDefaultGroupApplies(): void {
        $service = $this->service();
        $this->assertSame(['learning-instructors'], $service->getInstructorGroups());
        $this->assertTrue($service->isInstructor('dora'));
    }

    public function testAStoredListWinsOverTheLegacyValue(): void {
        $this->appValues[RoleService::LEGACY_GROUP_KEY] = 'learning-instructors';
        $this->appValues[RoleService::GROUPS_KEY] = '["training"]';
        $service = $this->service();
        $this->assertTrue($service->isInstructor('anna'));
        $this->assertFalse($service->isInstructor('dora'));
    }

    public function testAStoredEmptyGroupListIsNotReplacedByTheLegacyGroup(): void {
        // only people configured: the old group must not come back through the fallback read
        $this->appValues[RoleService::LEGACY_GROUP_KEY] = 'learning-instructors';
        $this->appValues[RoleService::GROUPS_KEY] = '[]';
        $this->appValues[RoleService::USERS_KEY] = '["carla"]';
        $service = $this->service();
        $this->assertSame([], $service->getInstructorGroups());
        $this->assertSame('', $service->getInstructorGroup());
        $this->assertFalse($service->isInstructor('dora'));
        $this->assertTrue($service->isInstructor('carla'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function damagedValues(): array {
        return [
            'not json' => ['training,hr'],
            'an object' => ['{"a":"training"}'],
            'a string' => ['"training"'],
            'mixed types' => ['["training", 5]'],
            'null' => ['null'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('damagedValues')]
    public function testADamagedGroupValueFallsBackToTheLegacyGroupNeverToEveryoneOrNobody(string $raw): void {
        $this->appValues[RoleService::LEGACY_GROUP_KEY] = 'training';
        $this->appValues[RoleService::GROUPS_KEY] = $raw;
        $service = $this->service();
        $this->assertSame(['training'], $service->getInstructorGroups());
        $this->assertTrue($service->isInstructor('anna'));
        $this->assertFalse($service->isInstructor('ben'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('damagedValues')]
    public function testADamagedPersonValueGrantsNobody(string $raw): void {
        $this->appValues[RoleService::GROUPS_KEY] = '[]';
        $this->appValues[RoleService::USERS_KEY] = $raw;
        $service = $this->service();
        $this->assertSame([], $service->getInstructorUsers());
        $this->assertFalse($service->isInstructor('carla'));
    }

    public function testTheCourseFallbackStaysOffByDefaultAndComesAfterTheLists(): void {
        $this->appValues[RoleService::GROUPS_KEY] = '["training"]';
        $this->appValues[RoleService::USERS_KEY] = '["carla"]';
        $service = $this->service();
        $this->assertFalse($service->isInstructor('ben'));
        $this->assertFalse($this->dbQueried, 'fallback off: no course lookup');

        $this->appValues['allow_course_instructor_fallback'] = 'yes';
        $this->assertTrue($service->isInstructor('carla'));
        $this->assertFalse($this->dbQueried, 'a listed person needs no course lookup');
        $this->assertFalse($service->isInstructor('ben'), 'a failing lookup grants nothing');
        $this->assertTrue($this->dbQueried, 'fallback on: reached after the lists');
    }

    public function testSavingStoresBothListsAndTheFirstGroupForA511Downgrade(): void {
        $saved = $this->service()->saveInstructors([' training ', 'hr', 'training', '', 7, str_repeat('x', 65)], ['carla', 'carla']);
        $this->assertSame(['groups' => ['training', 'hr'], 'users' => ['carla']], $saved);
        $this->assertSame('["training","hr"]', $this->appValues[RoleService::GROUPS_KEY]);
        $this->assertSame('["carla"]', $this->appValues[RoleService::USERS_KEY]);
        $this->assertSame('training', $this->appValues[RoleService::LEGACY_GROUP_KEY]);
    }

    public function testSavingOnlyPeopleLeavesTheDowngradeGroupEmptyFailClosed(): void {
        $this->appValues[RoleService::LEGACY_GROUP_KEY] = 'learning-instructors';
        $this->service()->saveInstructors([], ['carla']);
        $this->assertSame('', $this->appValues[RoleService::LEGACY_GROUP_KEY]);
        $this->assertSame('[]', $this->appValues[RoleService::GROUPS_KEY]);
    }

    public function testSavingNothingKeepsTheDefaultGroupLikeTheSingleFieldDid(): void {
        $saved = $this->service()->saveInstructors([], []);
        $this->assertSame(['learning-instructors'], $saved['groups']);
        $this->assertTrue($this->service()->isInstructor('dora'));
    }

    public function testTheListsAreCapped(): void {
        $many = array_map(fn ($i) => 'g' . $i, range(1, RoleService::MAX_ENTRIES + 50));
        $saved = $this->service()->saveInstructors($many, []);
        $this->assertCount(RoleService::MAX_ENTRIES, $saved['groups']);
    }
}
