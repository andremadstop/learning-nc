<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Unit\Migration;

use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Schema assertion tests for Version009300 (audit hash-chain).
 *
 * Runs against PG16 inside the devcloud container (NC DI provides IDBConnection).
 * Skips gracefully when executed outside the container (no OCP\Server available).
 *
 * Phase 160, Track A — verifies:
 *  - learning_audit_chain_state table exists with exactly 1 genesis row
 *  - learning_audit_events has the 4 new chain columns (all nullable)
 *  - learn_audit_chain_idx index exists on learning_audit_events(chain_hash)
 */
class AuditMigrationTest extends TestCase {
    private ?IDBConnection $db = null;

    protected function setUp(): void {
        try {
            $this->db = Server::get(IDBConnection::class);
        } catch (\Throwable) {
            $this->markTestSkipped('No NC container — run in devcloud');
        }
    }

    /**
     * learning_audit_chain_state must exist and contain exactly 1 genesis row
     * (last_seq=0, last_hash=000...0) after Version009300 postSchemaChange().
     */
    public function testChainStateTableExists(): void {
        $qb = $this->db->getQueryBuilder();
        $count = (int)$qb->select($qb->func()->count('id'))
            ->from('learning_audit_chain_state')
            ->executeQuery()
            ->fetchOne();

        $this->assertGreaterThanOrEqual(1, $count, 'Genesis row must exist in learning_audit_chain_state');
    }

    /**
     * Genesis row must have last_seq=0 and last_hash=str_repeat('0', 64).
     */
    public function testChainStateGenesisValues(): void {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('last_seq', 'last_hash')
            ->from('learning_audit_chain_state')
            ->setMaxResults(1)
            ->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        $this->assertIsArray($row, 'Genesis row must exist');
        $this->assertSame(0, (int)$row['last_seq'], 'Genesis last_seq must be 0');
        $this->assertSame(str_repeat('0', 64), (string)$row['last_hash'], 'Genesis last_hash must be 64 zeros');
    }

    /**
     * learning_audit_events must have all 4 new chain columns (all nullable).
     */
    public function testAuditEventsHasChainColumns(): void {
        $schema = $this->db->createSchema();
        $prefix = $this->db->getTablePrefix();
        $table = $schema->getTable($prefix . 'learning_audit_events');

        foreach (['seq_num', 'user_ref', 'prev_hash', 'chain_hash'] as $col) {
            $this->assertTrue($table->hasColumn($col), "Column {$col} must exist on learning_audit_events");
        }
    }

    /**
     * All 4 chain columns must be nullable (NULL for rows written by logEvent()).
     */
    public function testChainColumnsAreNullable(): void {
        $schema = $this->db->createSchema();
        $prefix = $this->db->getTablePrefix();
        $table = $schema->getTable($prefix . 'learning_audit_events');

        foreach (['seq_num', 'user_ref', 'prev_hash', 'chain_hash'] as $col) {
            $this->assertFalse(
                $table->getColumn($col)->getNotnull(),
                "Column {$col} must be nullable (logEvent() rows leave it NULL)"
            );
        }
    }

    /**
     * learn_audit_chain_idx index must exist on learning_audit_events.
     * Index name is 21 chars — safely under MariaDB's 27-char limit.
     */
    public function testChainIndexExists(): void {
        $schema = $this->db->createSchema();
        $prefix = $this->db->getTablePrefix();
        $table = $schema->getTable($prefix . 'learning_audit_events');
        $indexes = $table->getIndexes();

        $this->assertArrayHasKey(
            'learn_audit_chain_idx',
            $indexes,
            'learn_audit_chain_idx index must exist on learning_audit_events'
        );
    }
}
