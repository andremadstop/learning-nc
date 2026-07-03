<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Phase 164-07 post-impl-review BLOCKER fix (DSGVO-03 crypto-erasure).
 *
 * RetentionService::anonymizeExpired() writes user_id = NULL (learning_certificates) and
 * subject_id = NULL (learning_assignments) as the pseudonymization step — but both columns
 * were created NOT NULL (Version009100 / Version009400). On PostgreSQL the erasure UPDATE
 * would throw a NOT NULL violation: cert rows would retry forever and the assignment scrub
 * would abort the daily RetentionJob. Caught by the grumpy-Codex post-impl review; the
 * mock-based unit tests could not see the live constraint.
 *
 * NULL semantics after this migration:
 *   learning_certificates.user_id  NULL ⟺ anonymized_at IS NOT NULL (erased tombstone)
 *   learning_assignments.subject_id NULL ⟺ aged CLOSED period scrubbed by retention
 * Live (non-erased) rows are always written with non-null values by the application.
 */
class Version009700Date20260703000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        if ($schema->hasTable('learning_certificates')) {
            $table = $schema->getTable('learning_certificates');
            if ($table->hasColumn('user_id') && $table->getColumn('user_id')->getNotnull()) {
                $table->getColumn('user_id')->setNotnull(false);
                $changed = true;
            }
        }

        if ($schema->hasTable('learning_assignments')) {
            $table = $schema->getTable('learning_assignments');
            if ($table->hasColumn('subject_id') && $table->getColumn('subject_id')->getNotnull()) {
                $table->getColumn('subject_id')->setNotnull(false);
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }
}
