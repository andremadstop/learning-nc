<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Controller\PoolController;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\PoolShareMapper;
use OCA\Learning\Service\PoolService;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use OCA\Learning\Tests\Support\FakeResult;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #69: "Pools meiner Kurse" lists the pools of the courses the user leads (owner or
 * co-instructor), each pool once with its course titles and the user's permission. The SQL rule
 * itself (who leads, no foreign course, no learner) runs against a real database in
 * tests/Integration/PoolsInMyCourses69Test.php.
 */
#[AllowMockObjectsWithoutExpectations]
class PoolsInMyCourses69Test extends TestCase {
    private function service(FakeDbConnection $db): PoolService {
        return new PoolService($this->createMock(PoolMapper::class), $this->createMock(PoolShareMapper::class), $db);
    }

    private static function pool(int $id, string $owner, int $courseId, string $courseTitle): array {
        return [
            'id' => $id, 'user_id' => $owner, 'name' => 'Pool ' . $id, 'description' => null,
            'created_at' => 1, 'updated_at' => 1,
            'lnc_course_id' => $courseId, 'lnc_course_title' => $courseTitle,
        ];
    }

    public function testEachPoolOnceWithItsCoursesAndThePermission(): void {
        $rows = new FakeQueryBuilder(new FakeResult([
            self::pool(1, 'admin', 7, 'Annual safety training'),
            self::pool(2, 'teacher', 7, 'Annual safety training'),
            self::pool(2, 'teacher', 9, 'Refresher'),
            self::pool(3, 'teacher', 9, 'Refresher'),
        ]));
        $shares = new FakeQueryBuilder(new FakeResult([['pool_id' => 3, 'permission' => 'edit']]));
        $db = new FakeDbConnection([$rows, $shares]);

        $pools = $this->service($db)->findInMyCourses('admin');

        $this->assertSame([1, 2, 3], array_column($pools, 'id'));
        $this->assertSame(['owner', 'read', 'edit'], array_column($pools, 'permission'));
        $this->assertSame([false, true, true], array_column($pools, 'is_shared'), 'App.selectPool reads a falsy is_shared as owner');
        $this->assertSame([
            ['id' => 7, 'title' => 'Annual safety training'],
            ['id' => 9, 'title' => 'Refresher'],
        ], $pools[1]['courses']);

        // The course rule: owner column or an instructor member row of the user, nothing else.
        $params = array_column($rows->namedParameters, 'value');
        $this->assertSame(['admin', 'instructor', 'admin'], $params);
        $this->assertSame([[1, 2, 3]], array_values(array_filter(array_column($shares->namedParameters, 'value'), 'is_array')));
    }

    public function testNoCourseNoShareQuery(): void {
        $db = new FakeDbConnection([new FakeQueryBuilder(new FakeResult([]))]);
        $this->assertSame([], $this->service($db)->findInMyCourses('learner'));
        $this->assertCount(1, $db->issuedBuilders);
    }

    public function testTheEndpointWrapsTheListForTheCurrentUser(): void {
        $service = $this->createMock(PoolService::class);
        $service->expects($this->once())->method('findInMyCourses')->with('admin')->willReturn([['id' => 1]]);
        $controller = new PoolController('learning', $this->createMock(IRequest::class), $service, 'admin');
        $response = $controller->inMyCourses();
        $this->assertSame(200, $response->getStatus());
        $this->assertSame(['pools' => [['id' => 1]]], $response->getData());
    }
}
