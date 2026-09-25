<?php
declare(strict_types=1);
namespace OCA\Learning\Tests\Integration\Migration;

use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

class AssignmentMigrationTest extends TestCase {
    private IDBConnection $db;
    private string $prefix;

    protected function setUp(): void {
        $this->db = Server::get(IDBConnection::class);
        // IDBConnection has no public prefix getter (getTablePrefix() never existed); the
        // Doctrine schema below lists tables by their full, prefixed name.
        $this->prefix = Server::get(IConfig::class)->getSystemValueString('dbtableprefix', 'oc_');
    }

    public function testAssignmentTableExists(): void {
        $schema = $this->db->createSchema();
        $this->assertTrue($schema->hasTable($this->prefix . 'learning_assignments'));
    }

    public function testCompositeIndexIsPlain(): void {
        $schema = $this->db->createSchema();
        $table = $schema->getTable($this->prefix . 'learning_assignments');
        $indexes = $table->getIndexes();
        $idx = $indexes['learn_asn_crs_subj_idx'] ?? null;
        $this->assertNotNull($idx, 'learn_asn_crs_subj_idx must exist');
        $this->assertFalse($idx->isUnique(), 'Composite index must be PLAIN (not unique) — re-cert rows require it');
    }

    public function testPeriodKeyIsUnique(): void {
        $schema = $this->db->createSchema();
        $table = $schema->getTable($this->prefix . 'learning_assignments');
        $idx = $table->getIndexes()['learn_asn_period_uq'] ?? null;
        $this->assertNotNull($idx, 'learn_asn_period_uq must exist');
        $this->assertTrue($idx->isUnique());
    }

    public function testNullableUniqueAllowsMultipleNulls(): void {
        // Cross-DB guard: verify multiple NULL active_period_key values coexist.
        // ANSI SQL: NULL != NULL so unique constraint allows multiple NULLs.
        // This mirrors the shipped active_idem_key pattern (Version009100) — design locked in here.
        $this->db->beginTransaction();
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('learning_assignments')
                ->values([
                    'course_id'    => $qb->createNamedParameter(9999, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                    'subject_type' => $qb->createNamedParameter('user'),
                    'subject_id'   => $qb->createNamedParameter('test_null_pk_1'),
                    'assigned_by'  => $qb->createNamedParameter('test'),
                    'assigned_at'  => $qb->createNamedParameter(time(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                    'status'       => $qb->createNamedParameter('assigned'),
                ]);
            $qb->executeStatement();

            $qb2 = $this->db->getQueryBuilder();
            $qb2->insert('learning_assignments')
                ->values([
                    'course_id'    => $qb2->createNamedParameter(9999, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                    'subject_type' => $qb2->createNamedParameter('user'),
                    'subject_id'   => $qb2->createNamedParameter('test_null_pk_2'),
                    'assigned_by'  => $qb2->createNamedParameter('test'),
                    'assigned_at'  => $qb2->createNamedParameter(time(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                    'status'       => $qb2->createNamedParameter('assigned'),
                ]);
            $qb2->executeStatement();

            $this->addToAssertionCount(1); // both inserts succeeded — multiple NULLs coexist (PG16 + MariaDB)
        } finally {
            $this->db->rollBack(); // non-destructive
        }
    }

    public function testOversightScopeGroupIdLength(): void {
        $schema = $this->db->createSchema();
        $table = $schema->getTable($this->prefix . 'learning_oversight');
        $col = $table->getColumn('scope_group_id');
        $this->assertSame(64, $col->getLength(), 'scope_group_id must be VARCHAR(64) to match NC oc_groups.gid');
    }
}
