<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Service;

use OCA\Learning\Db\Pool;
use OCA\Learning\Db\PoolMapper;
use OCA\Learning\Db\PoolShare;
use OCA\Learning\Db\PoolShareMapper;
use OCA\Learning\Service\BadgeService;
use OCA\Learning\Service\ShareService;
use OCP\ICacheFactory;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * GitHub #35 (same defect as course enrolment): a pool share typed as "Bob" for the account "bob"
 * passed the case-insensitive existence check and was stored as typed, so the share lookups,
 * which compare exactly, never found it for bob.
 */
#[AllowMockObjectsWithoutExpectations]
class ShareServiceTest extends TestCase {
    /** @var list<PoolShare> */
    private array $inserted = [];

    private function service(): ShareService {
        $shares = $this->createMock(PoolShareMapper::class);
        $shares->method('findByPoolAndUser')->willReturn(null);
        $shares->method('insert')->willReturnCallback(function (PoolShare $s) {
            $this->inserted[] = $s;
            return $s;
        });

        $pools = $this->createMock(PoolMapper::class);
        $pools->method('find')->willReturn(new Pool());

        $users = $this->createMock(IUserManager::class);
        $users->method('get')->willReturnCallback(function (string $typed) {
            $canonical = ['bob' => 'bob', 'owner' => 'owner'][strtolower($typed)] ?? null;
            if ($canonical === null) {
                return null;
            }
            $u = $this->createMock(IUser::class);
            $u->method('getUID')->willReturn($canonical);
            return $u;
        });

        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn(new class {
            public function remove(string $key): void {
            }
        });

        return new ShareService($shares, $pools, $users, $this->createMock(BadgeService::class), $cacheFactory);
    }

    public function testShareIsStoredUnderTheCanonicalUid(): void {
        $this->service()->sharePool(3, 'Bob', 'user', 'read', 'owner');

        $this->assertCount(1, $this->inserted);
        $this->assertSame('bob', $this->inserted[0]->getSharedWith());
    }

    public function testSharingWithYourselfInAnotherCaseIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot share with yourself');
        try {
            $this->service()->sharePool(3, 'OWNER', 'user', 'read', 'owner');
        } finally {
            $this->assertSame([], $this->inserted);
        }
    }

    public function testUnknownAccountIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('User does not exist');
        $this->service()->sharePool(3, 'ghost', 'user', 'read', 'owner');
    }
}
