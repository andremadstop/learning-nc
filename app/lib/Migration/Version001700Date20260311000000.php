<?php
declare(strict_types=1);
namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001700Date20260311000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('learning_sessions')) {
            return $schema;
        }

        $table = $schema->getTable('learning_sessions');

        if (!$table->hasColumn('attempt_no')) {
            $table->addColumn('attempt_no', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
        }

        if (!$table->hasIndex('learn_s_exam_attempt_idx')) {
            $table->addIndex(['user_id', 'pool_id', 'mode', 'started_at'], 'learn_s_exam_attempt_idx');
        }

        return $schema;
    }
}
