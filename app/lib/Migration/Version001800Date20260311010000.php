<?php
declare(strict_types=1);
namespace OCA\Learning\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001800Date20260311010000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('learning_sessions')) {
            return $schema;
        }

        $table = $schema->getTable('learning_sessions');

        if (!$table->hasColumn('question_order_json')) {
            $table->addColumn('question_order_json', Types::TEXT, ['notnull' => false]);
        }

        return $schema;
    }
}
