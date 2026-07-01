<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Phase 160 Track A — Audit Hash-Chain Schema (AUDIT-01).
 *
 * Changes:
 * 1. ALTER learning_audit_events — add 4 nullable chain columns:
 *    - seq_num    BIGINT     NULL  (position in compliance chain)
 *    - user_ref   VARCHAR(64) NULL  (hash_hmac(sha256, user_id, pepper) — never raw uid; DSGVO-01)
 *    - prev_hash  VARCHAR(64) NULL  (chain_hash of predecessor event)
 *    - chain_hash VARCHAR(64) NULL  (sha256(ksorted_canonical | prev_hash))
 *    - index learn_audit_chain_idx on (chain_hash) — 21 chars, safely under MariaDB 27-char limit
 *
 * 2. CREATE learning_audit_chain_state — single-row CAS table:
 *    - id        BIGINT PK autoincrement
 *    - last_seq  BIGINT NOT NULL default 0
 *    - last_hash VARCHAR(64) NOT NULL
 *    No additional indexes — single-row lookup via PK only.
 *
 * 3. postSchemaChange(): seed genesis row (last_seq=0, last_hash=000...0) if table is empty.
 *    Idempotent: second occ upgrade is a no-op.
 *
 * Design constraints:
 * - No Types::JSONB — existing context_json is TEXT; chain columns are STRING (VARCHAR).
 * - No oc_ prefix — NC auto-prepends the table prefix.
 * - All column guards use hasColumn/hasIndex/hasTable — safe for repeated occ upgrade.
 * - forUpdate() is NOT used — NC IQueryBuilder has no forUpdate(); CAS UPDATE in
 *   logComplianceEvent() serializes concurrent writes via WHERE last_seq = :expected.
 * - IDBConnection $connection injected via constructor for postSchemaChange DB access.
 * - PG16 + MariaDB 11.4 safe: all types from OCP\DB\Types constants.
 */
class Version009300Date20260701000000 extends SimpleMigrationStep {
    public function __construct(private IDBConnection $connection) {}

    /**
     * @param Closure(): ISchemaWrapper $schemaClosure
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        // 1. ALTER learning_audit_events — add chain columns + index
        if ($schema->hasTable('learning_audit_events')) {
            $table = $schema->getTable('learning_audit_events');

            if (!$table->hasColumn('seq_num')) {
                // Position in the compliance chain (chain_state counter, NOT the DB autoincrement id)
                $table->addColumn('seq_num', Types::BIGINT, ['notnull' => false]);
                $changed = true;
            }

            if (!$table->hasColumn('user_ref')) {
                // hash_hmac(sha256, user_id, pepper) — pseudonymous; raw user_id stored separately.
                // Art.17: null the user_id column without touching the canonical hash input.
                $table->addColumn('user_ref', Types::STRING, ['notnull' => false, 'length' => 64]);
                $changed = true;
            }

            if (!$table->hasColumn('prev_hash')) {
                // chain_hash of predecessor event; genesis uses str_repeat('0', 64).
                $table->addColumn('prev_hash', Types::STRING, ['notnull' => false, 'length' => 64]);
                $changed = true;
            }

            if (!$table->hasColumn('chain_hash')) {
                // sha256(ksorted_canonical_json . '|' . prev_hash)
                $table->addColumn('chain_hash', Types::STRING, ['notnull' => false, 'length' => 64]);
                $changed = true;
            }

            if (!$table->hasIndex('learn_audit_chain_idx')) {
                // 21 chars — safely under MariaDB 27-char index-name limit.
                $table->addIndex(['chain_hash'], 'learn_audit_chain_idx');
                $changed = true;
            }
        }

        // 2. CREATE learning_audit_chain_state (single-row CAS table)
        if (!$schema->hasTable('learning_audit_chain_state')) {
            $t = $schema->createTable('learning_audit_chain_state');
            $t->addColumn('id',        Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('last_seq',  Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $t->addColumn('last_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
            $t->setPrimaryKey(['id']);
            // No additional indexes — single-row table, PK lookup only.
            $changed = true;
        }

        return $changed ? $schema : null;
    }

    /**
     * Seed genesis chain state row if the table is empty.
     * Called after changeSchema() so the table is guaranteed to exist.
     * Idempotent: count check prevents double-insert on repeated occ upgrade.
     *
     * @param Closure(): ISchemaWrapper $schemaClosure
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $qb = $this->connection->getQueryBuilder();
        $count = (int)$qb->select($qb->func()->count('id'))
            ->from('learning_audit_chain_state')
            ->executeQuery()
            ->fetchOne();

        if ($count === 0) {
            $qb2 = $this->connection->getQueryBuilder();
            $qb2->insert('learning_audit_chain_state')
                ->values([
                    'last_seq'  => $qb2->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                    'last_hash' => $qb2->createNamedParameter(str_repeat('0', 64)),
                ]);
            $qb2->executeStatement();
        }
    }
}
