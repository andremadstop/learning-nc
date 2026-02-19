<?php
declare(strict_types=1);
namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001000Date20260219100000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Fix: answer_id must be nullable for multi-select questions
        // (multi-select stores answer_ids as JSON in separate column, answer_id is NULL)
        if ($schema->hasTable('learning_user_answers')) {
            $table = $schema->getTable('learning_user_answers');
            if ($table->hasColumn('answer_id')) {
                $column = $table->getColumn('answer_id');
                $column->setNotnull(false);
            }
        }

        return $schema;
    }
}
