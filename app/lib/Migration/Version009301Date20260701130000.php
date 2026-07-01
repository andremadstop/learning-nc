<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Phase 160 security hardening (FIX-7) — enforce a UNIQUE seq_num on the compliance chain.
 *
 * Adds a UNIQUE index on learning_audit_events.seq_num. seq_num is nullable: non-compliance
 * events written by logEvent() leave it NULL, and BOTH PG16 and MariaDB 11.4 treat NULLs as
 * distinct in a UNIQUE index (multiple NULLs allowed), so those rows are unaffected. For
 * compliance rows (seq_num IS NOT NULL) the index makes a duplicate sequence number impossible
 * at the DB level — a defence-in-depth backstop for the CAS serialization in
 * AuditService::logComplianceEvent(). A lost-race INSERT now fails with a unique-constraint
 * violation, which logComplianceEvent catches and retries (graceful), instead of forking the chain.
 *
 * Index name learn_audit_seq_uq — 17 chars, safely under MariaDB's 27-char index-name limit.
 * Idempotent: hasTable/hasIndex guards make a repeated occ upgrade a no-op.
 * PG16 + MariaDB 11.4 safe.
 */
class Version009301Date20260701130000 extends SimpleMigrationStep {

    /**
     * @param Closure(): ISchemaWrapper $schemaClosure
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('learning_audit_events')) {
            return null;
        }

        $table = $schema->getTable('learning_audit_events');

        if ($table->hasColumn('seq_num') && !$table->hasIndex('learn_audit_seq_uq')) {
            // UNIQUE on a nullable column: multiple NULLs allowed (PG16 + MariaDB), unique on non-NULL.
            $table->addUniqueIndex(['seq_num'], 'learn_audit_seq_uq');
            return $schema;
        }

        return null;
    }
}
