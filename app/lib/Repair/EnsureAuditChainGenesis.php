<?php
declare(strict_types=1);

namespace OCA\Learning\Repair;

use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Seed the genesis row of the compliance audit chain where it is missing.
 *
 * Version009300 seeds learning_audit_chain_state in postSchemaChange(). A FRESH install runs
 * only changeSchema() (migrate('latest', true)) and then records every migration as executed,
 * so on every fresh install since 5.2 the table stayed empty. AuditService::logComplianceEvent()
 * is fail-closed on a missing row, which made "course passed", certificate issue/revoke and
 * mandatory-training completion fail with HTTP 500 on those installs.
 *
 * Registered under <install> (fresh installs) and <post-migration> (installs that were
 * fresh-installed on an affected version and are now upgraded).
 *
 * The row is only seeded where the chain has never started. Any trace of an earlier chain
 * (chain columns on an event, a signed checkpoint, recorded checkpoint progress) means its head
 * was lost; a new genesis would restart the chain at seq 1 and hide that. Then this step only
 * warns, and AuditService stays fail-closed until an admin has looked at it.
 */
class EnsureAuditChainGenesis implements IRepairStep {
    /** AuditService pins the chain head to this row. */
    private const STATE_ID = 1;

    /** Columns that only compliance (chained) events fill; plain audit rows leave them NULL. */
    private const CHAIN_COLUMNS = ['seq_num', 'user_ref', 'prev_hash', 'chain_hash'];

    public function __construct(
        private IDBConnection $db,
        private IConfig $config,
    ) {
    }

    public function getName(): string {
        return 'Ensure the compliance audit chain has its genesis row';
    }

    public function run(IOutput $output): void {
        if ($this->stateRowExists()) {
            return;
        }

        $evidence = $this->earlierChainEvidence();
        if ($evidence !== null) {
            $output->warning(
                "The compliance audit chain has no head row (learning_audit_chain_state id=1), but $evidence. "
                . 'Not starting a new chain, which would hide the loss. Compliance events stay blocked; '
                . 'check with `occ learning:audit:verify`.'
            );
            return;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->insert('learning_audit_chain_state')
            ->values([
                // Explicit id: AuditService reads exactly this row. On PostgreSQL the id sequence is
                // not advanced by this; nothing inserts into this table without an id once a row
                // exists (Version009300 seeds only an empty table), so no later insert can collide.
                'id'        => $qb->createNamedParameter(self::STATE_ID, IQueryBuilder::PARAM_INT),
                'last_seq'  => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                'last_hash' => $qb->createNamedParameter(str_repeat('0', 64)),
            ]);
        try {
            $qb->executeStatement();
        } catch (DbException $e) {
            // A concurrent run seeded it between our check and insert: the primary key stopped
            // the second genesis, and the row that won is the chain head. Anything else is real.
            if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION || !$this->stateRowExists()) {
                throw $e;
            }
            return;
        }
        $output->info('Seeded the genesis row of the compliance audit chain');
    }

    /** @phpstan-impure reads the database; a concurrent run can change the answer between calls */
    private function stateRowExists(): bool {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('id')
            ->from('learning_audit_chain_state')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter(self::STATE_ID, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row !== false;
    }

    /** A description of what shows that a chain existed before, or null for a chain never started. */
    private function earlierChainEvidence(): ?string {
        $stateRows = $this->count('learning_audit_chain_state', null);
        if ($stateRows > 0) {
            return "learning_audit_chain_state holds $stateRows row(s) with another id";
        }

        $qb = $this->db->getQueryBuilder();
        $chained = $this->count('learning_audit_events', $qb->expr()->orX(
            ...array_map(fn (string $col) => $qb->expr()->isNotNull($col), self::CHAIN_COLUMNS)
        ), $qb);
        if ($chained > 0) {
            return "$chained audit event(s) carry chain data";
        }

        $checkpoints = $this->count('learning_audit_checkpoints', null);
        if ($checkpoints > 0) {
            return "$checkpoints signed checkpoint(s) exist";
        }

        if ($this->config->getAppValue('learning', 'last_checkpoint_to_event_id', '') !== '') {
            return 'checkpoint progress is recorded (last_checkpoint_to_event_id)';
        }

        return null;
    }

    /** Count rows of $table, optionally filtered by $where (built on $qb). */
    private function count(string $table, mixed $where, ?IQueryBuilder $qb = null): int {
        $qb ??= $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'n'))->from($table);
        if ($where !== null) {
            $qb->where($where);
        }
        $result = $qb->executeQuery();
        $n = (int)$result->fetchOne();
        $result->closeCursor();
        return $n;
    }
}
