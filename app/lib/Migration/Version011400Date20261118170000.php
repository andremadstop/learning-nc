<?php
declare(strict_types=1);

namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * GitHub #90: stale entries leave the learning materials, their evidence stays.
 *
 * learning_course_documents.removed_at — when "Scan folder" or the instructor's × took the entry
 *   out of the list because its file is gone or no longer in the linked material folder. The row
 *   itself stays: read confirmations (learning_doc_confirms), "Ask everyone to confirm again" and
 *   the required-content mark belong to it, so they count again when the same file comes back
 *   and a scan clears the column. NULL = listed, as before 5.16.0.
 *
 * A nullable column without data to fill: changeSchema() alone covers fresh installs and
 * upgrades. No index: the column is only read together with course_id, which has one.
 */
class Version011400Date20261118170000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('learning_course_documents')) {
            $table = $schema->getTable('learning_course_documents');
            if (!$table->hasColumn('removed_at')) {
                $table->addColumn('removed_at', Types::BIGINT, ['notnull' => false, 'default' => null]);
                return $schema;
            }
        }

        return null;
    }
}
