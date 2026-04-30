<?php
declare(strict_types=1);
namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Issue #9: Sync DB table names with what the Mapper classes have been
 * referring to since v3.5.0 (commit 7016ddd, 2026-03-29).
 *
 * That commit renamed the table identifiers in the Mapper classes:
 *   learning_q_translations -> learning_qst_translations
 *   learning_a_translations -> learning_ans_translations
 * but never shipped a migration to rename the tables themselves. As a
 * result, every fresh install since v3.5.0 ended up with the original
 * names from Version000350 in the database, while the code looked for
 * the renamed identifiers — producing SQLSTATE[42S02] / 1146 "table not
 * found" once any Mapper / raw query touched the translations.
 *
 * This migration is idempotent: it only renames when the old name still
 * exists and the new name does not yet. Installs that were patched
 * manually (old absent, new present) and fresh installs running this
 * migration first time are both correctly handled.
 */
class Version007900Date20260430000000 extends SimpleMigrationStep {

    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $prefix = method_exists($this->db, 'getPrefix') ? $this->db->getPrefix() : 'oc_';

        if ($schema->hasTable('learning_q_translations') && !$schema->hasTable('learning_qst_translations')) {
            $this->db->executeStatement(
                "ALTER TABLE {$prefix}learning_q_translations RENAME TO {$prefix}learning_qst_translations"
            );
            $output->info('Renamed learning_q_translations -> learning_qst_translations (issue #9)');
        }

        if ($schema->hasTable('learning_a_translations') && !$schema->hasTable('learning_ans_translations')) {
            $this->db->executeStatement(
                "ALTER TABLE {$prefix}learning_a_translations RENAME TO {$prefix}learning_ans_translations"
            );
            $output->info('Renamed learning_a_translations -> learning_ans_translations (issue #9)');
        }
    }
}
