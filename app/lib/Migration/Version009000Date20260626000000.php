<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use OCP\DB\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Phase 154 — Pass-Definition: add cert configuration columns to learning_courses.
 *
 * cert_enabled       — instructor enables/disables certification per course (PASS-01)
 * cert_pass_percent  — minimum exam score % to pass (PASS-02); default 80
 * cert_required_pool_ids — JSON array of pool IDs that must be mastered (PASS-03); null = none
 * cert_validity_days — certificate validity in days; 0 = no expiry (PASS-04)
 *                      NOTE: expiry evaluation is Phase 155 only — stored here, not checked here.
 *
 * Cross-DB: PostgreSQL 16 (relay) + MariaDB 11.4 utf8mb4.
 * Types::BOOLEAN → BOOLEAN on PG, TINYINT(1) on MariaDB (NC handles this).
 * Types::TEXT    → TEXT on both (no length limit; avoids VARCHAR(N) pitfalls on MariaDB).
 * No new indexes — cert columns are accessed by course_id (existing PK) only.
 */
class Version009000Date20260626000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('learning_courses')) {
            return null;
        }

        $table = $schema->getTable('learning_courses');
        $changed = false;

        if (!$table->hasColumn('cert_enabled')) {
            $table->addColumn('cert_enabled', Types::BOOLEAN, [
                'notnull' => false,
                'default' => false,
            ]);
            $changed = true;
        }

        if (!$table->hasColumn('cert_pass_percent')) {
            $table->addColumn('cert_pass_percent', Types::SMALLINT, [
                'notnull' => false,
                'default' => 80,
            ]);
            $changed = true;
        }

        if (!$table->hasColumn('cert_required_pool_ids')) {
            $table->addColumn('cert_required_pool_ids', Types::TEXT, [
                'notnull' => false,
                'default' => null,
            ]);
            $changed = true;
        }

        if (!$table->hasColumn('cert_validity_days')) {
            $table->addColumn('cert_validity_days', Types::INTEGER, [
                'notnull' => false,
                'default' => 0,
            ]);
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
