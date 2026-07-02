<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Phase 164 — Re-Zertifizierung + Retention schema foundation (RECERT-02/06, DSGVO-03).
 *
 * Three schema changes:
 *
 * learning_courses.cert_validity_months (RECERT-02) — configurable validity in months per
 *   course. NULL = inherit / no-expiry; code defaults to 12 when null. BIGINT to match
 *   the existing cert_validity_days column type.
 *
 * learning_certificates.anonymized_at (DSGVO-03) — retention tombstone. NULL = live record;
 *   non-NULL unix timestamp = crypto-erased (credential_json scrubbed). Consumed by
 *   CertificateVerifyService (164-06 anonymized branch) + RetentionJob (164-07).
 *
 * learning_recert_reminders (RECERT-06) — idempotency table for T-30/T-7 reminder emails.
 *   UNIQUE(cert_id, threshold_days) = exactly one row per (cert, threshold) → storm-proof
 *   across job re-runs AND notification dismissals (CAS: catch REASON_UNIQUE_CONSTRAINT_VIOLATION
 *   = "already sent"). See Pattern 3 in 164-RESEARCH.md.
 *
 * Index name constraints: all names ≤ 27 chars (MariaDB InnoDB limit).
 * No oc_ prefix — NC auto-prepends the prefix.
 * All columns use Types::* constants — NC resolves per platform (PG16 + MariaDB 11.4).
 */
class Version009600Date20260702000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        // 1. learning_courses.cert_validity_months — per-course validity in months (RECERT-02)
        //    NULL = no-expiry / inherit global default; code supplies default 12 at runtime.
        if ($schema->hasTable('learning_courses')) {
            $table = $schema->getTable('learning_courses');
            if (!$table->hasColumn('cert_validity_months')) {
                $table->addColumn('cert_validity_months', Types::BIGINT, [
                    'notnull' => false,
                ]);
                $changed = true;
            }
        }

        // 2. learning_certificates.anonymized_at — DSGVO-03 retention tombstone (DSGVO-03)
        //    NULL = live record; set = crypto-erased (credential_json scrubbed by RetentionJob).
        if ($schema->hasTable('learning_certificates')) {
            $table = $schema->getTable('learning_certificates');
            if (!$table->hasColumn('anonymized_at')) {
                $table->addColumn('anonymized_at', Types::BIGINT, [
                    'notnull' => false,
                ]);
                $changed = true;
            }
        }

        // 3. learning_recert_reminders — reminder idempotency table (RECERT-06)
        //    UNIQUE(cert_id, threshold_days) = one row per (cert, T-30|T-7 threshold).
        if (!$schema->hasTable('learning_recert_reminders')) {
            $table = $schema->createTable('learning_recert_reminders');
            $table->addColumn('id',             Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('cert_id',        Types::BIGINT, ['notnull' => true]); // -> learning_certificates.id
            $table->addColumn('threshold_days', Types::BIGINT, ['notnull' => true]); // 30 | 7
            $table->addColumn('sent_at',        Types::BIGINT, ['notnull' => true]); // unix seconds
            $table->setPrimaryKey(['id']);
            // UNIQUE(cert_id, threshold_days) — idempotency key (17 chars ✓)
            $table->addUniqueIndex(['cert_id', 'threshold_days'], 'learn_rcrt_rem_uq');
            // Plain index on cert_id for reminder-lookup queries (23 chars ✓)
            $table->addIndex(['cert_id'], 'learn_rcrt_rem_cert_idx');
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
