<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Phase 160 Track B — Assignment/Oversight schema.
 *
 * Creates TWO new tables:
 *
 * learning_assignments  — polymorphic subject assignment (user or group) to a course.
 *   active_period_key: nullable UNIQUE — "courseId:subjectType:subjectId" when period is active,
 *   NULL when period is closed (re-cert history). Multiple NULLs allowed on PG16 + MariaDB (ANSI SQL).
 *   Composite (course_id, subject_type, subject_id): PLAIN addIndex — NOT unique. Re-cert creates a
 *   second row for the same (course, subject) without constraint violation.
 *
 * learning_oversight  — team-lead scope assignments (replaces ARCHITECTURE.md's learning_team_leads).
 *   scope_group_id is VARCHAR(64) — matches NC oc_groups.gid length. NOT VARCHAR(255).
 *
 * Index name constraints: all names ≤ 27 chars (MariaDB InnoDB limit).
 * No oc_ prefix in table names — NC auto-prepends the prefix.
 * All columns use Types::* constants — NC resolves per platform (PG16 + MariaDB 11.4).
 * No constructor — Version009400 has no postSchemaChange data seeding.
 */
class Version009400Date20260701120000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        if (!$schema->hasTable('learning_assignments')) {
            $table = $schema->createTable('learning_assignments');
            $table->addColumn('id',                   Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('course_id',            Types::BIGINT, ['notnull' => true]);
            $table->addColumn('subject_type',         Types::STRING, ['notnull' => true, 'length' => 16]);
            $table->addColumn('subject_id',           Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('assigned_by',          Types::STRING, ['notnull' => false, 'length' => 64]);
            $table->addColumn('assigned_at',          Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('due_date',             Types::BIGINT, ['notnull' => false]);
            $table->addColumn('recert_interval_days', Types::BIGINT, ['notnull' => false]);
            $table->addColumn('status',               Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'assigned']);
            $table->addColumn('active_period_key',    Types::STRING, ['notnull' => false, 'length' => 128, 'default' => null]);
            $table->setPrimaryKey(['id']);
            // PLAIN index — NOT unique. Multiple period rows per same subject+course allowed (re-cert history)
            $table->addIndex(['course_id', 'subject_type', 'subject_id'], 'learn_asn_crs_subj_idx'); // 22 chars OK
            $table->addIndex(['subject_type', 'subject_id'],              'learn_asn_subj_idx');      // 19 chars OK
            $table->addIndex(['course_id'],                               'learn_asn_course_idx');    // 20 chars OK
            // UNIQUE on active_period_key — enforces "at most one active period" (same pattern as active_idem_key)
            $table->addUniqueIndex(['active_period_key'],                 'learn_asn_period_uq');     // 19 chars OK
            $table->addIndex(['due_date'],                                'learn_asn_due_idx');       // 18 chars OK
            $changed = true;
        }

        if (!$schema->hasTable('learning_oversight')) {
            $table = $schema->createTable('learning_oversight');
            $table->addColumn('id',             Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('course_id',      Types::BIGINT, ['notnull' => true]);
            $table->addColumn('lead_user_id',   Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('scope_group_id', Types::STRING, ['notnull' => true, 'length' => 64]); // matches NC oc_groups.gid
            $table->addColumn('created_at',     Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['course_id', 'lead_user_id', 'scope_group_id'], 'learn_ov_crs_usr_grp_uq'); // 24 chars OK
            $table->addIndex(['course_id'],    'learn_ov_course_idx'); // 20 chars OK
            $table->addIndex(['lead_user_id'], 'learn_ov_lead_idx');   // 19 chars OK
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
