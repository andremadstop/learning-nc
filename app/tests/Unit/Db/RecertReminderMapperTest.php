<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Db;

use OCA\Learning\Db\RecertReminderMapper;
use OCA\Learning\Tests\Support\FakeDbConnection;
use OCA\Learning\Tests\Support\FakeQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * RecertReminderMapper::reclaimStale — the atomic stale-claim CAS (164-07 review pass 3).
 *
 * Two concurrent runners observing the same stale pending row must resolve to exactly ONE
 * deliverer: the UPDATE is guarded on delivered_at IS NULL AND sent_at <= staleBefore, and
 * only affected===1 wins. The loser's UPDATE matches 0 rows (the winner already bumped
 * sent_at to now) → false → skip.
 */
class RecertReminderMapperTest extends TestCase {

    public function testReclaimStaleWinner(): void {
        $qb = new FakeQueryBuilder(null, 1); // UPDATE matches the stale row
        $mapper = new RecertReminderMapper(new FakeDbConnection([$qb]));

        $this->assertTrue($mapper->reclaimStale(42, 30, 1750000000, 1750000000 - 3600));

        $this->assertSame('update', $qb->operation);
        $this->assertSame('learning_recert_reminders', $qb->table);
        $this->assertArrayHasKey('sent_at', $qb->setCalls, 'winner bumps sent_at to now (fresh claim)');
        $this->assertArrayNotHasKey('delivered_at', $qb->setCalls, 're-claim must NOT fake a delivery');
        $where = json_encode(array_merge($qb->whereCalls, $qb->andWhereCalls));
        $this->assertIsString($where);
        $this->assertStringContainsString('isNull', $where,
            'CAS must be guarded on delivered_at IS NULL (never re-claim a delivered row)');
        $this->assertStringContainsString('lte', $where,
            'CAS must be guarded on sent_at <= staleBefore (never steal a fresh claim)');
    }

    public function testReclaimStaleLoser(): void {
        // 0 affected rows: the concurrent winner already bumped sent_at → we must not deliver.
        $mapper = new RecertReminderMapper(new FakeDbConnection([new FakeQueryBuilder(null, 0)]));

        $this->assertFalse($mapper->reclaimStale(42, 30, 1750000000, 1750000000 - 3600));
    }
}
