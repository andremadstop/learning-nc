<?php
declare(strict_types=1);

namespace OCA\Learning\Tests\Integration\Migration;

use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Schema assertion tests for Version009300 (audit hash-chain).
 *
 * Runs in the integration suite against a real Nextcloud (scripts/nc-integration.sh), which
 * installs the app fresh: the path on which the genesis row used to be missing (5.5.3).
 *
 * Phase 160, Track A — verifies:
 *  - learning_audit_chain_state table exists with exactly 1 genesis row
 *  - learning_audit_events has the 4 new chain columns (all nullable)
 *  - learn_audit_chain_idx index exists on learning_audit_events(chain_hash)
 */
class AuditMigrationTest extends TestCase {
    private IDBConnection $db;
    private string $prefix;

    protected function setUp(): void {
        $this->db = Server::get(IDBConnection::class);
        // IDBConnection has no public prefix getter (getTablePrefix() never existed); the
        // Doctrine schema below lists tables by their full, prefixed name.
        $this->prefix = Server::get(IConfig::class)->getSystemValueString('dbtableprefix', 'oc_');
    }

    /**
     * The chain head row id=1 must exist: AuditService reads exactly that row and fails every
     * compliance event without it. On a fresh install it is seeded by the EnsureAuditChainGenesis
     * repair step, because Version009300::postSchemaChange() does not run there.
     */
    public function testChainHeadRowExists(): void {
        $row = $this->chainHead();

        $this->assertIsArray($row, 'learning_audit_chain_state must hold the chain head row id=1');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string)$row['last_hash']);
    }

    /**
     * An untouched chain starts from last_seq=0 and a hash of 64 zeros.
     */
    public function testChainHeadStartsAtGenesis(): void {
        $row = $this->chainHead();
        $this->assertIsArray($row);

        if ((int)$row['last_seq'] === 0) {
            $this->assertSame(str_repeat('0', 64), (string)$row['last_hash'], 'Genesis last_hash must be 64 zeros');
        } else {
            $this->assertNotSame(str_repeat('0', 64), (string)$row['last_hash'], 'A chain that moved on must not keep the genesis hash');
        }
    }

    /** @return array<string, mixed>|false */
    private function chainHead(): array|false {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('last_seq', 'last_hash')
            ->from('learning_audit_chain_state')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
            ->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row;
    }

    /**
     * learning_audit_events must have all 4 new chain columns (all nullable).
     */
    public function testAuditEventsHasChainColumns(): void {
        $schema = $this->db->createSchema();
        $table = $schema->getTable($this->prefix . 'learning_audit_events');

        foreach (['seq_num', 'user_ref', 'prev_hash', 'chain_hash'] as $col) {
            $this->assertTrue($table->hasColumn($col), "Column {$col} must exist on learning_audit_events");
        }
    }

    /**
     * All 4 chain columns must be nullable (NULL for rows written by logEvent()).
     */
    public function testChainColumnsAreNullable(): void {
        $schema = $this->db->createSchema();
        $table = $schema->getTable($this->prefix . 'learning_audit_events');

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
        $table = $schema->getTable($this->prefix . 'learning_audit_events');
        $indexes = $table->getIndexes();

        $this->assertArrayHasKey(
            'learn_audit_chain_idx',
            $indexes,
            'learn_audit_chain_idx index must exist on learning_audit_events'
        );
    }
}
