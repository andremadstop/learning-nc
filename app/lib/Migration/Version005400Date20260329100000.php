<?php
declare(strict_types=1);
namespace OCA\Learning\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Phase 104: course_snapshots table for permanent course-end summaries.
 */
class Version005400Date20260329100000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('learning_course_snapshots')) {
            $table = $schema->createTable('learning_course_snapshots');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('course_id', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('snapshot_data', Types::TEXT, ['notnull' => true]);
            $table->addColumn('created_at', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id', 'course_id'], 'lnc_snap_user_course');
        }

        return $schema;
    }
}
