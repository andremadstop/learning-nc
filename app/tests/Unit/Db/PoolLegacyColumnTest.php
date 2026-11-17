<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Db;

use OCA\Learning\Db\Pool;
use PHPUnit\Framework\TestCase;

/**
 * Installs that started on 1.x/2.x still carry learning_pools.visibility. PoolMapper reads with
 * SELECT *, so the entity must accept that column, or every full pool read throws
 * "visibility is not a valid attribute" (5.11.0–5.14.0 on such installs).
 */
class PoolLegacyColumnTest extends TestCase {
    public function testARowWithTheLegacyVisibilityColumnMapsToAPool(): void {
        $pool = Pool::fromRow([
            'id' => 173,
            'user_id' => 'andre',
            'name' => 'Legacy pool',
            'description' => null,
            'created_at' => 1700000000,
            'updated_at' => 1700000000,
            'review_status' => null,
            'reviewer_id' => null,
            'reviewed_at' => null,
            'handbook_key' => null,
            'handbook_title' => null,
            'chapter_key' => null,
            'chapter_title' => null,
            'chapter_order' => null,
            'visibility' => 'shared',
        ]);
        $this->assertSame(173, $pool->getId());
        $this->assertSame('Legacy pool', $pool->getName());
    }

    public function testTheLegacyColumnIsNeitherSerializedNorWritten(): void {
        $pool = Pool::fromRow(['id' => 1, 'name' => 'x', 'visibility' => 'private']);
        $this->assertArrayNotHasKey('visibility', $pool->jsonSerialize());
        $this->assertArrayNotHasKey('visibility', $pool->getUpdatedFields());
    }
}
